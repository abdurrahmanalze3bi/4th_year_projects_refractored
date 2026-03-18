<?php

namespace App\Services;

use App\Interfaces\OtpRepositoryInterface;
use App\Models\Otp;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WhatsAppOtpService
{
    protected $otpRepository;
    protected $client;
    protected $apiKey;

    public function __construct(OtpRepositoryInterface $otpRepository)
    {
        $this->otpRepository = $otpRepository;
        $this->client = new Client();
        $this->apiKey = env('CALLMEBOT_API_KEY');
    }

    /**
     * Send OTP via WhatsApp
     */
    public function sendOtp(string $phoneNumber, string $type = 'E-PAYMENT'): array
    {
        try {
            $validatedPhone = $this->validateSyrianPhone($phoneNumber);

            if (!$this->canSendOtp($validatedPhone)) {
                return [
                    'success' => false,
                    'message' => 'Too many OTP requests. Please try again later.'
                ];
            }

            $this->otpRepository->deleteByPhone($validatedPhone);
            $otpCode = Otp::generateCode();

            $otp = $this->otpRepository->create([
                'phone_number' => $validatedPhone,
                'otp_code'     => $otpCode,
                'type'         => $type,
                'expires_at'   => Carbon::now()->addMinutes(10),
                'is_verified'  => false,
                'attempts'     => 0
            ]);

            // ── TESTING MODE ────────────────────────────────────────────
            if ($this->isTestingMode()) {
                Log::info("OTP (testing mode) for $validatedPhone: $otpCode");
                return [
                    'success'    => true,
                    'message'    => 'OTP generated (testing mode — use the code below)',
                    'otp_code'   => $otpCode,          // ← returned to caller
                    'expires_at' => $otp->expires_at->toDateTimeString()
                ];
            }

            // ── PRODUCTION MODE ─────────────────────────────────────────
            if (empty($this->apiKey)) {
                return [
                    'success' => false,
                    'message' => 'No API key configured for production OTP sending.'
                ];
            }

            $sent = $this->sendViaCallMeBot($validatedPhone, $otpCode);

            if (!$sent) {
                Log::error("Failed to send OTP to $validatedPhone");
                return [
                    'success' => false,
                    'message' => 'Failed to send OTP. Please try again.'
                ];
            }

            Log::info("OTP sent (production) to $validatedPhone");
            return [
                'success'    => true,
                'message'    => 'OTP sent successfully via WhatsApp',
                'expires_at' => $otp->expires_at->toDateTimeString()
                // ← otp_code is NOT returned in production
            ];

        } catch (\Exception $e) {
            Log::error('OTP send error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send OTP. Please try again.'];
        }
    }

    /**
     * Verify OTP - MODIFIED TO ACCEPT ANY 6-DIGIT CODE
     */
    public function verifyOtp(string $phoneNumber, string $code): array
    {
        try {
            $validatedPhone = $this->validateSyrianPhone($phoneNumber);

            // Both modes verify against the stored DB record — no bypass
            $otp = $this->otpRepository->findByPhoneAndCode($validatedPhone, $code);

            if (!$otp) {
                return ['success' => false, 'message' => 'Invalid or expired OTP'];
            }

            if (!$otp->isValid()) {
                return ['success' => false, 'message' => 'OTP has expired or exceeded maximum attempts'];
            }

            $otp->markAsVerified();

            return [
                'success' => true,
                'message' => 'OTP verified successfully',
                'data'    => [
                    'phone_number' => $validatedPhone,
                    'verified_at'  => $otp->verified_at->toDateTimeString()
                ]
            ];

        } catch (\Exception $e) {
            Log::error('OTP verification error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'OTP verification failed'];
        }
    }

// ── private helper ───────────────────────────────────────────────────────────

    private function isTestingMode(): bool
    {
        return env('WALLET_OTP_MODE', 'production') === 'testing';
    }

    /**
     * Check if bypass mode is enabled
     * You can control this with an environment variable
     */
    private function isBypassMode(): bool
    {
        // Enable bypass in development or when explicitly set
        return env('OTP_BYPASS_ENABLED', false) || app()->environment(['local', 'testing']);
    }

    /**
     * Validate if code is exactly 6 digits
     */
    private function isValidSixDigitCode(string $code): bool
    {
        return preg_match('/^\d{6}$/', $code);
    }

    /**
     * Create a dummy OTP record for bypass verification
     */
    private function createBypassOtpRecord(string $phoneNumber, string $code): void
    {
        try {
            // Delete any existing OTPs for this phone
            $this->otpRepository->deleteByPhone($phoneNumber);

            // Create a verified OTP record for consistency
            $this->otpRepository->create([
                'phone_number' => $phoneNumber,
                'otp_code' => $code,
                'type' => 'BYPASS',
                'expires_at' => Carbon::now()->addHour(), // Long expiry for bypass
                'is_verified' => true,
                'verified_at' => Carbon::now(),
                'attempts' => 0
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to create bypass OTP record: ' . $e->getMessage());
        }
    }

    /**
     * Send OTP via CallMeBot API (works with personal WhatsApp)
     */
    private function sendViaCallMeBot(string $phoneNumber, string $otpCode): bool
    {
        try {
            $normalizedPhone = $this->normalizeForCallMeBot($phoneNumber);
            $message = "Your verification code is: $otpCode\n\nThis code will expire in 5 minutes.\n\nDo not share this code with anyone.";

            $url = "https://api.callmebot.com/whatsapp.php?" . http_build_query([
                    'phone' => $normalizedPhone,
                    'text' => $message,
                    'apikey' => $this->apiKey
                ]);

            // Create client with disabled SSL verification
            $insecureClient = new Client(['verify' => false]);

            $response = $insecureClient->get($url);
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            // Consider 200 or 203 as success regardless of response body
            if ($statusCode === 200 || $statusCode === 203) {
                Log::info("OTP sent successfully to $phoneNumber. Response status: $statusCode");
                return true;
            }

            Log::error("CallMeBot failed. Status: $statusCode | Response: $responseBody");
            return false;

        } catch (RequestException $e) {
            Log::error('CallMeBot API error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Normalize phone for CallMeBot (963XXXXXXXXX format)
     */
    private function normalizeForCallMeBot(string $phoneNumber): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phoneNumber);
        $clean = ltrim($clean, '0');

        if (str_starts_with($clean, '9639') && strlen($clean) === 12) {
            return $clean; // Already in 9639XXXXXXXX format
        }

        if (str_starts_with($clean, '9') && strlen($clean) === 9) {
            return '963' . $clean; // 9XXXXXXXX → 9639XXXXXXXX
        }

        return $clean;
    }

    /**
     * Validate Syrian phone number
     */
    // Replace with in both files
    private function validateSyrianPhone(string $phoneNumber): string
    {
        return (string) \App\Domain\ValueObjects\PhoneNumber::from($phoneNumber);
    }

    /**
     * Check if OTP can be sent (rate limiting)
     */
    private function canSendOtp(string $phoneNumber): bool
    {
        $recentAttempts = $this->otpRepository->getRecentAttempts($phoneNumber, 5);
        return $recentAttempts < 3; // Max 3 attempts per 5 minutes
    }
}
