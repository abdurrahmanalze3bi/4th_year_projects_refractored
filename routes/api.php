<?php

use App\Http\Controllers\API\AdminDashboardController;
use App\Http\Controllers\API\Auth\GoogleController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\EmailVerificationController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\OtpController;
use App\Http\Controllers\API\RideController;
use App\Http\Controllers\API\ScoreController;
use App\Http\Controllers\API\TextMeOtpController;
use App\Http\Controllers\API\VerificationController;
use App\Http\Controllers\API\SignupController;
use App\Http\Controllers\API\LoginController;
use App\Http\Controllers\API\LogoutController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\ResetPasswordController;
use App\Http\Controllers\API\RefreshTokenController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/* |-------------------------------------------------------------------------- | API Routes |-------------------------------------------------------------------------- */

// Database connection test route
Route::get('test-db', function() {
    try {
        DB::connection()->getPdo();
        $tables = DB::select('SHOW TABLES');
        return response()->json([
            'message' => 'Database connection successful',
            'database' => config('database.connections.mysql.database'),
            'host' => config('database.connections.mysql.host'),
            'tables_count' => count($tables)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Database connection failed: ' . $e->getMessage(),
            'config' => [
                'host' => config('database.connections.mysql.host'),
                'database' => config('database.connections.mysql.database'),
                'port' => config('database.connections.mysql.port')
            ]
        ], 500);
    }
});

// Test route for basic API functionality
Route::get('/test', function() {
    return response()->json(['message' => 'API is working!', 'timestamp' => now()]);
});

// OTP routes (public)
Route::post('/otp/send', [OtpController::class, 'sendOtp']);
Route::post('/otp/verify', [OtpController::class, 'verifyOtp']);

Route::prefix('textme-otp')->group(function () {
    Route::post('/send', [TextMeOtpController::class, 'sendOtp']);
    Route::post('/verify', [TextMeOtpController::class, 'verifyOtp']);
});

// ========================================
// PUBLIC AUTHENTICATION ROUTES (JWT)
// ========================================
Route::prefix('auth')->group(function () {
    Route::post('/signup', [SignupController::class, 'register']);
    Route::post('/login', [LoginController::class, '__invoke']);
    Route::post('/forgot-password', [ForgotPasswordController::class, '__invoke']);
    Route::post('/reset-password', [ResetPasswordController::class, '__invoke']);
    Route::post('/refresh', RefreshTokenController::class);
});
Route::prefix('email-verification')->group(function () {
    // Public — no auth needed
    Route::post('/send',   [EmailVerificationController::class, 'send']);
    Route::post('/verify', [EmailVerificationController::class, 'verify']);
    Route::post('/resend', [EmailVerificationController::class, 'resend']);
});

