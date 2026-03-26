<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| API Routes - E-commerce REST API
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1 via RouteServiceProvider.
| Protected routes require a valid JWT bearer token.
|
*/

// -------------------------------------------------------------------------
// Public routes (no authentication required)
// -------------------------------------------------------------------------

Route::prefix('v1')->group(function () {

    // --- Authentication ---
    Route::prefix('auth')->group(function () {
        Route::post('/register',          [AuthController::class, 'register']);
        Route::post('/login',             [AuthController::class, 'login']);
        Route::post('/forgot-password',   [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password',    [AuthController::class, 'resetPassword']);
        Route::post('/verify-email/{token}', [AuthController::class, 'verifyEmail']);
        Route::post('/resend-verification',  [AuthController::class, 'resendVerification']);
        Route::post('/refresh',           [AuthController::class, 'refresh']);
        Route::post('/social/google',     [AuthController::class, 'socialLoginGoogle']);
        Route::post('/social/facebook',   [AuthController::class, 'socialLoginFacebook']);
    });

    // --- Public product browsing ---
    Route::prefix('products')->group(function () {
        Route::get('/',                         [ProductController::class, 'index']);
        Route::get('/featured',                 [ProductController::class, 'featured']);
        Route::get('/bestsellers',              [ProductController::class, 'bestsellers']);
        Route::get('/new-arrivals',             [ProductController::class, 'newArrivals']);
        Route::get('/on-sale',                  [ProductController::class, 'onSale']);
        Route::get('/search',                   [ProductController::class, 'search']);
        Route::get('/{id}',                     [ProductController::class, 'show']);
        Route::get('/{id}/related',             [ProductController::class, 'related']);
        Route::get('/{id}/reviews',             [ReviewController::class, 'forProduct']);
    });

    // --- Public category browsing ---
    Route::prefix('categories')->group(function () {
        Route::get('/',                         [CategoryController::class, 'index']);
        Route::get('/tree',                     [CategoryController::class, 'tree']);
        Route::get('/{id}',                     [CategoryController::class, 'show']);
        Route::get('/{id}/products',            [CategoryController::class, 'products']);
        Route::get('/{id}/subcategories',       [CategoryController::class, 'subcategories']);
    });

    // --- Public coupon validation ---
    Route::post('/coupons/validate',            [CouponController::class, 'validate']);

    // -------------------------------------------------------------------------
    // Protected routes (JWT authentication required)
    // -------------------------------------------------------------------------
    Route::middleware('auth:api')->group(function () {

        // --- Auth management ---
        Route::prefix('auth')->group(function () {
            Route::post('/logout',              [AuthController::class, 'logout']);
            Route::get('/me',                   [AuthController::class, 'me']);
            Route::put('/change-password',      [AuthController::class, 'changePassword']);
            Route::post('/two-factor/enable',   [AuthController::class, 'enableTwoFactor']);
            Route::post('/two-factor/disable',  [AuthController::class, 'disableTwoFactor']);
            Route::post('/two-factor/verify',   [AuthController::class, 'verifyTwoFactor']);
        });

        // --- User profile ---
        Route::prefix('users')->group(function () {
            Route::get('/profile',              [UserController::class, 'profile']);
            Route::put('/profile',              [UserController::class, 'updateProfile']);
            Route::put('/avatar',               [UserController::class, 'updateAvatar']);
            Route::delete('/account',           [UserController::class, 'deleteAccount']);
            Route::get('/order-history',        [UserController::class, 'orderHistory']);
            Route::get('/purchase-summary',     [UserController::class, 'purchaseSummary']);
            Route::get('/notifications',        [UserController::class, 'notifications']);
            Route::put('/notifications/{id}/read', [UserController::class, 'markNotificationRead']);
            Route::put('/notifications/read-all',  [UserController::class, 'markAllNotificationsRead']);
        });

        // --- Addresses ---
        Route::prefix('addresses')->group(function () {
            Route::get('/',                     [AddressController::class, 'index']);
            Route::post('/',                    [AddressController::class, 'store']);
            Route::get('/{id}',                 [AddressController::class, 'show']);
            Route::put('/{id}',                 [AddressController::class, 'update']);
            Route::delete('/{id}',              [AddressController::class, 'destroy']);
            Route::put('/{id}/set-default',     [AddressController::class, 'setDefault']);
        });

        // --- Shopping cart ---
        Route::prefix('cart')->group(function () {
            Route::get('/',                     [CartController::class, 'index']);
            Route::post('/items',               [CartController::class, 'addItem']);
            Route::put('/items/{itemId}',       [CartController::class, 'updateItem']);
            Route::delete('/items/{itemId}',    [CartController::class, 'removeItem']);
            Route::delete('/',                  [CartController::class, 'clear']);
            Route::post('/apply-coupon',        [CartController::class, 'applyCoupon']);
            Route::delete('/remove-coupon',     [CartController::class, 'removeCoupon']);
            Route::get('/summary',              [CartController::class, 'summary']);
        });

        // --- Orders ---
        Route::prefix('orders')->group(function () {
            Route::get('/',                     [OrderController::class, 'index']);
            Route::post('/',                    [OrderController::class, 'store']);
            Route::get('/{id}',                 [OrderController::class, 'show']);
            Route::post('/{id}/cancel',         [OrderController::class, 'cancel']);
            Route::post('/{id}/return',         [OrderController::class, 'requestReturn']);
            Route::get('/{id}/invoice',         [OrderController::class, 'downloadInvoice']);
            Route::get('/{id}/tracking',        [OrderController::class, 'trackShipment']);
        });

        // --- Payments ---
        Route::prefix('payments')->group(function () {
            Route::post('/checkout',            [PaymentController::class, 'checkout']);
            Route::post('/stripe/intent',       [PaymentController::class, 'createStripeIntent']);
            Route::post('/stripe/confirm',      [PaymentController::class, 'confirmStripePayment']);
            Route::post('/paypal/create',       [PaymentController::class, 'createPaypalOrder']);
            Route::post('/paypal/capture',      [PaymentController::class, 'capturePaypalOrder']);
            Route::get('/methods',              [PaymentController::class, 'savedMethods']);
            Route::delete('/methods/{id}',      [PaymentController::class, 'removeMethod']);
        });

        // --- Reviews ---
        Route::prefix('reviews')->group(function () {
            Route::post('/',                    [ReviewController::class, 'store']);
            Route::put('/{id}',                 [ReviewController::class, 'update']);
            Route::delete('/{id}',              [ReviewController::class, 'destroy']);
            Route::post('/{id}/helpful',        [ReviewController::class, 'markHelpful']);
            Route::post('/{id}/report',         [ReviewController::class, 'report']);
            Route::get('/my-reviews',           [ReviewController::class, 'myReviews']);
        });

        // --- Wishlist ---
        Route::prefix('wishlist')->group(function () {
            Route::get('/',                     [WishlistController::class, 'index']);
            Route::post('/items',               [WishlistController::class, 'addItem']);
            Route::delete('/items/{productId}', [WishlistController::class, 'removeItem']);
            Route::post('/move-to-cart',        [WishlistController::class, 'moveToCart']);
            Route::get('/check/{productId}',    [WishlistController::class, 'check']);
        });

        // -----------------------------------------------------------------------
        // Admin-only routes
        // -----------------------------------------------------------------------
        Route::middleware('role:admin')->prefix('admin')->group(function () {

            // Admin: users management
            Route::prefix('users')->group(function () {
                Route::get('/',                 [UserController::class, 'adminIndex']);
                Route::get('/{id}',             [UserController::class, 'adminShow']);
                Route::put('/{id}',             [UserController::class, 'adminUpdate']);
                Route::put('/{id}/ban',         [UserController::class, 'ban']);
                Route::put('/{id}/unban',       [UserController::class, 'unban']);
                Route::delete('/{id}',          [UserController::class, 'adminDestroy']);
            });

            // Admin: product management
            Route::prefix('products')->group(function () {
                Route::post('/',                [ProductController::class, 'store']);
                Route::put('/{id}',             [ProductController::class, 'update']);
                Route::delete('/{id}',          [ProductController::class, 'destroy']);
                Route::post('/{id}/images',     [ProductController::class, 'uploadImages']);
                Route::delete('/{id}/images/{imageId}', [ProductController::class, 'deleteImage']);
                Route::put('/{id}/publish',     [ProductController::class, 'publish']);
                Route::put('/{id}/unpublish',   [ProductController::class, 'unpublish']);
                Route::post('/bulk-import',     [ProductController::class, 'bulkImport']);
                Route::post('/bulk-delete',     [ProductController::class, 'bulkDelete']);
                Route::get('/export',           [ProductController::class, 'export']);
            });

            // Admin: category management
            Route::prefix('categories')->group(function () {
                Route::post('/',                [CategoryController::class, 'store']);
                Route::put('/{id}',             [CategoryController::class, 'update']);
                Route::delete('/{id}',          [CategoryController::class, 'destroy']);
                Route::put('/reorder',          [CategoryController::class, 'reorder']);
            });

            // Admin: order management
            Route::prefix('orders')->group(function () {
                Route::get('/',                 [OrderController::class, 'adminIndex']);
                Route::get('/{id}',             [OrderController::class, 'adminShow']);
                Route::put('/{id}/status',      [OrderController::class, 'updateStatus']);
                Route::put('/{id}/assign',      [OrderController::class, 'assignFulfillment']);
                Route::post('/{id}/refund',     [OrderController::class, 'processRefund']);
                Route::post('/bulk-export',     [OrderController::class, 'bulkExport']);
            });

            // Admin: coupon management
            Route::prefix('coupons')->group(function () {
                Route::get('/',                 [CouponController::class, 'adminIndex']);
                Route::post('/',                [CouponController::class, 'store']);
                Route::get('/{id}',             [CouponController::class, 'show']);
                Route::put('/{id}',             [CouponController::class, 'update']);
                Route::delete('/{id}',          [CouponController::class, 'destroy']);
                Route::get('/{id}/usage',       [CouponController::class, 'usageReport']);
            });

            // Admin: reports & analytics
            Route::prefix('reports')->group(function () {
                Route::get('/sales',            [ReportController::class, 'sales']);
                Route::get('/revenue',          [ReportController::class, 'revenue']);
                Route::get('/top-products',     [ReportController::class, 'topProducts']);
                Route::get('/customers',        [ReportController::class, 'customers']);
                Route::get('/inventory',        [ReportController::class, 'inventory']);
                Route::get('/refunds',          [ReportController::class, 'refunds']);
                Route::get('/dashboard',        [ReportController::class, 'dashboard']);
            });

            // Admin: review moderation
            Route::prefix('reviews')->group(function () {
                Route::get('/',                 [ReviewController::class, 'adminIndex']);
                Route::put('/{id}/approve',     [ReviewController::class, 'approve']);
                Route::put('/{id}/reject',      [ReviewController::class, 'reject']);
                Route::delete('/{id}',          [ReviewController::class, 'adminDestroy']);
            });
        });
    });
});

// Webhook routes (signed, no JWT auth)
Route::prefix('webhooks')->group(function () {
    Route::post('/stripe',   [PaymentController::class, 'stripeWebhook'])->middleware('stripe.webhook');
    Route::post('/paypal',   [PaymentController::class, 'paypalWebhook']);
    Route::post('/shipment', [OrderController::class,   'shipmentWebhook']);
});
