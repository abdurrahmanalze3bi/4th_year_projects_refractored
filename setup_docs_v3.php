<?php
/**
 * setup_docs_v3.php — built from the actual api.php routes.
 * Drops and recreates all app/Docs/ files.
 *
 * Usage:  php setup_docs_v3.php
 * Delete: after it prints "Done"
 */

$base = __DIR__ . '/app/Docs';

if (is_dir($base)) {
    foreach (glob("$base/*.php") as $f) unlink($f);
} else {
    mkdir($base, 0755, true);
}

$ok = 0; $fail = 0;

function w(string $path, string $src): void {
    global $ok, $fail;
    if (file_put_contents($path, $src) !== false) {
        echo "  OK  " . basename($path) . "\n"; $ok++;
    } else {
        echo "  !!  " . basename($path) . " — FAILED (check permissions)\n"; $fail++;
    }
}

echo "\nSyRide — regenerating app/Docs/ from api.php\n";
echo "---------------------------------------------------\n\n";

/* =============================================================================
   1. ApiInfo.php
   ============================================================================ */
w("$base/ApiInfo.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Info(
 *     title="SyRide API",
 *     version="1.0.0",
 *     description="SyRide Ride-Sharing Platform – Full API Reference"
 * )
 *
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="Local Development Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class ApiInfo {}
SDOC);

/* =============================================================================
   2. AuthDocs.php — OTP · TextMeOTP · Email Verification · Auth · Session
   ============================================================================ */
w("$base/AuthDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * ── OTP (WhatsApp) ────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/otp/send",
 *     operationId="otpSend",
 *     tags={"OTP"},
 *     summary="Send OTP via WhatsApp",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"phone"},
 *             @OA\Property(property="phone", type="string", example="+9627XXXXXXXX")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP sent")
 * )
 *
 * @OA\Post(
 *     path="/api/otp/verify",
 *     operationId="otpVerify",
 *     tags={"OTP"},
 *     summary="Verify WhatsApp OTP",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"phone","otp"},
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="otp",   type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP verified")
 * )
 *
 * ── OTP (TextMeBot) ───────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/textme-otp/send",
 *     operationId="textmeOtpSend",
 *     tags={"OTP"},
 *     summary="Send OTP via TextMeBot",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"phone"},
 *             @OA\Property(property="phone", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP sent")
 * )
 *
 * @OA\Post(
 *     path="/api/textme-otp/verify",
 *     operationId="textmeOtpVerify",
 *     tags={"OTP"},
 *     summary="Verify TextMeBot OTP",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"phone","otp"},
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="otp",   type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP verified")
 * )
 *
 * ── Email Verification ────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/email-verification/send",
 *     operationId="emailVerifSend",
 *     tags={"Email Verification"},
 *     summary="Send email verification code",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email"},
 *             @OA\Property(property="email", type="string", format="email")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Code sent")
 * )
 *
 * @OA\Post(
 *     path="/api/email-verification/verify",
 *     operationId="emailVerifVerify",
 *     tags={"Email Verification"},
 *     summary="Verify the emailed code",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email","code"},
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="code",  type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Email verified")
 * )
 *
 * @OA\Post(
 *     path="/api/email-verification/resend",
 *     operationId="emailVerifResend",
 *     tags={"Email Verification"},
 *     summary="Resend email verification code",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email"},
 *             @OA\Property(property="email", type="string", format="email")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Code resent")
 * )
 *
 * ── Auth ──────────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/auth/signup",
 *     operationId="authSignup",
 *     tags={"Authentication"},
 *     summary="Register a new user",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             required={"name","email","password","password_confirmation","phone"},
 *             @OA\Property(property="name",                  type="string"),
 *             @OA\Property(property="email",                 type="string", format="email"),
 *             @OA\Property(property="password",              type="string", format="password"),
 *             @OA\Property(property="password_confirmation", type="string", format="password"),
 *             @OA\Property(property="phone",                 type="string")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Registered – returns tokens"),
 *     @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/login",
 *     operationId="authLogin",
 *     tags={"Authentication"},
 *     summary="Login and receive JWT tokens",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email","password"},
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Returns access_token + refresh_token"),
 *     @OA\Response(response=401, description="Invalid credentials")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/refresh",
 *     operationId="authRefresh",
 *     tags={"Authentication"},
 *     summary="Refresh JWT access token",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="New access token")
 * )
 *
 * ── Password Reset (3-step OTP flow) ─────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/auth/password/forgot",
 *     operationId="passwordForgot",
 *     tags={"Authentication"},
 *     summary="Step 1 – request password-reset OTP",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email"},
 *             @OA\Property(property="email", type="string", format="email")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP dispatched to email")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/password/verify-otp",
 *     operationId="passwordVerifyOtp",
 *     tags={"Authentication"},
 *     summary="Step 2 – verify OTP and receive reset token",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email","otp"},
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="otp",   type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Returns reset_token")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/password/reset",
 *     operationId="passwordReset",
 *     tags={"Authentication"},
 *     summary="Step 3 – set new password using reset token",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"reset_token","password","password_confirmation"},
 *             @OA\Property(property="reset_token",           type="string"),
 *             @OA\Property(property="password",              type="string", format="password"),
 *             @OA\Property(property="password_confirmation", type="string", format="password")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Password updated")
 * )
 *
 * ── Session ───────────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/user",
 *     operationId="authUser",
 *     tags={"Authentication"},
 *     summary="Get authenticated user",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="User object"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/logout",
 *     operationId="authLogout",
 *     tags={"Authentication"},
 *     summary="Logout (invalidate JWT)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Logged out"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class AuthDocs {}
