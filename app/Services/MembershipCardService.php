<?php

namespace App\Services;

use App\Models\EcommercePaymentMethod;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cards for memberships: collect one at signup (also when nothing is due today, e.g. a free
 * trial), keep it on file, and charge it for the first payment and every renewal.
 *
 * Two modes:
 *  - "stripe": real Stripe keys are set. The browser confirms a SetupIntent with Stripe.js and
 *    sends back a pm_… id; card numbers never reach this server.
 *  - "test":  local/testing with no Stripe key. Only the 4242 test card is accepted and no
 *    money moves.
 */
class MembershipCardService
{
    public const MODE_STRIPE = 'stripe';
    public const MODE_TEST = 'test';
    public const MODE_UNAVAILABLE = 'unavailable';

    private const TEST_CARDS = ['pm_card_visa', 'tok_visa', 'test_card_4242424242424242', '4242424242424242'];

    public function mode(): string
    {
        if (filled($this->secret())) {
            return self::MODE_STRIPE;
        }

        return app()->environment(['local', 'testing']) ? self::MODE_TEST : self::MODE_UNAVAILABLE;
    }

    /** Stripe keys: the active Stripe gateway from the admin panel, else the server config. */
    /** Same keys as checkout: see StripeKeys for how they are chosen. */
    public function secret(): string
    {
        return StripeKeys::secret();
    }

    public function publishableKey(): string
    {
        return StripeKeys::publishable();
    }

    /**
     * What the browser needs to show a card form.
     *
     * @return array{mode: string, publishable_key: ?string, client_secret: ?string, saved_card: ?array}
     */
    public function setup(User $user): array
    {
        $mode = $this->mode();
        $out = [
            'mode' => $mode,
            'publishable_key' => null,
            'client_secret' => null,
            'saved_card' => $this->cardPayload($this->defaultCard($user)),
            // Why card entry is off, for staff (no key material): see `php artisan stripe:check`.
            'reason' => null,
        ];

        if ($mode === self::MODE_UNAVAILABLE) {
            $out['reason'] = 'no_usable_stripe_keys';
            Log::warning('Card entry unavailable: no active, real Stripe keys found (Payment Gateways or .env). Run `php artisan stripe:check`.');

            return $out;
        }

        if ($mode === self::MODE_STRIPE && $this->publishableKey() === '') {
            // A secret key alone cannot show Stripe's card field in the browser.
            $out['mode'] = self::MODE_UNAVAILABLE;
            $out['reason'] = 'publishable_key_missing';
            Log::warning('Card entry unavailable: the Stripe secret key has no matching publishable (pk_) key. Run `php artisan stripe:check`.');

            return $out;
        }

        if ($mode === self::MODE_STRIPE) {
            $intent = $this->stripe('post', '/setup_intents', [
                'customer' => $this->customerId($user),
                'usage' => 'off_session',
                'payment_method_types[0]' => 'card',
                'metadata[purpose]' => 'membership_card',
                'metadata[user_id]' => (string) $user->id,
            ]);
            if (! $intent->successful()) {
                $this->setupFailed('SetupIntent', $intent);
            }
            $out['publishable_key'] = $this->publishableKey();
            $out['client_secret'] = (string) $intent->json('client_secret');
        }

        return $out;
    }

