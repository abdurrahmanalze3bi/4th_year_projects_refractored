<?php

use App\Http\Controllers\API\AdminDashboardController;
use App\Http\Controllers\API\AdminDriverController;
use App\Http\Controllers\API\AdminTripController;
use App\Http\Controllers\API\AdminUserController;
use App\Http\Controllers\API\Auth\GoogleController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\EmailVerificationController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\LoginController;
use App\Http\Controllers\API\LogoutController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\OtpController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\PushNotificationController;
use App\Http\Controllers\API\RefreshTokenController;
use App\Http\Controllers\API\ResetPasswordController;
use App\Http\Controllers\API\RideController;
use App\Http\Controllers\API\ScoreController;
use App\Http\Controllers\API\SignupController;
use App\Http\Controllers\API\TextMeOtpController;
use App\Http\Controllers\API\VerificationController;
use App\Http\Controllers\API\VerifyPasswordOtpController;
use App\Http\Controllers\API\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ========================================
// UTILITY / DEBUG
// ========================================

Route::get('/test', fn () => response()->json([
    'message'   => 'API is working!',
    'timestamp' => now(),
]));

Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            'message'      => 'Database connection successful',
            'database'     => config('database.connections.mysql.database'),
            'host'         => config('database.connections.mysql.host'),
            'tables_count' => count(DB::select('SHOW TABLES')),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error'  => 'Database connection failed: ' . $e->getMessage(),
            'config' => [
                'host'     => config('database.connections.mysql.host'),
                'database' => config('database.connections.mysql.database'),
                'port'     => config('database.connections.mysql.port'),
            ],
        ], 500);
    }
});

// ========================================
// PUBLIC — OTP (WhatsApp / TextMeBot)
// ========================================

Route::prefix('otp')->group(function () {
    Route::post('/send',   [OtpController::class, 'sendOtp']);
    Route::post('/verify', [OtpController::class, 'verifyOtp']);
});

Route::prefix('textme-otp')->group(function () {
    Route::post('/send',   [TextMeOtpController::class, 'sendOtp']);
    Route::post('/verify', [TextMeOtpController::class, 'verifyOtp']);
});

// ========================================
// PUBLIC — AUTHENTICATION
// ========================================

Route::prefix('auth')->group(function () {

    Route::post('/signup',  [SignupController::class, 'register']);
    Route::post('/login',   LoginController::class);
    Route::post('/refresh', RefreshTokenController::class);

    // ── Password reset (3-step OTP flow) ──────────────────────────────────
    // Step 1: send OTP to email
    Route::post('/password/forgot',      ForgotPasswordController::class);

    // Step 2: verify OTP → receive reset_token (valid 15 min, single-use)
    Route::post('/password/verify-otp',  VerifyPasswordOtpController::class);

    // Step 3: submit new password using reset_token
    Route::post('/password/reset',       ResetPasswordController::class);
});

// ========================================
// PUBLIC — EMAIL VERIFICATION
// ========================================

Route::prefix('email-verification')->group(function () {
    Route::post('/send',   [EmailVerificationController::class, 'send']);
    Route::post('/verify', [EmailVerificationController::class, 'verify']);
    Route::post('/resend', [EmailVerificationController::class, 'resend']);
});

// ========================================
// PROTECTED — JWT AUTH REQUIRED
// ========================================