SDOC);

/* =============================================================================
   3. ProfileDocs.php — Profile · Documents · Verification · Score · Autocomplete
   ============================================================================ */
w("$base/ProfileDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * ── Score ─────────────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/score",
 *     operationId="scoreShow",
 *     tags={"Profile"},
 *     summary="Get current user's score",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Score object"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *     path="/api/score/history",
 *     operationId="scoreHistory",
 *     tags={"Profile"},
 *     summary="Get score history",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Score history list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── Autocomplete ──────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/autocomplete",
 *     operationId="autocomplete",
 *     tags={"Rides"},
 *     summary="Location autocomplete suggestions",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="query", in="query", required=true,
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\Response(response=200, description="Location suggestions"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── Profile ───────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/profile",
 *     operationId="profileUpdate",
 *     tags={"Profile"},
 *     summary="Update own profile",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=false,
 *         @OA\MediaType(mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 @OA\Property(property="name",   type="string"),
 *                 @OA\Property(property="phone",  type="string"),
 *                 @OA\Property(property="avatar", type="string", format="binary")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=200, description="Profile updated"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/documents",
 *     operationId="profileDocuments",
 *     tags={"Profile"},
 *     summary="Upload verification documents",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\MediaType(mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 @OA\Property(property="document_type", type="string",
 *                     enum={"id_card","driver_license","vehicle_registration"}),
 *                 @OA\Property(property="front", type="string", format="binary"),
 *                 @OA\Property(property="back",  type="string", format="binary")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=200, description="Documents uploaded"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── Verification ──────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/profile/verify/passenger",
 *     operationId="verifyPassenger",
 *     tags={"Profile"},
 *     summary="Submit passenger verification request",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Verification submitted"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/verify/driver",
 *     operationId="verifyDriver",
 *     tags={"Profile"},
 *     summary="Submit driver verification request",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Verification submitted"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *     path="/api/profile/verify/status/{userId}",
 *     operationId="verifyStatus",
 *     tags={"Profile"},
 *     summary="Get verification status for a user",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Verification status"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * ── User Profile ──────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/profile/{userId}",
 *     operationId="profileShow",
 *     tags={"Profile"},
 *     summary="Get a user's public profile",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="User profile"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/{userId}/comments",
 *     operationId="profileComment",
 *     tags={"Profile"},
 *     summary="Post a comment on a user's profile",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"comment"},
 *             @OA\Property(property="comment", type="string")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Comment posted"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/profile/{userId}/rate",
 *     operationId="profileRate",
 *     tags={"Profile"},
 *     summary="Rate a user",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"rating"},
 *             @OA\Property(property="rating",  type="number", minimum=1, maximum=5),
 *             @OA\Property(property="comment", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Rating saved"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class ProfileDocs {}
SDOC);

/* =============================================================================
   4. RideDocs.php
   ============================================================================ */
w("$base/RideDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/rides/search",
 *     operationId="ridesSearchGet",
 *     tags={"Rides"},
 *     summary="Search rides (GET)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="from",  in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="to",    in="query", @OA\Schema(type="string")),
 *     @OA\Parameter(name="date",  in="query", @OA\Schema(type="string", format="date")),
 *     @OA\Parameter(name="seats", in="query", @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Matching rides")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/search",
 *     operationId="ridesSearchPost",
 *     tags={"Rides"},
 *     summary="Search rides (POST)",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="from",  type="string"),
 *             @OA\Property(property="to",    type="string"),
 *             @OA\Property(property="date",  type="string", format="date"),
 *             @OA\Property(property="seats", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Matching rides")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/route-options",
 *     operationId="ridesRouteOptions",
 *     tags={"Rides"},
 *     summary="Get route options before creating a ride",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"from","to"},
 *             @OA\Property(property="from", type="string"),
 *             @OA\Property(property="to",   type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Route options returned")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/create-with-route",
 *     operationId="ridesCreateWithRoute",
 *     tags={"Rides"},
 *     summary="Create a ride with a pre-selected route",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             required={"from","to","date","seats","price"},
 *             @OA\Property(property="from",  type="string"),
 *             @OA\Property(property="to",    type="string"),
 *             @OA\Property(property="date",  type="string", format="date-time"),
 *             @OA\Property(property="seats", type="integer"),
 *             @OA\Property(property="price", type="number"),
 *             @OA\Property(property="route", type="object",
 *                 description="Route object returned from route-options")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Ride created")
 * )
 *
 * @OA\Get(
 *     path="/api/rides",
 *     operationId="ridesList",
 *     tags={"Rides"},
 *     summary="List current user's rides",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Rides list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/rides",
 *     operationId="ridesCreate",
 *     tags={"Rides"},
 *     summary="Create a new ride",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             required={"from","to","date","seats","price"},
 *             @OA\Property(property="from",   type="string"),
 *             @OA\Property(property="to",     type="string"),
 *             @OA\Property(property="date",   type="string", format="date-time"),
 *             @OA\Property(property="seats",  type="integer"),
 *             @OA\Property(property="price",  type="number")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Ride created"),
 *     @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Get(
 *     path="/api/rides/{rideId}",
 *     operationId="ridesShow",
 *     tags={"Rides"},
 *     summary="Get ride details",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Ride details"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Patch(
 *     path="/api/rides/{rideId}/cancel",
 *     operationId="ridesCancel",
 *     tags={"Rides"},
 *     summary="Cancel a ride (driver)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Ride cancelled"),
 *     @OA\Response(response=403, description="Forbidden")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/{rideId}/book",
 *     operationId="ridesBook",
 *     tags={"Rides"},
 *     summary="Book a ride (passenger)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"seats"},
 *             @OA\Property(property="seats", type="integer")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Booking created")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/{rideId}/finish",
 *     operationId="ridesFinish",
 *     tags={"Rides"},
 *     summary="Mark a ride as finished (driver)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Ride finished")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/{rideId}/driver-confirm",
 *     operationId="ridesDriverConfirm",
 *     tags={"Rides"},
 *     summary="Driver confirms ride completion",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Confirmed")
 * )
 *
 * @OA\Post(
 *     path="/api/rides/{rideId}/driver-no-show",
 *     operationId="ridesDriverNoShow",
 *     tags={"Rides"},
 *     summary="Report driver no-show (passenger)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="rideId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Report submitted")
 * )
 */
class RideDocs {}
SDOC);

/* =============================================================================
   5. BookingDocs.php
   ============================================================================ */
w("$base/BookingDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/bookings",
 *     operationId="bookingsList",
 *     tags={"Bookings"},
 *     summary="List current user's bookings",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Bookings list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/accept",
 *     operationId="bookingsAccept",
 *     tags={"Bookings"},
 *     summary="Accept a booking request (driver)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Booking accepted")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/reject",
 *     operationId="bookingsReject",
 *     tags={"Bookings"},
 *     summary="Reject a booking request (driver)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Booking rejected")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/cancel",
 *     operationId="bookingsCancel",
 *     tags={"Bookings"},
 *     summary="Cancel a booking (passenger)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Booking cancelled")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/cancel-seats",
 *     operationId="bookingsCancelSeats",
 *     tags={"Bookings"},
 *     summary="Cancel partial seats in a booking",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"seats"},
 *             @OA\Property(property="seats", type="integer", minimum=1)
 *         )
 *     ),
 *     @OA\Response(response=200, description="Seats cancelled")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/passenger-confirm",
 *     operationId="bookingsPassengerConfirm",
 *     tags={"Bookings"},
 *     summary="Passenger confirms ride completion",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Confirmed")
 * )
 *
 * @OA\Post(
 *     path="/api/bookings/{bookingId}/passenger-no-show",
 *     operationId="bookingsPassengerNoShow",
 *     tags={"Bookings"},
 *     summary="Report passenger no-show (driver)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="bookingId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Report submitted")
 * )
 */
