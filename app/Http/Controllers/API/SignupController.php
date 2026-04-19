<?php
namespace App\Http\Controllers\API;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\Http\Controllers\Controller;
use App\Interfaces\EmailOtpServiceInterface;
use App\Interfaces\UserRepositoryInterface;
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
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|confirmed|min:8',
            'gender'     => 'required|in:M,F',
            'address'    => 'required|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,ادلب,الحسكة,الرقة,دير الزور',
        ], [
            'email.unique'       => 'This email address is already registered.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

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

            // Send email OTP — tokens returned only after verification
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

            // Expose OTP code only in testing/local mode
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