    /**
     * The card a purchase will use: a card already on file, or the new one from the form.
     * A new card is saved to the customer's payment methods and made the default.
     *
     * @return array{mode: string, customer: ?string, payment_method: string, brand: string, last4: string, exp_month: ?int, exp_year: ?int, saved_method_id: ?int}
     */
    public function resolveForPurchase(?User $user, array $input): array
    {
        $mode = $this->mode();
        if ($mode === self::MODE_UNAVAILABLE) {
            throw ValidationException::withMessages(['stripe' => ['Card payments are not set up yet. Please choose another payment method.']]);
        }

        $savedId = $input['saved_payment_method_id'] ?? null;
        if ($user && filled($savedId)) {
            $saved = $user->paymentMethods()->whereKey((int) $savedId)->first();
            if (! $saved || ! $this->chargeable($saved, $mode)) {
                throw ValidationException::withMessages(['saved_payment_method_id' => ['This saved card cannot be charged. Please enter the card again.']]);
            }

            return $this->describe($saved, $mode);
        }

        $token = trim((string) ($input['stripe_payment_method'] ?? $input['payment_method_id'] ?? $input['stripe_test_token'] ?? $input['payment_token'] ?? ''));
        if ($token === '') {
            throw ValidationException::withMessages(['stripe_payment_method' => ['A card is required to start this membership.']]);
        }

        if ($mode === self::MODE_TEST) {
            if (! in_array($token, self::TEST_CARDS, true)) {
                throw ValidationException::withMessages([
                    'stripe_payment_method' => ['Local Stripe test mode only accepts the 4242 4242 4242 4242 test card, tok_visa, or pm_card_visa.'],
                ]);
            }
            $card = [
                'provider' => 'stripe_test',
                'provider_customer_id' => $user ? 'cus_test_' . $user->id : null,
                'provider_payment_method_id' => 'pm_card_visa',
                'brand' => 'Visa',
                'last4' => '4242',
                'exp_month' => $this->intOrNull($input['card_exp_month'] ?? null),
                'exp_year' => $this->intOrNull($input['card_exp_year'] ?? null),
                // Stripe gives the same fingerprint to the same card on any account; test mode imitates it.
                // Every accepted test token is the same Visa 4242 card, so they share one fingerprint.
                'fingerprint' => 'test_visa_4242',
            ];
        } else {
            $card = $this->stripeCard($user, $token);
        }

        $saved = $user ? $this->remember($user, $card, (string) ($input['cardholder_name'] ?? '')) : null;

        return [
            'mode' => $mode,
            'customer' => $card['provider_customer_id'],
            'payment_method' => $card['provider_payment_method_id'],
            'brand' => $card['brand'],
            'last4' => $card['last4'],
            'exp_month' => $card['exp_month'],
            'exp_year' => $card['exp_year'],
            'fingerprint' => $card['fingerprint'] ?? null,
            'saved_method_id' => $saved?->id,
        ];
    }

    /**
     * The card to charge for a renewal: the member's current default card, else the one used at signup.
     *
     * @param array $signupCard `payment_card` from the membership's metadata
     */
    public function resolveForRenewal(?User $user, array $signupCard): ?array
    {
        $mode = $this->mode();
        $default = $user ? $this->defaultCard($user) : null;
        if ($default && $this->chargeable($default, $mode)) {
            return $this->describe($default, $mode);
        }

        if (filled($signupCard['payment_method'] ?? null) && ($signupCard['mode'] ?? $mode) === $mode) {
            return [
                'mode' => $mode,
                'customer' => $signupCard['customer'] ?? null,
                'payment_method' => (string) $signupCard['payment_method'],
                'brand' => (string) ($signupCard['brand'] ?? 'Card'),
                'last4' => (string) ($signupCard['last4'] ?? ''),
                'exp_month' => $this->intOrNull($signupCard['exp_month'] ?? null),
                'exp_year' => $this->intOrNull($signupCard['exp_year'] ?? null),
                'saved_method_id' => null,
            ];
        }

        return null;
    }

    /**
     * Take a payment from a card on file.
     *
     * @param string $idempotencyKey the same key never charges twice (retries, repeated job runs)
     * @return array{paid: bool, reference: ?string, reason: ?string, processor: string, details: array}
     */
    public function charge(array $card, float $amount, string $currency, string $description, array $metadata, string $idempotencyKey): array
    {
        $amount = round($amount, 2);
        $details = ['amount' => $amount, 'card' => $this->publicCard($card)];

        if ($card['mode'] === self::MODE_TEST) {
            return [
                'paid' => true,
                'reference' => 'test_stripe_' . substr(sha1($idempotencyKey), 0, 16),
                'reason' => null,
                'processor' => 'stripe_test',
                'details' => $details + ['test_mode' => true],
            ];
        }

        $payload = [
            'amount' => (int) round($amount * 100),
            'currency' => strtolower($currency ?: 'USD'),
            'customer' => $card['customer'],
            'payment_method' => $card['payment_method'],
            'payment_method_types[0]' => 'card',
            'confirm' => 'true',
            // The card was verified when it was saved; the customer is not asked again.
            'off_session' => 'true',
            'description' => $description,
        ];
        foreach ($metadata as $key => $value) {
            $payload["metadata[{$key}]"] = (string) $value;
        }

        $response = $this->stripe('post', '/payment_intents', $payload, $idempotencyKey);
        $paid = $response->successful() && $response->json('status') === 'succeeded';
        $secret = $this->secret();

        return [
            'paid' => $paid,
            'reference' => $response->json('id') ?: $response->json('error.payment_intent.id'),
            'reason' => $paid ? null : ($response->json('error.message') ?: 'The card payment did not go through.'),
            'processor' => 'stripe',
            'details' => $details + [
                'stripe_payment_intent' => $response->json('id'),
                'stripe_status' => $response->json('status'),
                'decline_code' => $response->json('error.decline_code'),
                'test_mode' => str_starts_with($secret, 'sk_test_'),
            ],
        ];
    }