Route::middleware('jwt')->group(function () {

    // ── Session ───────────────────────────────────────────────────────────
    Route::get('/user', fn (Request $r) => response()->json([
        'status' => 'success',
        'user'   => $r->user(),
    ]));
    Route::post('/logout', LogoutController::class);

    // ── Score ─────────────────────────────────────────────────────────────
    Route::prefix('score')->group(function () {
        Route::get('/',        [ScoreController::class, 'show']);
        Route::get('/history', [ScoreController::class, 'history']);
    });

    // ── Autocomplete ──────────────────────────────────────────────────────
    Route::get('/autocomplete', [RideController::class, 'autocomplete']);

    // ── Profile ───────────────────────────────────────────────────────────
    Route::prefix('profile')->group(function () {
        Route::post('/',      [ProfileController::class, 'update']);
        Route::post('/documents', [DocumentController::class, 'store']);

        // Verification requests
        Route::prefix('verify')->group(function () {
            Route::post('/passenger',      [VerificationController::class, 'verifyPassenger']);
            Route::post('/driver',         [VerificationController::class, 'verifyDriver']);
            Route::get('/status/{userId}', [VerificationController::class, 'status']);
        });

        // Dynamic userId last — avoids clashing with static segments above
        Route::get('/{userId}',          [ProfileController::class, 'show']);
        Route::post('/{userId}/comments', [ProfileController::class, 'comment']);
        Route::post('/{userId}/rate',     [ProfileController::class, 'rateUser']);
    });

    // ── Rides ─────────────────────────────────────────────────────────────
    // IMPORTANT: static segments (/search, /route-options, /create-with-route)
    // must be declared BEFORE dynamic segments (/{rideId}) to prevent Laravel
    // from routing "search" as a rideId.
    Route::prefix('rides')->group(function () {

        // -- Static routes first --
        Route::get('/search',             [RideController::class, 'searchRides']);
        Route::post('/search',            [RideController::class, 'searchRides']);
        Route::post('/route-options',     [RideController::class, 'getRouteOptions']);
        Route::post('/create-with-route', [RideController::class, 'createRideWithRoute']);

        // -- Collection --
        Route::get('/',  [RideController::class, 'getRides']);
        Route::post('/', [RideController::class, 'createRide']);

        // -- Dynamic routes last --
        Route::get('/{rideId}',                    [RideController::class, 'getRideDetails']);
        Route::patch('/{rideId}/cancel',           [RideController::class, 'cancelRide']);
        Route::post('/{rideId}/book',              [RideController::class, 'bookRide']);
        Route::post('/{rideId}/finish',            [RideController::class, 'finishRide']);
        Route::post('/{rideId}/driver-confirm',    [RideController::class, 'driverConfirmCompletion']);
        Route::post('/{rideId}/driver-no-show',    [RideController::class, 'reportDriverNoShow']);
    });

    // ── Bookings ──────────────────────────────────────────────────────────
    Route::prefix('bookings')->group(function () {
        Route::get('/',                                  [RideController::class, 'getMyBookings']);
        Route::post('/{bookingId}/accept',               [RideController::class, 'acceptBooking']);
        Route::post('/{bookingId}/reject',               [RideController::class, 'rejectBooking']);
        Route::post('/{bookingId}/cancel',               [RideController::class, 'cancelBooking']);
        Route::post('/{bookingId}/cancel-seats',         [RideController::class, 'cancelPartialSeats']);
        Route::post('/{bookingId}/passenger-confirm',    [RideController::class, 'passengerConfirmCompletion']);
        Route::post('/{bookingId}/passenger-no-show',    [RideController::class, 'reportPassengerNoShow']);
    });

    // ── Chat ──────────────────────────────────────────────────────────────
    Route::prefix('chat')->group(function () {
        Route::get('/conversations',                              [ChatController::class, 'getConversations']);
        Route::post('/conversations',                             [ChatController::class, 'startConversation']);
        Route::get('/conversations/{conversationId}/messages',    [ChatController::class, 'getMessages']);
        Route::post('/conversations/{conversationId}/messages',   [ChatController::class, 'sendMessage']);
        Route::delete('/messages/{messageId}',                    [ChatController::class, 'deleteMessage']);
    });

    // ── Notifications ─────────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/',                  [NotificationController::class, 'index']);
        Route::get('/unread-count',      [NotificationController::class, 'getUnreadCount']);
        Route::get('/categories',        [NotificationController::class, 'getCategories']);
        Route::post('/read-all',         [NotificationController::class, 'markAllAsRead']);
        Route::post('/bulk-action',      [NotificationController::class, 'bulkAction']);
        Route::post('/{id}/read',        [NotificationController::class, 'markAsRead']);
        Route::post('/{id}/unread',      [NotificationController::class, 'markAsUnread']);
        Route::delete('/{id}',           [NotificationController::class, 'destroy']);
    });

    // ── Wallet ────────────────────────────────────────────────────────────
    Route::prefix('wallet')->group(function () {
        Route::get('/balance',           [WalletController::class, 'getBalance']);
        Route::post('/initiate',         [WalletController::class, 'initiateWalletCreation']);
        Route::post('/verify-and-create',[WalletController::class, 'verifyAndCreateWallet']);
    });

});