class BookingDocs {}
SDOC);

/* =============================================================================
   6. ChatDocs.php
   ============================================================================ */
w("$base/ChatDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/chat/conversations",
 *     operationId="chatConversations",
 *     tags={"Chat"},
 *     summary="List conversations",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Conversation list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/chat/conversations",
 *     operationId="chatStartConversation",
 *     tags={"Chat"},
 *     summary="Start or retrieve a conversation",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"user_id"},
 *             @OA\Property(property="user_id", type="integer"),
 *             @OA\Property(property="ride_id", type="integer", nullable=true)
 *         )
 *     ),
 *     @OA\Response(response=200, description="Conversation object")
 * )
 *
 * @OA\Get(
 *     path="/api/chat/conversations/{conversationId}/messages",
 *     operationId="chatMessages",
 *     tags={"Chat"},
 *     summary="List messages in a conversation",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="conversationId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Messages list"),
 *     @OA\Response(response=403, description="Forbidden")
 * )
 *
 * @OA\Post(
 *     path="/api/chat/conversations/{conversationId}/messages",
 *     operationId="chatSendMessage",
 *     tags={"Chat"},
 *     summary="Send a message",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="conversationId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"message"},
 *             @OA\Property(property="message", type="string")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Message sent")
 * )
 *
 * @OA\Delete(
 *     path="/api/chat/messages/{messageId}",
 *     operationId="chatDeleteMessage",
 *     tags={"Chat"},
 *     summary="Delete a message",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="messageId", in="path", required=true,
 *         @OA\Schema(type="integer")
 *     ),
 *     @OA\Response(response=200, description="Deleted"),
 *     @OA\Response(response=403, description="Forbidden")
 * )
 */