// ========================================
// PROTECTED ROUTES (JWT AUTHENTICATION)
// ========================================
Route::middleware('jwt.auth')->group(function () {

    // User info & logout
    Route::get('/user', fn(Request $r) => response()->json([
        'status' => 'success',
        'user' => $r->user()
    ]));
    Route::post('/logout', [LogoutController::class, '__invoke']);

    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/{userId}', [ProfileController::class, 'show']);
        Route::post('/', [ProfileController::class, 'update']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::post('/verify/passenger', [VerificationController::class, 'verifyPassenger']);
        Route::post('/verify/driver', [VerificationController::class, 'verifyDriver']);
        Route::get('/verify/status/{userId}', [VerificationController::class, 'status']);
        Route::post('/{userId}/comments', [ProfileController::class, 'comment']);
        Route::post('/{userId}/rate', [ProfileController::class, 'rateUser']);
    });

    // Booking routes
    Route::prefix('bookings')->group(function () {
        Route::post('/{bookingId}/cancel-seats', [RideController::class, 'cancelPartialSeats']);
    });

    Route::get('/my-bookings', [RideController::class, 'getMyBookings']);

    // Ride routes
    Route::prefix('rides')->group(function () {
        Route::post('/', [RideController::class, 'createRide']);
        Route::get('/', [RideController::class, 'getRides']);
        Route::get('/{rideId}', [RideController::class, 'getRideDetails']);
        Route::post('/{rideId}/book', [RideController::class, 'bookRide']);
        Route::patch('/{ride}/cancel', [RideController::class, 'cancelRide']);
        Route::post('/search', [RideController::class, 'searchRides']);
        Route::post('/route-options', [RideController::class, 'getRouteOptions']);
        Route::post('/create-with-route', [RideController::class, 'createRideWithRoute']);
        Route::post('/{rideId}/finish', [RideController::class, 'finishRide']);
        Route::post('/{ride}/driver-confirm', [RideController::class, 'driverConfirmCompletion']);
        Route::post('/{booking}/passenger-confirm', [RideController::class, 'passengerConfirmCompletion']);
        Route::post('/{bookingId}/accept', [RideController::class, 'acceptBooking']);
        Route::post('/{bookingId}/reject', [RideController::class, 'rejectBooking']);
        Route::post('/bookings/{bookingId}/cancel-seat', [RideController::class, 'cancelSeat']);
    });

    // Autocomplete route (separate from rides)
    Route::get('/autocomplete', [RideController::class, 'autocomplete']);

    // Chat routes
    Route::prefix('chat')->group(function () {
        Route::get('/conversations', [ChatController::class, 'getConversations']);
        Route::post('/conversations', [ChatController::class, 'startConversation']);
        Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'getMessages']);
        Route::post('/conversations/{conversationId}/messages', [ChatController::class, 'sendMessage']);
        Route::delete('/messages/{messageId}', [ChatController::class, 'deleteMessage']);
    });

    // Notification routes
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::get('/categories', [NotificationController::class, 'getCategories']);
        Route::post('/bulk-action', [NotificationController::class, 'bulkAction']);
    });

    // Wallet routes
    Route::prefix('wallet')->group(function () {
        Route::post('/initiate', [WalletController::class, 'initiateWalletCreation']);
        Route::post('/verify-and-create', [WalletController::class, 'verifyAndCreateWallet']);
        Route::get('/balance', [WalletController::class, 'getBalance']);
    });
    Route::get('/score',         [ScoreController::class, 'show']);
    Route::get('/score/history', [ScoreController::class, 'history']);

    // --- No-show reporting ---
    Route::post('/bookings/{bookingId}/passenger-no-show', [RideController::class, 'reportPassengerNoShow']);
    Route::post('/rides/{rideId}/driver-no-show',          [RideController::class, 'reportDriverNoShow']);

});

// ========================================
// ADMIN ROUTES
// ========================================
Route::prefix('admin')->group(function () {

    // ----------------------------------------
    // Auth (public — no middleware)
    // ----------------------------------------
    Route::post('/login',   [AdminDashboardController::class, 'login']);
    Route::post('/refresh', [AdminDashboardController::class, 'refresh']);

    // ----------------------------------------
    // Protected admin routes
    // ----------------------------------------
    Route::middleware('auth.admin')->group(function () {

        // Auth
        Route::post('/logout', [AdminDashboardController::class, 'logout']);
        Route::post('/photo', [AdminDashboardController::class, 'uploadAdminPhoto']);
        // Dashboard — BFF endpoints
        Route::prefix('dashboard')->group(function () {
            Route::get('/',        [AdminDashboardController::class, 'dashboard']);       // Full payload (all widgets)
            Route::get('/stats',   [AdminDashboardController::class, 'dashboardStats']); // KPI cards only
            Route::get('/growth',  [AdminDashboardController::class, 'dashboardGrowth']); // Growth bar chart
            Route::get('/cities',  [AdminDashboardController::class, 'dashboardCities']); // City distribution
            Route::get('/recent',  [AdminDashboardController::class, 'dashboardRecent']); // Recent activities table
        });

        // Wallet — any admin
        Route::prefix('wallet')->group(function () {
            Route::get('/',                          [AdminDashboardController::class, 'getAdminWallet']);        // Own wallet
            Route::get('/{walletId}/transactions',   [AdminDashboardController::class, 'showWalletTransactions']); // Wallet transactions
        });
        Route::get('/wallets', [AdminDashboardController::class, 'getAdminWallets']); // All wallets overview

        // ----------------------------------------
        // Primary-admin-only routes
        // ----------------------------------------
        Route::middleware('auth.admin:primary')->group(function () {

            // Wallet — charge
            Route::post('/wallet/charge', [AdminDashboardController::class, 'chargeWallet']);
            Route::get('/export/pdf', [AdminDashboardController::class, 'exportPdf']);
            // Financial report
            Route::get('/reports', [AdminDashboardController::class, 'showReport']);

            // Verifications
            Route::prefix('verifications')->group(function () {
                Route::get('/',                        [AdminDashboardController::class, 'pendingVerifications']);   // List pending
                Route::post('/{userId}/approve',       [AdminDashboardController::class, 'approveVerification']);   // Approve
                Route::post('/{userId}/reject',        [AdminDashboardController::class, 'rejectVerification']);    // Reject
            });
        });
    });
});
