<?php

namespace App\Console\Commands;

use App\Models\Donation;
use App\Models\EcommerceOrder;
use App\Models\EcommerceWalletTransaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncRefundedDonationsToWallet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'donations:sync-wallet-refunds {--order= : Specific order number to sync} {--id= : Specific donation ID to sync} {--dry-run : Only show what would be synced without crediting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds refunded donations that have not yet been credited to the customer wallet and applies the wallet refund.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orderFilter = $this->option('order');
        $idFilter = $this->option('id');
        $dryRun = (bool) $this->option('dry-run');

        $query = Donation::where('status', 'Refunded');

        if ($orderFilter) {
            $query->where('order_id', $orderFilter);
        }

        if ($idFilter) {
            $query->where('id', $idFilter);
        }

        $refundedDonations = $query->orderBy('id', 'asc')->get();

        if ($refundedDonations->isEmpty()) {
            $this->info('No refunded donations found to process.');
            return 0;
        }

        $this->info("Found {$refundedDonations->count()} refunded donation(s). Checking wallet credit status...");

        $syncedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($refundedDonations as $donation) {
            $refIdPrefix = 'REF-DON-' . $donation->id;
            $altRefIdPrefix = 'DON-REV-' . $donation->id;

            // Check if wallet credit already exists
            $existingTx = EcommerceWalletTransaction::where('reference_id', $refIdPrefix)
                ->orWhere('reference_id', $altRefIdPrefix)
                ->orWhere('reference_id', 'like', $refIdPrefix . '-%')
                ->first();

            if ($existingTx) {
                $this->line(" - Donation #{$donation->id} (Order {$donation->order_id}): Already credited in wallet (Tx #{$existingTx->id}).");
                $skippedCount++;
                continue;
            }

            // Locate user
            $user = null;
            if (!empty($donation->user_id)) {
                $user = User::find($donation->user_id);
            }

            $order = null;
            if (!empty($donation->order_id)) {
                $order = EcommerceOrder::where('order_number', $donation->order_id)->first();
                if (!$user && $order) {
                    if (!empty($order->user_id)) {
                        $user = User::find($order->user_id);
                    }
                    if (!$user && !empty($order->customer_email)) {
                        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($order->customer_email))])->first();
                    }
                }
            }

            if (!$user && !empty($donation->donor_email)) {
                $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($donation->donor_email))])->first();
            }

            $email = strtolower(trim((string)($donation->donor_email ?: ($order->customer_email ?? ''))));
            if (!$user && !empty($email)) {
                if ($dryRun) {
                    $this->warn(" [Dry-run] Would create local user record for: {$email}");
                } else {
                    $user = User::create([
                        'name' => $donation->donor_name ?: ($order->customer_name ?? 'Customer'),
                        'email' => $email,
                        'password' => bcrypt(Str::random(24)),
                        'role' => 'customer',
                        'wallet_balance' => 0.00,
                    ]);
                }
            }

            if (!$user) {
                $this->error(" - Donation #{$donation->id} (Order {$donation->order_id}): Cannot find or create user for email '{$email}'. Skipped.");
                $failedCount++;
                continue;
            }

            if (empty($donation->user_id) && !$dryRun) {
                $donation->user_id = $user->id;
                $donation->save();
            }

            $amount = (float) $donation->amount;
            if ($amount <= 0) {
                $this->line(" - Donation #{$donation->id}: Amount is 0. Skipped.");
                $skippedCount++;
                continue;
            }

            $refId = $refIdPrefix . '-' . time();
            $desc = "Refund for donation to {$donation->charity_name}" . ($donation->order_id ? " (Order #{$donation->order_id})" : "");

            if ($dryRun) {
                $this->info(" [Dry-run] Would credit \${$amount} to user {$user->email} (ID {$user->id}) - Ref: {$refId}");
                $syncedCount++;
                continue;
            }

            try {
                $credited = WalletService::adjustWallet(
                    $user->id,
                    $amount,
                    'refund',
                    $desc,
                    $refId
                );

                if ($credited) {
                    $this->info(" ✓ Donation #{$donation->id}: Successfully credited \${$amount} to user {$user->email} (Ref: {$refId}).");
                    $syncedCount++;
                } else {
                    $this->error(" ✗ Donation #{$donation->id}: WalletService::adjustWallet returned false for user {$user->email}.");
                    $failedCount++;
                }
            } catch (\Throwable $e) {
                $this->error(" ✗ Donation #{$donation->id}: Exception crediting wallet: " . $e->getMessage());
                $failedCount++;
            }
        }

        $this->newLine();
        $this->info("Sync complete. Credited: {$syncedCount}, Skipped/Already Credited: {$skippedCount}, Failed: {$failedCount}");

        return 0;
    }
}