class ChatDocs {}
SDOC);

/* =============================================================================
   7. NotificationDocs.php  (NEW — was entirely missing)
   ============================================================================ */
w("$base/NotificationDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/notifications",
 *     operationId="notifList",
 *     tags={"Notifications"},
 *     summary="List notifications",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Notification list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *     path="/api/notifications/unread-count",
 *     operationId="notifUnreadCount",
 *     tags={"Notifications"},
 *     summary="Get unread notification count",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Unread count")
 * )
 *
 * @OA\Get(
 *     path="/api/notifications/categories",
 *     operationId="notifCategories",
 *     tags={"Notifications"},
 *     summary="Get notification categories",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Categories list")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/read-all",
 *     operationId="notifReadAll",
 *     tags={"Notifications"},
 *     summary="Mark all notifications as read",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="All marked as read")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/bulk-action",
 *     operationId="notifBulkAction",
 *     tags={"Notifications"},
 *     summary="Perform bulk action on notifications",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"action","ids"},
 *             @OA\Property(property="action", type="string", enum={"read","unread","delete"}),
 *             @OA\Property(property="ids", type="array", @OA\Items(type="integer"))
 *         )
 *     ),
 *     @OA\Response(response=200, description="Action performed")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/{id}/read",
 *     operationId="notifRead",
 *     tags={"Notifications"},
 *     summary="Mark a notification as read",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Marked as read")
 * )
 *
 * @OA\Post(
 *     path="/api/notifications/{id}/unread",
 *     operationId="notifUnread",
 *     tags={"Notifications"},
 *     summary="Mark a notification as unread",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Marked as unread")
 * )
 *
 * @OA\Delete(
 *     path="/api/notifications/{id}",
 *     operationId="notifDelete",
 *     tags={"Notifications"},
 *     summary="Delete a notification",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Deleted")
 * )
 */
class NotificationDocs {}
SDOC);

/* =============================================================================
   8. WalletDocs.php
   ============================================================================ */
w("$base/WalletDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/wallet/balance",
 *     operationId="walletBalance",
 *     tags={"Wallet"},
 *     summary="Get wallet balance",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Balance object"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/initiate",
 *     operationId="walletInitiate",
 *     tags={"Wallet"},
 *     summary="Initiate wallet creation (sends OTP)",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"phone"},
 *             @OA\Property(property="phone", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="OTP sent for wallet creation")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/verify-and-create",
 *     operationId="walletVerifyCreate",
 *     tags={"Wallet"},
 *     summary="Verify OTP and create wallet",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"phone","otp"},
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="otp",   type="string")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Wallet created")
 * )
 *
 * @OA\Get(
 *     path="/api/wallet/requests",
 *     operationId="walletRequests",
 *     tags={"Wallet"},
 *     summary="List own wallet requests",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Requests list")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/request-charge",
 *     operationId="walletRequestCharge",
 *     tags={"Wallet"},
 *     summary="Request a wallet top-up",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"amount"},
 *             @OA\Property(property="amount", type="number"),
 *             @OA\Property(property="notes",  type="string")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Charge request created")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/request-withdraw",
 *     operationId="walletRequestWithdraw",
 *     tags={"Wallet"},
 *     summary="Request a wallet withdrawal",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"amount"},
 *             @OA\Property(property="amount", type="number"),
 *             @OA\Property(property="notes",  type="string")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Withdrawal request created")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/requests",
 *     operationId="walletRequestStore",
 *     tags={"Wallet"},
 *     summary="Generic wallet request store",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"type","amount"},
 *             @OA\Property(property="type",   type="string", enum={"charge","withdraw"}),
 *             @OA\Property(property="amount", type="number")
 *         )
 *     ),
 *     @OA\Response(response=201, description="Request created")
 * )
 *
 * @OA\Get(
 *     path="/api/wallet/requests/{id}",
 *     operationId="walletRequestShow",
 *     tags={"Wallet"},
 *     summary="Get a specific wallet request",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Request object"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Delete(
 *     path="/api/wallet/requests/{id}",
 *     operationId="walletRequestDelete",
 *     tags={"Wallet"},
 *     summary="Delete a wallet request",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Deleted")
 * )
 */