    /**
     * Give back a card payment, e.g. when the plan change it paid for could not be saved.
     * Test-mode payments moved no money, so there is nothing to return.
     *
     * @param string $reference the payment reference returned by charge() (a Stripe pi_… id)
     */
    public function refund(string $reference, float $amount, string $reason = 'requested_by_customer'): bool
    {
        if ($reference === '' || str_starts_with($reference, 'test_') || str_starts_with($reference, 'stripe_zero_due')) {
            return true;
        }
        if (! str_starts_with($reference, 'pi_') || $this->mode() !== self::MODE_STRIPE) {
            return false;
        }

        $response = $this->stripe('post', '/refunds', [
            'payment_intent' => $reference,
            'amount' => (int) round($amount * 100),
            'reason' => $reason,
            'metadata[purpose]' => 'membership_change_reversal',
        ], 'refund_' . $reference);

        return $response->successful() && in_array($response->json('status'), ['succeeded', 'pending'], true);
    }

    /**
     * Save a card from the customer's Payment Methods page (card confirmed with Stripe, or the
     * 4242 card in local test mode). Same checks as a membership signup: the card must belong to
     * this customer. Unlike signup, it only becomes the default card when asked to (or is the first).
     */
    public function saveFromProfile(User $user, array $input, bool $makeDefault, ?int $billingAddressId = null): EcommercePaymentMethod
    {
        $previousDefault = $user->paymentMethods()->where('is_default', true)->value('id');

        $card = $this->resolveForPurchase($user, array_diff_key($input, ['saved_payment_method_id' => true]));
        /** @var EcommercePaymentMethod $saved */
        $saved = $user->paymentMethods()->findOrFail($card['saved_method_id']);

        if (! $makeDefault && $previousDefault && (int) $previousDefault !== (int) $saved->id) {
            // resolveForPurchase made the new card the default: put the previous default back.
            $saved->update(['is_default' => false]);
            $user->paymentMethods()->whereKey($previousDefault)->update(['is_default' => true]);
        }
        if ($billingAddressId) {
            $saved->update(['billing_address_id' => $billingAddressId]);
        }

        return $saved->fresh();
    }

    /** The customer's saved card that can be charged now (saved through Stripe), or null. */
    public function chargeableCardId(User $user): ?int
    {
        $card = $this->defaultCard($user);

        return $card && $this->chargeable($card, $this->mode()) ? (int) $card->id : null;
    }

    /** Whether a saved card can be charged (cards typed into the profile without Stripe cannot). */
    public function isChargeable(EcommercePaymentMethod $method): bool
    {
        return $this->chargeable($method, $this->mode());
    }

    /** Card details that are safe to store on a membership and show to staff. */
    public function publicCard(array $card): array
    {
        return [
            'mode' => $card['mode'],
            'customer' => $card['customer'],
            'payment_method' => $card['payment_method'],
            'brand' => $card['brand'],
            'last4' => $card['last4'],
            'exp_month' => $card['exp_month'],
            'exp_year' => $card['exp_year'],
            'saved_method_id' => $card['saved_method_id'],
        ];
    }

    private function providerFor(string $mode): string
    {
        return $mode === self::MODE_TEST ? 'stripe_test' : 'stripe';
    }

    private function chargeable(EcommercePaymentMethod $method, string $mode): bool
    {
        return $method->provider === $this->providerFor($mode) && filled($method->provider_payment_method_id);
    }

    private function defaultCard(User $user): ?EcommercePaymentMethod
    {
        return $user->paymentMethods()
            ->where('provider', $this->providerFor($this->mode()))
            ->whereNotNull('provider_payment_method_id')
            ->orderByDesc('is_default')
            ->latest()
            ->first();
    }

    private function cardPayload(?EcommercePaymentMethod $method): ?array
    {
        return $method ? [
            'id' => $method->id,
            'brand' => $method->brand ?: 'Card',
            'last4' => $method->last4,
            'exp_month' => $method->exp_month,
            'exp_year' => $method->exp_year,
        ] : null;
    }

