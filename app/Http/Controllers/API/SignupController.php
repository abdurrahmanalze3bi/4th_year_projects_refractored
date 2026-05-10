<?php

namespace App\Http\Controllers\API;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SignupController extends Controller
{
    public function __construct(
        private readonly UserRepositoryInterface  $userRepository,
        private readonly JwtService               $jwtService,
        private readonly EmailOtpServiceInterface $emailOtpService,
    ) {}

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|email|max:255',        // ← removed unique here, handled manually below
            'password'   => 'required|string|confirmed|min:8',
            'gender'     => 'required|in:M,F',
            'address'    => 'required|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,ادلب,الحسكة,الرقة,دير الزور',
        ], [
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // ── Check if email already exists ────────────────────────────────
        $existingUser = $this->userRepository->findByEmail($request->email);

        if ($existingUser) {
            // Already verified → hard stop
            if ($existingUser->email_verified_at !== null) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This email address is already registered. Please log in.',
                ], 409);
            }

            // Exists but NOT verified → resend OTP, don't create a new account
            // Update their password in case they're retrying with a new one
            $existingUser->password = Hash::make($request->password);
            $existingUser->save();

            $dto       = SendEmailOtpDTO::fromUser($existingUser);
            $otpResult = $this->emailOtpService->sendOtp($dto);

            if (!$otpResult['success']) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Could not send verification email. Please try again.',
                ], 500);
            }

            $response = [
                'status'  => 'success',
                'message' => 'A new verification code has been sent to your email. Your previous code had expired.',
                'user'    => [
                    'id'         => $existingUser->id,
                    'first_name' => $existingUser->first_name,
                    'email'      => $existingUser->email,
                ],
            ];

            if (isset($otpResult['otp_code'])) {
                $response['otp_code'] = $otpResult['otp_code'];
            }

            return response()->json($response, 200);
        }

        // ── New user — create account and send OTP ───────────────────────
        DB::beginTransaction();

        try {
            $user = $this->userRepository->createUser([
                'first_name' => $request->first_name,
                'last_name'  => $request->last_name,
                'email'      => $request->email,
                'password'   => Hash::make($request->password),
                'gender'     => $request->gender,
                'address'    => $request->address,
                'status'     => 1,
            ]);

            $dto       = SendEmailOtpDTO::fromUser($user);
            $otpResult = $this->emailOtpService->sendOtp($dto);

            if (!$otpResult['success']) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Registration failed: could not send verification email.',
                ], 500);
            }

            DB::commit();

            $response = [
                'status'  => 'success',
                'message' => 'Registration successful. Check your email for a verification code.',
                'user'    => [
                    'id'         => $user->id,
                    'first_name' => $user->first_name,
                    'email'      => $user->email,
                ],
            ];

            if (isset($otpResult['otp_code'])) {
                $response['otp_code'] = $otpResult['otp_code'];
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: ' . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => 'Registration failed',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred',
            ], 500);
        }
    }
}