class WalletDocs {}
SDOC);

/* =============================================================================
   9. MiscDocs.php — Complaints · Contact
   ============================================================================ */
w("$base/MiscDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/complaints",
 *     operationId="complaintsList",
 *     tags={"Complaints"},
 *     summary="List own complaints",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Complaints list"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/complaints",
 *     operationId="complaintsStore",
 *     tags={"Complaints"},
 *     summary="Submit a complaint",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             required={"subject","body"},
 *             @OA\Property(property="subject",       type="string"),
 *             @OA\Property(property="body",          type="string"),
 *             @OA\Property(property="ride_id",       type="integer", nullable=true),
 *             @OA\Property(property="complained_id", type="integer", nullable=true)
 *         )
 *     ),
 *     @OA\Response(response=201, description="Complaint created")
 * )
 *
 * @OA\Get(
 *     path="/api/complaints/{id}",
 *     operationId="complaintsShow",
 *     tags={"Complaints"},
 *     summary="Get a specific complaint",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Complaint object"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Post(
 *     path="/api/contact",
 *     operationId="contactStore",
 *     tags={"Complaints"},
 *     summary="Send a contact / support message",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"name","email","message"},
 *             @OA\Property(property="name",    type="string"),
 *             @OA\Property(property="email",   type="string", format="email"),
 *             @OA\Property(property="message", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Message sent")
 * )
 */
class MiscDocs {}
SDOC);

/* =============================================================================
   10. AdminDocs.php
   ============================================================================ */