// ========================================
// ADMIN ROUTES
// ========================================

Route::prefix('admin')->group(function () {
    Route::get('users', [AdminUserController::class, 'index']);
   /* GET /api/admin/users
GET /api/admin/users?type=driver
    GET /api/admin/users?type=passenger&status=pending
        GET /api/admin/users?status=verified&date=last_30_days
            GET /api/admin/users?type=driver&status=all&date=last_6_months&search=ahmed*/
    Route::get('drivers/verification-efficiency', [AdminDriverController::class, 'verificationEfficiency']);
    Route::get('drivers/dashboard',  [AdminDriverController::class, 'dashboard']);

//  Individual component refresh endpoints
    Route::get('drivers/stats',      [AdminDriverController::class, 'stats']);
    Route::get('drivers/activity',   [AdminDriverController::class, 'activity']);

//  Driver table (paginated, filterable)
    Route::get('drivers',            [AdminDriverController::class, 'index']);

//  Single driver profile / detail view
    //  Single driver profile / detail view
    Route::get('drivers/{driverId}/profile',   [AdminDriverController::class, 'driverProfile']);
    Route::get('drivers/{driverId}/dashboard', [AdminDriverController::class, 'driverDashboard']);
    // ── Public admin auth ─────────────────────────────────────────────────
    Route::post('/login',   [AdminDashboardController::class, 'login']);
    Route::post('/refresh', [AdminDashboardController::class, 'refresh']);

    // ── Protected — any admin ─────────────────────────────────────────────
    Route::middleware('auth.admin')->group(function () {

        Route::post('/logout', [AdminDashboardController::class, 'logout']);
        Route::post('/photo',  [AdminDashboardController::class, 'uploadAdminPhoto']);

        // Dashboard widgets
        Route::prefix('dashboard')->group(function () {
            Route::get('/',       [AdminDashboardController::class, 'dashboard']);
            Route::get('/stats',  [AdminDashboardController::class, 'dashboardStats']);
            Route::get('/growth', [AdminDashboardController::class, 'dashboardGrowth']);
            Route::get('/cities', [AdminDashboardController::class, 'dashboardCities']);
            Route::get('/recent', [AdminDashboardController::class, 'dashboardRecent']);
        });

        // Trips
        Route::prefix('trips')->group(function () {
            Route::get('/live', [AdminTripController::class, 'live']);
            Route::get('/',     [AdminTripController::class, 'index']);
        });
        Route::get('/routes/popular', [AdminTripController::class, 'popularRoutes']);
        Route::get('/drivers/top',    [AdminTripController::class, 'topDrivers']);

        // Wallet — read (any admin)
        Route::prefix('wallet')->group(function () {
            Route::get('/',                        [AdminDashboardController::class, 'getAdminWallet']);
            Route::get('/{walletId}/transactions', [AdminDashboardController::class, 'showWalletTransactions']);
        });
        Route::get('/wallets', [AdminDashboardController::class, 'getAdminWallets']);

        // ── Primary admin only ────────────────────────────────────────────
        Route::middleware('auth.admin:primary')->group(function () {

            Route::post('/wallet/charge', [AdminDashboardController::class, 'chargeWallet']);
            Route::get('/export/pdf',     [AdminDashboardController::class, 'exportPdf']);
            Route::get('/reports',        [AdminDashboardController::class, 'showReport']);

            Route::prefix('verifications')->group(function () {
                Route::get('/',                  [AdminDashboardController::class, 'pendingVerifications']);
                Route::post('/{userId}/approve', [AdminDashboardController::class, 'approveVerification']);
                Route::post('/{userId}/reject',  [AdminDashboardController::class, 'rejectVerification']);
            });
        });
    });
});