    private function describe(EcommercePaymentMethod $method, string $mode): array
    {
        return [
            'mode' => $mode,
            'customer' => $method->provider_customer_id,
            'payment_method' => (string) $method->provider_payment_method_id,
            'brand' => $method->brand ?: 'Card',
            'last4' => (string) $method->last4,
            'exp_month' => $method->exp_month,
            'exp_year' => $method->exp_year,
            'fingerprint' => $method->card_fingerprint,
            'saved_method_id' => $method->id,
        ];
    }

    /** Reads the card behind a pm_… id from Stripe and checks it belongs to this customer. */
    private function stripeCard(?User $user, string $paymentMethodId): array
    {
        $response = $this->stripe('get', '/payment_methods/' . rawurlencode($paymentMethodId));
        if (! $response->successful() || $response->json('type') !== 'card') {
            throw ValidationException::withMessages(['stripe_payment_method' => [$response->json('error.message') ?: 'That card could not be read. Please try again.']]);
        }

        $customer = (string) $response->json('customer');
        if ($customer === '') {
            throw ValidationException::withMessages(['stripe_payment_method' => ['That card was not saved for future payments. Please enter it again.']]);
        }

        if ($user) {
            $owner = $this->stripe('get', '/customers/' . rawurlencode($customer));
            if (! $owner->successful() || (string) $owner->json('metadata.user_id') !== (string) $user->id) {
                throw ValidationException::withMessages(['stripe_payment_method' => ['That card belongs to a different account.']]);
            }
        }

        return [
            'provider' => 'stripe',
            'provider_customer_id' => $customer,
            'provider_payment_method_id' => $paymentMethodId,
            'brand' => ucfirst((string) ($response->json('card.brand') ?: 'Card')),
            'last4' => (string) $response->json('card.last4'),
            'exp_month' => $this->intOrNull($response->json('card.exp_month')),
            'exp_year' => $this->intOrNull($response->json('card.exp_year')),
            'fingerprint' => $response->json('card.fingerprint') ?: null,
        ];
    }

    private function remember(User $user, array $card, string $holder): EcommercePaymentMethod
    {
        $user->paymentMethods()->where('is_default', true)->update(['is_default' => false]);

        return $user->paymentMethods()->updateOrCreate(
            ['provider' => $card['provider'], 'provider_payment_method_id' => $card['provider_payment_method_id']],
            [
                'provider_customer_id' => $card['provider_customer_id'],
                'brand' => $card['brand'],
                'last4' => $card['last4'],
                'exp_month' => $card['exp_month'],
                'exp_year' => $card['exp_year'],
                'cardholder_name' => $holder !== '' ? $holder : $user->name,
                'card_fingerprint' => $card['fingerprint'] ?? null,
                'is_default' => true,
            ]
        );
    }

    /** One Stripe customer per user: reuse the one on a saved card, else create it. */
    private function customerId(User $user): string
    {
        $known = $user->paymentMethods()->where('provider', 'stripe')->whereNotNull('provider_customer_id')->value('provider_customer_id');
        if ($known) {
            return (string) $known;
        }

        return (string) Cache::remember('membership_stripe_customer_' . $user->id, now()->addDays(7), function () use ($user) {
            $response = $this->stripe('post', '/customers', [
                'email' => $user->email,
                'name' => $user->name,
                'metadata[user_id]' => (string) $user->id,
            ]);
            if (! $response->successful() || ! $response->json('id')) {
                $this->setupFailed('Customer', $response);
            }

            return $response->json('id');
        });
    }

    /**
     * Stripe refused to set up card entry (bad or placeholder key, wrong account...). The customer
     * gets a plain message; the reason, with the key source, goes to the log for staff.
     */
    private function setupFailed(string $step, $response): never
    {
        $keys = StripeKeys::resolve();
        \Illuminate\Support\Facades\Log::error("Stripe {$step} failed while preparing card entry", [
            'status' => $response->status(),
            'stripe_error_type' => $response->json('error.type'),
            'stripe_error' => $response->json('error.message'),
            'key_source' => $keys['source'],
            'key_mode' => $keys['mode'],
        ]);

        throw ValidationException::withMessages([
            'stripe' => ['Card payments are unavailable right now. Please try again later or contact support.'],
        ]);
    }

    private function stripe(string $method, string $path, array $data = [], ?string $idempotencyKey = null)
    {
        $request = Http::asForm()->withBasicAuth($this->secret(), '')->timeout(20);
        if ($idempotencyKey) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $method === 'get'
            ? $request->get('https://api.stripe.com/v1' . $path, $data)
            : $request->post('https://api.stripe.com/v1' . $path, $data);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