w("$base/AdminDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * ── Admin Auth ────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/admin/login",
 *     operationId="adminLogin",
 *     tags={"Admin – Auth"},
 *     summary="Admin login",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email","password"},
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Admin JWT returned"),
 *     @OA\Response(response=401, description="Invalid credentials")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/refresh",
 *     operationId="adminRefresh",
 *     tags={"Admin – Auth"},
 *     summary="Refresh admin JWT",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="New token")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/logout",
 *     operationId="adminLogout",
 *     tags={"Admin – Auth"},
 *     summary="Admin logout",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Logged out")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/photo",
 *     operationId="adminUploadPhoto",
 *     tags={"Admin – Auth"},
 *     summary="Upload admin profile photo",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\MediaType(mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 @OA\Property(property="photo", type="string", format="binary")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=200, description="Photo uploaded")
 * )
 *
 * ── Admin Dashboard ───────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/dashboard",
 *     operationId="adminDashboard",
 *     tags={"Admin – Dashboard"},
 *     summary="Main admin dashboard",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Dashboard data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/stats",
 *     operationId="adminDashboardStats",
 *     tags={"Admin – Dashboard"},
 *     summary="Dashboard statistics",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/growth",
 *     operationId="adminDashboardGrowth",
 *     tags={"Admin – Dashboard"},
 *     summary="Dashboard growth metrics",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Growth data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/cities",
 *     operationId="adminDashboardCities",
 *     tags={"Admin – Dashboard"},
 *     summary="City breakdown",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Cities data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/recent",
 *     operationId="adminDashboardRecent",
 *     tags={"Admin – Dashboard"},
 *     summary="Recent activity",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Recent activity")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/export/pdf",
 *     operationId="adminExportPdf",
 *     tags={"Admin – Dashboard"},
 *     summary="[system_admin] Export report as PDF",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="PDF file")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/reports",
 *     operationId="adminReports",
 *     tags={"Admin – Dashboard"},
 *     summary="[system_admin] Show system reports",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Reports data")
 * )
 *
 * ── Admin Users ───────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/users",
 *     operationId="adminUsers",
 *     tags={"Admin – Users"},
 *     summary="List all users",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Users list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/users/{userId}/status",
 *     operationId="adminUserStatus",
 *     tags={"Admin – Users"},
 *     summary="Get user ban status",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="User status")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/users/{userId}/ban",
 *     operationId="adminUserBan",
 *     tags={"Admin – Users"},
 *     summary="Ban a user",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=false,
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *     @OA\Response(response=200, description="User banned")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/users/{userId}/unban",
 *     operationId="adminUserUnban",
 *     tags={"Admin – Users"},
 *     summary="Unban a user",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="User unbanned")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/verifications",
 *     operationId="adminVerifications",
 *     tags={"Admin – Users"},
 *     summary="[system_admin] List pending verifications",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Verifications list")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/verifications/{userId}/approve",
 *     operationId="adminVerifApprove",
 *     tags={"Admin – Users"},
 *     summary="[system_admin] Approve a verification",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Approved")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/verifications/{userId}/reject",
 *     operationId="adminVerifReject",
 *     tags={"Admin – Users"},
 *     summary="[system_admin] Reject a verification",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=false,
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *     @OA\Response(response=200, description="Rejected")
 * )
 *
 * ── Admin Passenger Profiles ──────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/full-profile",
 *     operationId="adminPassengerFullProfile",
 *     tags={"Admin – Users"},
 *     summary="Passenger full profile (BFF – all sections in one call)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Full profile data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/stats",
 *     operationId="adminPassengerStats",
 *     tags={"Admin – Users"},
 *     summary="Passenger stats",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/monthly-trips",
 *     operationId="adminPassengerMonthlyTrips",
 *     tags={"Admin – Users"},
 *     summary="Passenger monthly trip breakdown",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Monthly trips")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/recent-trips",
 *     operationId="adminPassengerRecentTrips",
 *     tags={"Admin – Users"},
 *     summary="Passenger recent trips",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Recent trips")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/complaints",
 *     operationId="adminPassengerComplaints",
 *     tags={"Admin – Users"},
 *     summary="Passenger complaints",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Complaints list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/passengers/{userId}/wallet-charges",
 *     operationId="adminPassengerWalletCharges",
 *     tags={"Admin – Users"},
 *     summary="Passenger wallet charge history",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Charges list")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/passengers/{userId}/charge-wallet",
 *     operationId="adminPassengerChargeWallet",
 *     tags={"Admin – Users"},
 *     summary="Charge a passenger's wallet directly",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"amount"},
 *             @OA\Property(property="amount", type="number"),
 *             @OA\Property(property="notes",  type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Wallet charged")
 * )
 *
 * ── Admin Drivers ─────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/drivers",
 *     operationId="adminDrivers",
 *     tags={"Admin – Drivers"},
 *     summary="List all drivers",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Drivers list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/verification-efficiency",
 *     operationId="adminDriverVerifEfficiency",
 *     tags={"Admin – Drivers"},
 *     summary="Driver verification efficiency stats",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/dashboard",
 *     operationId="adminDriverDashboard",
 *     tags={"Admin – Drivers"},
 *     summary="Drivers dashboard overview",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Dashboard data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/stats",
 *     operationId="adminDriverStats",
 *     tags={"Admin – Drivers"},
 *     summary="Driver statistics",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/activity",
 *     operationId="adminDriverActivity",
 *     tags={"Admin – Drivers"},
 *     summary="Driver activity log",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Activity data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/top",
 *     operationId="adminDriversTop",
 *     tags={"Admin – Drivers"},
 *     summary="Top performing drivers",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Drivers list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/{driverId}/profile",
 *     operationId="adminDriverProfile",
 *     tags={"Admin – Drivers"},
 *     summary="Get driver's full profile",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="driverId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Driver profile")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/drivers/{driverId}/dashboard",
 *     operationId="adminDriverDashboardById",
 *     tags={"Admin – Drivers"},
 *     summary="Driver dashboard by ID",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="driverId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Driver dashboard")
 * )
 *
 * ── Admin Trips ───────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/trips",
 *     operationId="adminTrips",
 *     tags={"Admin – Trips"},
 *     summary="List all trips",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Trips list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/trips/live",
 *     operationId="adminTripsLive",
 *     tags={"Admin – Trips"},
 *     summary="List live / active trips",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Live trips")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/routes/popular",
 *     operationId="adminRoutesPopular",
 *     tags={"Admin – Trips"},
 *     summary="Most popular routes",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Routes list")
 * )
 *
 * ── Admin Wallet ──────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/wallet",
 *     operationId="adminWallet",
 *     tags={"Admin – Wallet"},
 *     summary="Get admin wallet",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Admin wallet")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/wallets",
 *     operationId="adminWallets",
 *     tags={"Admin – Wallet"},
 *     summary="List all wallets",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Wallets list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/wallet/{walletId}/transactions",
 *     operationId="adminWalletTransactions",
 *     tags={"Admin – Wallet"},
 *     summary="Get transactions for a wallet",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="walletId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Transactions list")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/wallet/requests",
 *     operationId="adminWalletRequests",
 *     tags={"Admin – Wallet"},
 *     summary="List pending wallet requests",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Requests list")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/wallet/requests/{id}/approve",
 *     operationId="adminWalletApprove",
 *     tags={"Admin – Wallet"},
 *     summary="Approve a wallet request",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Approved")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/wallet/requests/{id}/reject",
 *     operationId="adminWalletReject",
 *     tags={"Admin – Wallet"},
 *     summary="Reject a wallet request",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=false,
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *     @OA\Response(response=200, description="Rejected")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/wallet/charge",
 *     operationId="adminWalletCharge",
 *     tags={"Admin – Wallet"},
 *     summary="[system_admin] Manually charge a wallet",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"user_id","amount"},
 *             @OA\Property(property="user_id", type="integer"),
 *             @OA\Property(property="amount",  type="number"),
 *             @OA\Property(property="notes",   type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Wallet charged")
 * )
 */
