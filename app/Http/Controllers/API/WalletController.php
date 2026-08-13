<?php

namespace App\Http\Controllers\API;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Domain\ValueObjects\Email;
use App\Http\Controllers\Controller;
use App\Interfaces\EmailOtpServiceInterface;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public function __construct(
        private readonly EmailOtpServiceInterface $emailOtpService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 1 – Initiate wallet creation
    // POST /api/wallet/initiate
    //
    // Validates the phone number, stores it in cache, then sends a 6-digit
    // OTP to the authenticated user's email address (same flow as signup).
    // Password is NOT required: the user is already authenticated via JWT.
    // ─────────────────────────────────────────────────────────────────────────
    public function initiateWalletCreation(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a wallet.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|unique:wallets,phone_number',
        ], [
            'phone_number.required' => 'A phone number is required to create a wallet.',
            'phone_number.unique'   => 'This phone number is already linked to another wallet.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Cache the phone number for the verification step (10 minutes).
        $cacheKey = "wallet_creation_{$user->id}";
        Cache::put($cacheKey, [
            'user_id'      => $user->id,
            'phone_number' => $request->phone_number,
        ], 600);

        // Send OTP to the user's Gmail (identical to signup / email verification).
        $dto    = new SendEmailOtpDTO(
            email:    Email::from($user->email),
            userName: $user->first_name,
            type:      'EMAIL_VERIFICATION',   // isolated from signup OTPs
        );
        $result = $this->emailOtpService->sendOtp($dto);

        if (! $result['success']) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send verification email. Please try again.',
            ], 500);
        }

        $response = [
            'success'    => true,
            'message'    => "A 6-digit verification code has been sent to {$user->email}. It expires in 10 minutes.",
            'email_hint' => $this->maskEmail($user->email),
        ];

        // Expose the code only when EMAIL_OTP_MODE != production (dev / local).
        if (isset($result['otp_code'])) {
            $response['otp_code'] = $result['otp_code'];
        }

        return response()->json($response);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 2 – Verify OTP and create wallet
    // POST /api/wallet/verify-and-create
    //
    // Only `otp_code` is required.  The user's email comes from the JWT and
    // the phone number is retrieved from the cache set in step 1.
    // ─────────────────────────────────────────────────────────────────────────
    public function verifyAndCreateWallet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'otp_code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ], [
            'otp_code.required' => 'Verification code is required.',
            'otp_code.size'     => 'Code must be exactly 6 digits.',
            'otp_code.regex'    => 'Code must contain numbers only.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a wallet.',
            ], 409);
        }

        // Retrieve cached phone number from step 1.
        $cacheKey   = "wallet_creation_{$user->id}";
        $walletData = Cache::get($cacheKey);

        if (! $walletData) {
            return response()->json([
                'success' => false,
                'message' => 'Session expired. Please start the wallet creation process again.',
            ], 400);
        }

        // Verify OTP against the user's email — same DTO / service as signup.
        $dto    = new VerifyEmailOtpDTO(
            email:   Email::from($user->email),
            otpCode: $request->otp_code,
        );
        $result = $this->emailOtpService->verifyOtp($dto);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Invalid or expired code.',
            ], 400);
        }

        // OTP valid — create wallet and link it to the user.
        $wallet = Wallet::create([
            'user_id'      => $user->id,
            'phone_number' => $walletData['phone_number'],
            'balance'      => 0,
        ]);

        $user->wallet_id = $wallet->id;
        $user->save();

        Cache::forget($cacheKey);

        return response()->json([
            'success'       => true,
            'message'       => 'Wallet created successfully.',
            'wallet_number' => $wallet->wallet_number,
            'phone_number'  => $wallet->phone_number,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/wallet/balance
    // ─────────────────────────────────────────────────────────────────────────
    public function getBalance(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user()->load('wallet');

        if (! $user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'No wallet found for this account.',
            ], 404);
        }

        return response()->json([
            'success'       => true,
            'wallet_number' => $user->wallet->wallet_number,
            'balance'       => $user->wallet->balance,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mask email for the response hint: ab***@gmail.com
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        $visible = min(2, strlen($local));
        $masked  = substr($local, 0, $visible) . str_repeat('*', max(0, strlen($local) - $visible));

        return $masked . '@' . $domain;
    }
    // POST /api/wallet/create-direct
    public function createDirect(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a wallet.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|unique:wallets,phone_number',
        ], [
            'phone_number.required' => 'A phone number is required.',
            'phone_number.unique'   => 'This phone number is already linked to another wallet.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $wallet = Wallet::create([
            'user_id'      => $user->id,
            'phone_number' => $request->phone_number,
            'balance'      => 0,
        ]);

        $user->wallet_id = $wallet->id;
        $user->save();

        return response()->json([
            'success'       => true,
            'message'       => 'Wallet created successfully.',
            'wallet_number' => $wallet->wallet_number,
            'phone_number'  => $wallet->phone_number,
            'balance'       => 0,
        ], 201);
    }
}
