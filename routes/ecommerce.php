<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E-Commerce API Routes
|--------------------------------------------------------------------------
|
| These routes handle all e-commerce functionality: carts, checkout,
| orders, wallet, profile, disputes, tickets, etc.
|
*/

Route::prefix('ecommerce')->group(function () {

    // Public E-Commerce Routes (No Auth Required)
    Route::get('/categories', [\App\Http\Controllers\Api\Ecommerce\CategoryController::class, 'index']);
    Route::get('/shipping-methods', [\App\Http\Controllers\Api\Ecommerce\ShippingMethodController::class, 'index']);
    Route::get('/delivery-times', [\App\Http\Controllers\Api\Ecommerce\DeliveryTimeController::class, 'index']);
    Route::get('/subscription-plans', [\App\Http\Controllers\Api\Admin\AdminSubscriptionPlanController::class, 'publicIndex']);
    Route::get('/membership-benefits', [\App\Http\Controllers\Api\Admin\AdminMembershipBenefitController::class, 'publicIndex']);
    Route::get('/memberships', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'index'])
        ->middleware('central.auth:optional');
    Route::post('/pickup-locations/eligible', [\App\Http\Controllers\Api\Ecommerce\PublicPickupLocationController::class, 'getEligibleLocations']);
    Route::get('/products', [\App\Http\Controllers\Api\Ecommerce\ProductController::class, 'index']);
    Route::get('/coupons/validate', [\App\Http\Controllers\Api\Admin\EcommerceCouponController::class, 'validateCoupon']);
    Route::get('/coupons', [\App\Http\Controllers\Api\Admin\EcommerceCouponController::class, 'publicIndex'])
        ->middleware('central.auth:optional');
    Route::get('/charity-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getCharity']);
    Route::get('/charities', [\App\Http\Controllers\Api\Admin\CharityController::class, 'publicIndex']);
    Route::get('/tips-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getTips']);
    Route::get('/packaging-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getPackaging']);
    Route::get('/loyalty-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getLoyalty']);
    Route::get('/payment-gateways', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'publicIndex']);
    Route::post('/products/{product}/price-preview', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'pricePreview']);
    Route::post('/products/{product}/customization-drafts', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'storeDraft'])
        ->middleware('central.auth:optional');
    Route::get('/products/{product}/coupons', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'coupons']);
    Route::get('/products/{product}/related', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'related']);
    Route::get('/products/{product}', [\App\Http\Controllers\Api\Ecommerce\ProductController::class, 'show']);
    Route::put('/customization-drafts/{draft}', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'updateDraft'])
        ->middleware('central.auth:optional');
    Route::post('/customization-drafts/{draft}/files', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'uploadFile'])
        ->middleware('central.auth:optional');
    Route::post('/customization-drafts/{draft}/submit-order', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'submitOrder'])
        ->middleware('central.auth:optional');
    Route::post('/customization-drafts/{draft}/submit-quote', [\App\Http\Controllers\Api\Ecommerce\ProductCustomizationController::class, 'submitQuote'])
        ->middleware('central.auth:optional');
    Route::post('/order-submissions', [\App\Http\Controllers\Api\Ecommerce\PublicOrderSubmissionController::class, 'store'])
        ->middleware('central.auth:optional');
    Route::post('/quotations', [\App\Http\Controllers\Api\Ecommerce\EcommerceQuotationController::class, 'store'])
        ->middleware('central.auth:optional');
    Route::post('/support-tickets', [\App\Http\Controllers\Api\Ecommerce\EcommerceTicketController::class, 'publicStore']);
    Route::post('/product-reviews', [\App\Http\Controllers\Api\Ecommerce\EcommerceReviewController::class, 'publicStore'])
        ->middleware('central.auth:optional');
    Route::get('/products/{product}/can-review', [\App\Http\Controllers\Api\Ecommerce\EcommerceReviewController::class, 'canReview'])
        ->middleware('central.auth:optional');
    Route::get('/product-questions', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'index'])
        ->middleware('central.auth:optional');
    Route::get('/product-questions/{question}', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'show'])
        ->middleware('central.auth:optional');
    Route::post('/product-questions', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'store'])
        ->middleware('central.auth:optional');
    Route::post('/product-questions/{question}/replies', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'addReply'])
        ->middleware('central.auth:optional');
    Route::get('/wishlist/shared/{token}', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'shared']);
    Route::get('/payment/orders/{order}', [\App\Http\Controllers\Api\Ecommerce\PaymentController::class, 'showOrder']);
    Route::post('/webhooks/stripe', [\App\Http\Controllers\Api\Ecommerce\StripeWebhookController::class, 'handle']);
    Route::post('/payment/process', [\App\Http\Controllers\Api\Ecommerce\PaymentController::class, 'process']);
    Route::match(['get', 'post'], '/payment/paypal/success', [\App\Http\Controllers\Api\Ecommerce\PaymentController::class, 'paypalSuccess']);
    Route::match(['get', 'post'], '/payment/paypal/cancel', [\App\Http\Controllers\Api\Ecommerce\PaymentController::class, 'paypalCancel']);
    Route::get('/disputes', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeController::class, 'index'])
        ->middleware('central.auth:optional');
    Route::post('/disputes', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeController::class, 'store'])
        ->middleware('central.auth:optional');
    Route::get('/dispute-types', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeTypeController::class, 'index'])
        ->middleware('central.auth:optional');
    Route::get('/attributes', [\App\Http\Controllers\Api\Ecommerce\PublicAttributeController::class, 'index']);

    // Gift Cards & Orders (Public / Optional Auth)
    Route::post('/gift-card-orders', [\App\Http\Controllers\Api\Ecommerce\GiftCardOrderController::class, 'store'])
        ->middleware('central.auth:optional');
    Route::get('/gift-card-orders/{id}', [\App\Http\Controllers\Api\Ecommerce\GiftCardOrderController::class, 'show'])
        ->middleware('central.auth:optional');
    Route::post('/payment/gift-card-order', [\App\Http\Controllers\Api\Ecommerce\GiftCardOrderController::class, 'pay'])
        ->middleware('central.auth:optional');
    Route::post('/gift-cards/validate', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'validateCode'])
        ->middleware('central.auth:optional');
    Route::post('/checkout', [\App\Http\Controllers\Api\Ecommerce\CheckoutController::class, 'process'])
        ->middleware('central.auth:optional');
    // Order tracking & show are optional-auth so guests can look up their orders
    Route::get('/orders/stats', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'stats'])
        ->middleware('central.auth:optional');
    Route::get('/orders/track', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'track'])
        ->middleware('central.auth:optional');
    Route::get('/orders/{order}', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'show'])
        ->middleware('central.auth:optional');

    // Order Verifications (Customer Routes)
    Route::get('/order-verifications', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'index'])
        ->middleware('central.auth:optional');
    Route::get('/order-verifications/{id}', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'show'])
        ->middleware('central.auth:optional');
    Route::post('/order-verifications/{id}/documents', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'uploadDocuments'])
        ->middleware('central.auth:optional');
    Route::post('/order-verifications/{id}/notes', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'addNote'])
        ->middleware('central.auth:optional');

    // Guest Cart (Session/Cookie based cart could be handled here if needed,
    // but usually we force auth for b2b or manage via local storage on frontend)

    // Protected E-Commerce Routes (Auth Required)
    Route::middleware(['central.auth'])->group(function () {
        
        // Cart
        Route::get('/cart', [\App\Http\Controllers\Api\Ecommerce\CartController::class, 'index']);
        Route::post('/cart/items', [\App\Http\Controllers\Api\Ecommerce\CartController::class, 'addItem']);
        Route::put('/cart/items/{id}', [\App\Http\Controllers\Api\Ecommerce\CartController::class, 'updateItem']);
        Route::delete('/cart/items/{id}', [\App\Http\Controllers\Api\Ecommerce\CartController::class, 'removeItem']);
        Route::delete('/cart', [\App\Http\Controllers\Api\Ecommerce\CartController::class, 'clear']);

        // Coupons (Public route defined above with central.auth:optional)

        // Wishlist
        Route::get('/wishlist', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'index']);
        Route::post('/wishlist/items', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'addItem']);
        Route::put('/wishlist/items/{item}', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'updateItem']);
        Route::delete('/wishlist/items/{item}', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'removeItem']);
        Route::delete('/wishlist/items', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'bulkRemoveItems']);
        Route::post('/wishlist/collections', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'createCollection']);
        Route::put('/wishlist/collections/{collection}', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'updateCollection']);
        Route::delete('/wishlist/collections/{collection}', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'deleteCollection']);
        Route::post('/wishlist/add-to-cart', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'addSelectedToCart']);
        Route::post('/wishlist/share', [\App\Http\Controllers\Api\Ecommerce\WishlistController::class, 'share']);

        // Compare Products
        Route::get('/compare-products', [\App\Http\Controllers\Api\Ecommerce\CompareProductController::class, 'index']);
        Route::post('/compare-products', [\App\Http\Controllers\Api\Ecommerce\CompareProductController::class, 'store']);
        Route::delete('/compare-products', [\App\Http\Controllers\Api\Ecommerce\CompareProductController::class, 'clear']);
        Route::delete('/compare-products/{productId}', [\App\Http\Controllers\Api\Ecommerce\CompareProductController::class, 'destroy']);

        // Checkout, Payments & Orders
        Route::post('/payment/process', [\App\Http\Controllers\Api\Ecommerce\PaymentController::class, 'process'])
            ->middleware('pin.verified:checkout_payment');
        Route::match(['get', 'post'], '/payment/paypal/success', [\App\Http\Controllers\Api\Ecommerce\PaymentController::class, 'paypalSuccess']);
        Route::match(['get', 'post'], '/payment/paypal/cancel', [\App\Http\Controllers\Api\Ecommerce\PaymentController::class, 'paypalCancel']);
        
        Route::get('/orders/stats', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'stats']);
        Route::get('/orders/track', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'track']);
        Route::post('/orders/{order}/cancel', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'cancel']);
        Route::post('/orders/{order}/tip', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'tip'])
            ->middleware('pin.verified:checkout_payment');
        Route::get('/orders/{order}/invoice', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'invoice']);
        Route::post('/orders/{order}/reorder', [\App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class, 'reorder']);
        Route::apiResource('orders', \App\Http\Controllers\Api\Ecommerce\EcommerceOrderController::class);

        // Account Profile & Addresses
        Route::get('/profile', [\App\Http\Controllers\Api\Ecommerce\ProfileController::class, 'show']);
        Route::put('/profile', [\App\Http\Controllers\Api\Ecommerce\ProfileController::class, 'update'])
            ->middleware('pin.verified:account_security_change');
        Route::put('/profile/password', [\App\Http\Controllers\Api\Ecommerce\ProfileController::class, 'updatePassword'])
            ->middleware('pin.verified:account_security_change');
        Route::put('/profile/pin', [\App\Http\Controllers\Api\Ecommerce\ProfileController::class, 'updatePin'])
            ->middleware('pin.verified:account_security_change');
        
        Route::put('/addresses/{address}/default', [\App\Http\Controllers\Api\Ecommerce\AddressController::class, 'setDefault']);
        Route::apiResource('addresses', \App\Http\Controllers\Api\Ecommerce\AddressController::class);

        Route::get('/payment-methods', [\App\Http\Controllers\Api\Ecommerce\PaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [\App\Http\Controllers\Api\Ecommerce\PaymentMethodController::class, 'store'])
            ->middleware('pin.verified:financial_withdrawal');
        Route::put('/payment-methods/{paymentMethod}/default', [\App\Http\Controllers\Api\Ecommerce\PaymentMethodController::class, 'setDefault'])
            ->middleware('pin.verified:financial_withdrawal');
        Route::delete('/payment-methods/{paymentMethod}', [\App\Http\Controllers\Api\Ecommerce\PaymentMethodController::class, 'destroy'])
            ->middleware('pin.verified:financial_withdrawal');

        Route::get('/bank-accounts', [\App\Http\Controllers\Api\Ecommerce\BankAccountController::class, 'index']);
        Route::post('/bank-accounts', [\App\Http\Controllers\Api\Ecommerce\BankAccountController::class, 'store'])
            ->middleware('pin.verified:financial_withdrawal');
        Route::delete('/bank-accounts/{bankAccount}', [\App\Http\Controllers\Api\Ecommerce\BankAccountController::class, 'destroy'])
            ->middleware('pin.verified:financial_withdrawal');

        // Wallet & Transactions
        Route::get('/wallet', [\App\Http\Controllers\Api\Ecommerce\EcommerceWalletTransactionController::class, 'summary']);
        Route::apiResource('wallet-transactions', \App\Http\Controllers\Api\Ecommerce\EcommerceWalletTransactionController::class)
            ->except(['index', 'show'])
            ->middleware('pin.verified:financial_withdrawal');
        Route::apiResource('wallet-transactions', \App\Http\Controllers\Api\Ecommerce\EcommerceWalletTransactionController::class)
            ->only(['index', 'show']);

        // Other E-Commerce Features
        Route::apiResource('quotations', \App\Http\Controllers\Api\Ecommerce\EcommerceQuotationController::class)
            ->except(['store']);
        Route::post('memberships/{id}/{action}', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'action']);
        Route::get('membership-transactions', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'transactions']);
        Route::get('membership-transactions/{id}/receipt', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'receipt']);
        Route::get('membership-savings', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'savings']);
        Route::apiResource('memberships', \App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class)
            ->except(['index']);
        Route::apiResource('disputes', \App\Http\Controllers\Api\Ecommerce\EcommerceDisputeController::class)
            ->except(['index', 'store']);
        Route::apiResource('tickets', \App\Http\Controllers\Api\Ecommerce\EcommerceTicketController::class);
        Route::post('tickets/{ticket}/reply', [\App\Http\Controllers\Api\Ecommerce\EcommerceTicketController::class, 'addReply']);
        Route::post('tickets/{ticket}/attachments', [\App\Http\Controllers\Api\Ecommerce\EcommerceTicketController::class, 'uploadAttachment']);
        Route::get('conversations', [\App\Http\Controllers\Api\Ecommerce\EcommerceConversationController::class, 'index']);
        Route::post('conversations', [\App\Http\Controllers\Api\Ecommerce\EcommerceConversationController::class, 'store']);
        Route::get('conversations/{conversation}', [\App\Http\Controllers\Api\Ecommerce\EcommerceConversationController::class, 'show']);
        Route::post('conversations/{conversation}/messages', [\App\Http\Controllers\Api\Ecommerce\EcommerceConversationController::class, 'addMessage']);
        Route::post('conversations/{conversation}/close', [\App\Http\Controllers\Api\Ecommerce\EcommerceConversationController::class, 'close']);
        Route::get('returns/stats', [\App\Http\Controllers\Api\Ecommerce\EcommerceReturnController::class, 'stats']);
        Route::post('returns/{id}/cancel', [\App\Http\Controllers\Api\Ecommerce\EcommerceReturnController::class, 'cancel']);
        Route::post('returns/{id}/respond-details', [\App\Http\Controllers\Api\Ecommerce\EcommerceReturnController::class, 'respondMoreDetails']);
        Route::post('returns/{id}/tracking', [\App\Http\Controllers\Api\Ecommerce\EcommerceReturnController::class, 'uploadTracking']);
        Route::apiResource('returns', \App\Http\Controllers\Api\Ecommerce\EcommerceReturnController::class);
        Route::post('/gift-cards/redeem-to-wallet', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'redeemToWallet']);
        Route::post('/gift-cards/{id}/transfer', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'transfer']);
        Route::apiResource('gift-cards', \App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class);
        Route::apiResource('reviews', \App\Http\Controllers\Api\Ecommerce\EcommerceReviewController::class);
        Route::get('affiliates/my/referrals', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'myReferrals']);
        Route::get('affiliates/my/commissions', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'myCommissions']);
        Route::post('affiliates', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'store']);
        Route::post('affiliate-applications', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'apply']);
        Route::apiResource('affiliates', \App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class)
            ->only(['index', 'show']);
        Route::apiResource('affiliates', \App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class)
            ->only(['update', 'destroy'])
            ->middleware('pin.verified:financial_withdrawal');

        // Order Proofs
        Route::get('/proofs', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'index']);
        Route::get('/orders/{orderId}/proofs', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'getByOrder']);
        Route::get('/proofs/{id}', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'show']);
        Route::get('/proofs/{id}/comments', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'comments']);
        Route::post('/proofs/{id}/comments', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'addComment']);
        Route::post('/proofs/{id}/approve', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'approve']);
        Route::post('/proofs/{id}/reject', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'reject']);
        Route::post('/proofs/{id}/request-revision', [\App\Http\Controllers\Api\Ecommerce\OrderProofController::class, 'requestRevision']);

        // Customer Downloads
        Route::get('/downloads', [\App\Http\Controllers\Api\Ecommerce\EcommerceDownloadController::class, 'index'])
            ->name('api.v1.ecommerce.downloads.index');
        Route::get('/downloads/stats', [\App\Http\Controllers\Api\Ecommerce\EcommerceDownloadController::class, 'stats'])
            ->name('api.v1.ecommerce.downloads.stats');
        Route::get('/downloads/bulk-download', [\App\Http\Controllers\Api\Ecommerce\EcommerceDownloadController::class, 'bulkDownload'])
            ->name('api.v1.ecommerce.downloads.bulk-download');
        Route::get('/downloads/{downloadId}', [\App\Http\Controllers\Api\Ecommerce\EcommerceDownloadController::class, 'show'])
            ->name('api.v1.ecommerce.downloads.show');
        Route::get('/downloads/{downloadId}/preview', [\App\Http\Controllers\Api\Ecommerce\EcommerceDownloadController::class, 'preview'])
            ->name('api.v1.ecommerce.downloads.preview');
        Route::get('/downloads/{downloadId}/download', [\App\Http\Controllers\Api\Ecommerce\EcommerceDownloadController::class, 'download'])
            ->name('api.v1.ecommerce.downloads.download');
        Route::post('/downloads/request', [\App\Http\Controllers\Api\Ecommerce\EcommerceDownloadController::class, 'requestFiles'])
            ->name('api.v1.ecommerce.downloads.request');
    });

    // Admin E-Commerce Routes (Auth + Admin Role Required)
    Route::middleware(['central.auth', 'admin.only', 'admin.mfa'])->group(function () {
        // Orders Management
        Route::get('/admin/orders', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'index']);
        Route::get('/admin/orders/stats', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'stats']);
        Route::get('/admin/orders/{order}', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'show']);
        Route::put('/admin/orders/{order}/status', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'updateStatus']);
        Route::delete('/admin/orders/{order}', [\App\Http\Controllers\Api\Admin\AdminOrderController::class, 'destroy']);

        // Reviews Management
        Route::get('/admin/reviews', [\App\Http\Controllers\Api\Admin\AdminReviewController::class, 'index']);
        Route::get('/admin/reviews/stats', [\App\Http\Controllers\Api\Admin\AdminReviewController::class, 'stats']);
        Route::get('/admin/reviews/{review}', [\App\Http\Controllers\Api\Admin\AdminReviewController::class, 'show']);
        Route::put('/admin/reviews/{review}', [\App\Http\Controllers\Api\Admin\AdminReviewController::class, 'approve']);
        Route::delete('/admin/reviews/{review}', [\App\Http\Controllers\Api\Admin\AdminReviewController::class, 'destroy']);

        // Product Questions Management (Admin)
        Route::get('/admin/product-questions/stats', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'stats']);
        Route::get('/admin/product-questions', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'index']);
        Route::put('/admin/product-questions/{question}/status', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'updateStatus']);
        Route::post('/admin/product-questions/{question}/replies', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'addReply']);
        Route::delete('/admin/product-questions/{question}', [\App\Http\Controllers\Api\Ecommerce\EcommerceProductQuestionController::class, 'destroy']);

        // Returns & Refunds Management
        Route::get('/admin/returns/stats', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'stats']);
        Route::get('/admin/returns', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'index']);
        Route::get('/admin/returns/{return}', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'show']);
        Route::put('/admin/returns/{return}/status', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateStatus']);
        Route::post('/admin/returns/{return}/approve', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'approve']);
        Route::post('/admin/returns/{return}/decline', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'decline']);
        Route::post('/admin/returns/{return}/request-info', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'requestInfo']);
        Route::put('/admin/returns/{return}/note', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateNote']);

        // Replacements Management
        Route::get('/admin/replacements/stats', [\App\Http\Controllers\Api\Admin\AdminReplacementController::class, 'stats']);
        Route::get('/admin/replacements', [\App\Http\Controllers\Api\Admin\AdminReplacementController::class, 'index']);
        Route::get('/admin/replacements/{id}', [\App\Http\Controllers\Api\Admin\AdminReplacementController::class, 'show']);
        Route::post('/admin/replacements/{id}/approve', [\App\Http\Controllers\Api\Admin\AdminReplacementController::class, 'approve']);
        Route::post('/admin/replacements/{id}/decline', [\App\Http\Controllers\Api\Admin\AdminReplacementController::class, 'decline']);
        Route::put('/admin/replacements/{id}/note', [\App\Http\Controllers\Api\Admin\AdminReplacementController::class, 'updateNote']);
        Route::post('/admin/replacements/{id}/supporting-docs', [\App\Http\Controllers\Api\Admin\AdminReplacementController::class, 'uploadSupportingDocs']);

        // Dedicated Refunds Routes
        Route::get('/admin/refunds/stats', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'stats']);
        Route::get('/admin/refunds/settings', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'getSettings']);
        Route::post('/admin/refunds/settings', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'saveSettings']);
        Route::get('/admin/refunds', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'index']);
        Route::get('/admin/refunds/{return}', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'show']);
        Route::put('/admin/refunds/{return}/status', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateStatus']);
        Route::post('/admin/refunds/{return}/approve', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'approve']);
        Route::post('/admin/refunds/{return}/decline', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'decline']);
        Route::post('/admin/refunds/{return}/request-info', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'requestInfo']);
        Route::put('/admin/refunds/{return}/note', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateNote']);

        // Quotations Management
        Route::get('/admin/quotations', [\App\Http\Controllers\Api\Admin\AdminQuotationController::class, 'index']);
        Route::get('/admin/quotations/{quotation}', [\App\Http\Controllers\Api\Admin\AdminQuotationController::class, 'show']);
        Route::put('/admin/quotations/{quotation}/status', [\App\Http\Controllers\Api\Admin\AdminQuotationController::class, 'updateStatus']);
        Route::post('/admin/quotations/{quotation}/send-quote', [\App\Http\Controllers\Api\Admin\AdminQuotationController::class, 'sendQuote']);

        // Support Tickets Management
        Route::get('/admin/tickets', [\App\Http\Controllers\Api\Admin\AdminTicketController::class, 'index']);
        Route::get('/admin/tickets/{ticket}', [\App\Http\Controllers\Api\Admin\AdminTicketController::class, 'show']);
        Route::put('/admin/tickets/{ticket}/status', [\App\Http\Controllers\Api\Admin\AdminTicketController::class, 'updateStatus']);
        Route::post('/admin/tickets/{ticket}/reply', [\App\Http\Controllers\Api\Admin\AdminTicketController::class, 'addReply']);
        Route::post('/admin/tickets/{ticket}/notes', [\App\Http\Controllers\Api\Admin\AdminTicketController::class, 'addNote']);
        Route::post('/admin/tickets/{ticket}/close', [\App\Http\Controllers\Api\Admin\AdminTicketController::class, 'close']);

        // Messages Management
        Route::get('/admin/conversations', [\App\Http\Controllers\Api\Admin\AdminConversationController::class, 'index']);
        Route::get('/admin/conversations/{conversation}', [\App\Http\Controllers\Api\Admin\AdminConversationController::class, 'show']);
        Route::post('/admin/conversations/{conversation}/messages', [\App\Http\Controllers\Api\Admin\AdminConversationController::class, 'addMessage']);
        Route::put('/admin/conversations/{conversation}/status', [\App\Http\Controllers\Api\Admin\AdminConversationController::class, 'updateStatus']);

        // Wallet Management
        Route::get('/admin/wallet', [\App\Http\Controllers\Api\Admin\AdminWalletController::class, 'index']);
        Route::get('/admin/wallet/{user}', [\App\Http\Controllers\Api\Admin\AdminWalletController::class, 'getUserWallet']);
        Route::post('/admin/wallet/{user}/credit', [\App\Http\Controllers\Api\Admin\AdminWalletController::class, 'creditWallet']);
        Route::post('/admin/wallet/{user}/debit', [\App\Http\Controllers\Api\Admin\AdminWalletController::class, 'debitWallet']);

        // Global Attributes Management
        Route::post('admin/attributes/upload-image', [\App\Http\Controllers\Api\Admin\AdminAttributeController::class, 'uploadImage']);
        Route::apiResource('admin/attributes', \App\Http\Controllers\Api\Admin\AdminAttributeController::class);
        Route::apiResource('admin/delivery-times', \App\Http\Controllers\Api\Admin\DeliveryTimeController::class);
        Route::post('admin/delivery-times/reorder', [\App\Http\Controllers\Api\Admin\DeliveryTimeController::class, 'reorder']);
        Route::apiResource('admin/pickup-locations', \App\Http\Controllers\Api\Admin\StorePickupLocationController::class);

        // Disputes Management
        Route::get('/admin/disputes', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeController::class, 'index']);
        Route::get('/admin/disputes/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeController::class, 'show']);
        Route::put('/admin/disputes/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeController::class, 'update']);
        Route::delete('/admin/disputes/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeController::class, 'destroy']);
        Route::get('/admin/dispute-types', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeTypeController::class, 'adminIndex']);
        Route::post('/admin/dispute-types', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeTypeController::class, 'store']);
        Route::get('/admin/dispute-types/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeTypeController::class, 'show']);
        Route::put('/admin/dispute-types/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeTypeController::class, 'update']);
        Route::delete('/admin/dispute-types/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeTypeController::class, 'destroy']);
        Route::patch('/admin/dispute-types/{id}/toggle-status', [\App\Http\Controllers\Api\Ecommerce\EcommerceDisputeTypeController::class, 'toggleStatus']);

        // Memberships Management
        Route::get('/admin/memberships', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'index']);
        Route::get('/admin/membership-transactions', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'adminTransactions']);
        Route::get('/admin/membership-transactions/{id}/receipt', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'adminReceipt']);
        Route::get('/admin/membership-audit-logs', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'adminAuditLogs']);
        Route::get('/admin/memberships/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'show']);
        Route::put('/admin/memberships/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'update']);
        Route::delete('/admin/memberships/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceMembershipController::class, 'destroy']);

        // Admin Downloads Management
        Route::get('/admin/downloads', [\App\Http\Controllers\Api\Ecommerce\EcommerceAdminDownloadController::class, 'index']);
        Route::get('/admin/downloads/stats', [\App\Http\Controllers\Api\Ecommerce\EcommerceAdminDownloadController::class, 'stats']);
        Route::get('/admin/downloads/users', [\App\Http\Controllers\Api\Ecommerce\EcommerceAdminDownloadController::class, 'users']);
        Route::post('/admin/downloads', [\App\Http\Controllers\Api\Ecommerce\EcommerceAdminDownloadController::class, 'store']);
        Route::delete('/admin/downloads/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceAdminDownloadController::class, 'destroy']);
        Route::match(['get', 'post'], '/admin/downloads/settings', [\App\Http\Controllers\Api\Ecommerce\EcommerceAdminDownloadController::class, 'settings']);

        // Aliases for Customers & Businesses under ecommerce prefix
        Route::get('/admin/customers', [\App\Http\Controllers\Api\Admin\UserController::class, 'customers']);
        Route::get('/admin/business-users', [\App\Http\Controllers\Api\Admin\UserController::class, 'businessUsers']);

        // Affiliates Management
        Route::get('/admin/affiliates', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'index']);
        Route::get('/admin/affiliate-settings', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'getSettings']);
        Route::post('/admin/affiliate-settings', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'updateSettings']);
        Route::get('/admin/referrals', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'referralsList']);
        Route::get('/admin/referral-commissions', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'referralCommissionsList']);
        Route::post('/admin/referral-commissions/{id}/payout', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'payout']);
        Route::get('/admin/affiliates/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'show']);
        Route::put('/admin/affiliates/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'update']);
        Route::delete('/admin/affiliates/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'destroy']);

        // Affiliate Applications Management
        Route::get('/admin/affiliate-applications', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'applicationsList']);
        Route::post('/admin/affiliate-applications/{id}/approve', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'approveApplication']);
        Route::post('/admin/affiliate-applications/{id}/reject', [\App\Http\Controllers\Api\Ecommerce\EcommerceAffiliateController::class, 'rejectApplication']);

        // Tax Rates Configuration
        Route::get('/admin/tax-rates', [\App\Http\Controllers\Api\Admin\TaxRateController::class, 'index']);
        Route::post('/admin/tax-rates', [\App\Http\Controllers\Api\Admin\TaxRateController::class, 'store']);
        Route::put('/admin/tax-rates/{id}', [\App\Http\Controllers\Api\Admin\TaxRateController::class, 'update']);
        Route::delete('/admin/tax-rates/{id}', [\App\Http\Controllers\Api\Admin\TaxRateController::class, 'destroy']);

        // Coupons Management
        Route::apiResource('admin/coupons', \App\Http\Controllers\Api\Admin\EcommerceCouponController::class);

        // Loyalty Points Settings
        Route::get('/admin/loyalty-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getLoyalty']);
        Route::post('/admin/loyalty-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'saveLoyalty']);
        Route::post('/admin/loyalty-config/adjust-points', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'adjustPoints']);
        Route::get('/admin/loyalty-config/transactions', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getTransactions']);

        // Charity / Donation Settings
        Route::get('/admin/charity-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getCharity']);
        Route::post('/admin/charity-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'saveCharity']);

        // Tips Settings
        Route::get('/admin/tips-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getTips']);
        Route::post('/admin/tips-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'saveTips']);

        // Packaging Settings
        Route::get('/admin/packaging-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'getPackaging']);
        Route::post('/admin/packaging-config', [\App\Http\Controllers\Api\Admin\EcommerceConfigController::class, 'savePackaging']);
    });

});