class AdminDocs {}
SDOC);

/* =============================================================================
   11. StaffDocs.php
   ============================================================================ */
w("$base/StaffDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * ── Staff Auth ────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/staff/login",
 *     operationId="staffLogin",
 *     tags={"Staff – Auth"},
 *     summary="Staff login",
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"email","password"},
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password")
 *         )
 *     ),
 *     @OA\Response(response=200, description="JWT returned"),
 *     @OA\Response(response=401, description="Invalid credentials")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/refresh",
 *     operationId="staffRefresh",
 *     tags={"Staff – Auth"},
 *     summary="Refresh staff JWT",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="New token")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/logout",
 *     operationId="staffLogout",
 *     tags={"Staff – Auth"},
 *     summary="Staff logout",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Logged out")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/me",
 *     operationId="staffMe",
 *     tags={"Staff – Auth"},
 *     summary="Get current staff member",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Staff user object")
 * )
 *
 * ── Staff Reviews ─────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/reviews",
 *     operationId="staffReviews",
 *     tags={"Staff – Operations"},
 *     summary="List all user reviews / comments",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Reviews list")
 * )
 *
 * @OA\Delete(
 *     path="/api/staff/reviews/{commentId}",
 *     operationId="staffDeleteReview",
 *     tags={"Staff – Operations"},
 *     summary="Delete a review / comment",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="commentId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Deleted")
 * )
 *
 * ── Staff Users ───────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/users",
 *     operationId="staffUsers",
 *     tags={"Staff – Operations"},
 *     summary="List all users",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Users list")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/users/{userId}",
 *     operationId="staffUserProfile",
 *     tags={"Staff – Operations"},
 *     summary="Get a user's profile",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="User profile")
 * )
 *
 * ── Staff Trips & Bookings ────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/trips",
 *     operationId="staffTrips",
 *     tags={"Staff – Operations"},
 *     summary="List all trips",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Trips list")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/bookings",
 *     operationId="staffBookings",
 *     tags={"Staff – Operations"},
 *     summary="List all bookings",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Bookings list")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/trips/{rideId}/cancel",
 *     operationId="staffCancelTrip",
 *     tags={"Staff – Operations"},
 *     summary="Cancel a trip (staff action)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="rideId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=false,
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *     @OA\Response(response=200, description="Trip cancelled")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/bookings/{bookingId}/cancel",
 *     operationId="staffCancelBooking",
 *     tags={"Staff – Operations"},
 *     summary="Cancel a booking (staff action)",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="bookingId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=false,
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *     @OA\Response(response=200, description="Booking cancelled")
 * )
 *
 * ── Staff Complaints ──────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/complaints",
 *     operationId="staffComplaints",
 *     tags={"Staff – Complaints"},
 *     summary="List all complaints",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Complaints list")
 * )
 *
 * @OA\Get(
 *     path="/api/staff/complaints/{id}",
 *     operationId="staffComplaintShow",
 *     tags={"Staff – Complaints"},
 *     summary="Get a complaint",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Complaint object")
 * )
 *
 * @OA\Patch(
 *     path="/api/staff/complaints/{id}/respond",
 *     operationId="staffComplaintRespond",
 *     tags={"Staff – Complaints"},
 *     summary="Respond to a complaint",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"response"},
 *             @OA\Property(property="response", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Response saved")
 * )
 *
 * @OA\Patch(
 *     path="/api/staff/complaints/{id}/escalate",
 *     operationId="staffComplaintEscalate",
 *     tags={"Staff – Complaints"},
 *     summary="Escalate a complaint to admin",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=false,
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *     @OA\Response(response=200, description="Escalated")
 * )
 *
 * ── Staff Verifications (admin + system_admin) ────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/verifications/pending",
 *     operationId="staffVerifPending",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] List pending verifications",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Pending verifications")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/verifications/{userId}/approve",
 *     operationId="staffVerifApprove",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] Approve a verification",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Approved")
 * )
 *
 * @OA\Post(
 *     path="/api/staff/verifications/{userId}/reject",
 *     operationId="staffVerifReject",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] Reject a verification",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=false,
 *         @OA\JsonContent(@OA\Property(property="reason", type="string"))
 *     ),
 *     @OA\Response(response=200, description="Rejected")
 * )
 *
 * ── Escalated Complaints (admin + system_admin) ───────────────────────────────
 *
 * @OA\Get(
 *     path="/api/staff/escalated-complaints",
 *     operationId="staffEscalatedComplaints",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] List escalated complaints",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Escalated complaints list")
 * )
 *
 * @OA\Patch(
 *     path="/api/staff/escalated-complaints/{id}/resolve",
 *     operationId="staffEscalatedResolve",
 *     tags={"Staff – Complaints"},
 *     summary="[admin] Resolve an escalated complaint",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"resolution"},
 *             @OA\Property(property="resolution", type="string")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Resolved")
 * )
 */
