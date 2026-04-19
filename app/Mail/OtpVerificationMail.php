<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class OtpVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otpCode,
        public readonly string $userName,
        public readonly int    $expiryMinutes = 10,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your Atarikak Verification Code')
            ->view('emails.otp-verification');  // ← change markdown() to view()
    }
}
