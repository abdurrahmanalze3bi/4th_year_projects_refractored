<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\WhatsAppOtpService;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class WalletController extends Controller
{
    protected $otpService;

    public function __construct(WhatsAppOtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * Step 1: Initiate wallet creation and send OTP
     * POST /api/wallet/initiate
     */
    public function initiateWalletCreation(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|unique:wallets,phone_number',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();


        // Verify password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid password'
            ], 401);
        }

        // Check if wallet already exists
        if ($user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet already exists for this user'
            ], 409);
        }

        // Store wallet creation data temporarily (expires in 10 minutes)
        $cacheKey = "wallet_creation_{$user->id}";
        $walletData = [
            'user_id'      => $user->id,
            'phone_number' => $request->phone_number,
            'timestamp'    => now()
        ];

        Cache::put($cacheKey, $walletData, 600); // 10 minutes

        // Send OTP
        $otpResult = $this->otpService->sendOtp($request->phone_number, 'WALLET_CREATION');

        if (!$otpResult['success']) {
            return response()->json($otpResult, 400);
        }

        $response = [
            'success'      => true,
            'message'      => 'OTP sent successfully. Please verify to complete wallet creation.',
            'phone_number' => $request->phone_number,
        ];

        // Testing mode: expose OTP code so you can use it directly
        if (isset($otpResult['otp_code'])) {
            $response['otp_code'] = $otpResult['otp_code'];
        }

        return response()->json($response, 200);
    }
    /**
     * Step 2: Verify OTP and create wallet
     * POST /api/wallet/verify-and-create
     */
    public function verifyAndCreateWallet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'otp_code'     => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();


        // Check if wallet already exists
        if ($user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet already exists for this user'
            ], 409);
        }

        // Verify OTP - the OTP table already holds the phone + code
        $otpResult = $this->otpService->verifyOtp($request->phone_number, $request->otp_code);

        if (!$otpResult['success']) {
            return response()->json($otpResult, 400);
        }

        // Create wallet
        $wallet = Wallet::create([
            'user_id'      => $user->id,
            'phone_number' => $request->phone_number,
            'balance'      => 0
        ]);

        $user->wallet_id = $wallet->id;
        $user->save();

        return response()->json([
            'success'       => true,
            'message'       => 'Wallet created successfully',
            'wallet_number' => $wallet->wallet_number,
            'phone_number'  => $wallet->phone_number
        ], 201);
    }

    /**
     * Get wallet balance
     * GET /api/wallet/balance
     */
    public function getBalance(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user()->load('wallet');

        if (!$user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found for this user'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'wallet_number' => $user->wallet->wallet_number,
            'balance'       => $user->wallet->balance
        ]);
    }
}