class StaffDocs {}
SDOC);

/* =============================================================================
   12. EmployeeDocs.php  (NEW — was entirely missing)
   ============================================================================ */
w("$base/EmployeeDocs.php", <<<'SDOC'
<?php
namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/employees",
 *     operationId="employeesList",
 *     tags={"Employees"},
 *     summary="[system_admin] List all employees",
 *     security={{"bearerAuth":{}}},
 *     @OA\Response(response=200, description="Employees list")
 * )
 *
 * @OA\Post(
 *     path="/api/employees",
 *     operationId="employeesStore",
 *     tags={"Employees"},
 *     summary="[system_admin] Create an employee account",
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             required={"name","email","password","role"},
 *             @OA\Property(property="name",     type="string"),
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password"),
 *             @OA\Property(property="role",     type="string", enum={"admin","staff"})
 *         )
 *     ),
 *     @OA\Response(response=201, description="Employee created")
 * )
 *
 * @OA\Get(
 *     path="/api/employees/{id}",
 *     operationId="employeesShow",
 *     tags={"Employees"},
 *     summary="[system_admin] Get an employee",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Employee object")
 * )
 *
 * @OA\Put(
 *     path="/api/employees/{id}",
 *     operationId="employeesUpdate",
 *     tags={"Employees"},
 *     summary="[system_admin] Update an employee",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="name",  type="string"),
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="role",  type="string", enum={"admin","staff"})
 *         )
 *     ),
 *     @OA\Response(response=200, description="Employee updated")
 * )
 *
 * @OA\Patch(
 *     path="/api/employees/{id}/toggle-active",
 *     operationId="employeesToggleActive",
 *     tags={"Employees"},
 *     summary="[system_admin] Toggle employee active status",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="Status toggled")
 * )
 *
 * @OA\Patch(
 *     path="/api/employees/{id}/reset-password",
 *     operationId="employeesResetPassword",
 *     tags={"Employees"},
 *     summary="[system_admin] Reset employee password",
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=true,
 *         @OA\JsonContent(required={"password","password_confirmation"},
 *             @OA\Property(property="password",              type="string", format="password"),
 *             @OA\Property(property="password_confirmation", type="string", format="password")
 *         )
 *     ),
 *     @OA\Response(response=200, description="Password reset")
 * )
 */
class EmployeeDocs {}
SDOC);

/* =============================================================================
   Summary
   ============================================================================ */
echo "\n---------------------------------------------------\n";
echo "  $ok file(s) created" . ($fail ? ", $fail FAILED" : "") . "\n";
echo "\nNext steps:\n";
echo "  1. Remove-Item -Recurse -Force app\\Docs   (wipe old files)\n";
echo "  2. php setup_docs_v3.php              (run this script)\n";
echo "  3. php artisan l5-swagger:generate    (build the spec)\n";
echo "  4. Open http://localhost/4th_year_project/public/api/documentation\n";
echo "  5. Delete setup_docs_v3.php\n\n";
