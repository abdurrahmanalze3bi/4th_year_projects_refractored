<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Atarikak Verification Code</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4;padding:40px 0;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);">

                {{-- Header --}}
                <tr>
                    <td align="center" style="background-color:#253c5b;padding:40px 30px;">
                        <h1 style="color:#ed8b10;margin:0;font-size:32px;font-weight:bold;letter-spacing:2px;">
                            ATARIKAK
                        </h1>
                        <p style="color:#ffffff;margin:8px 0 0;font-size:14px;opacity:0.8;">
                            Ride Sharing Platform
                        </p>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:40px 30px;">
                        <h2 style="color:#253c5b;margin:0 0 20px;font-size:24px;">
                            Email Verification
                        </h2>
                        <p style="color:#555555;font-size:16px;line-height:1.6;margin:0 0 30px;">
                            Hi <strong>{{ $userName }}</strong>,
                        </p>
                        <p style="color:#555555;font-size:16px;line-height:1.6;margin:0 0 30px;">
                            Your Atarikak verification code is:
                        </p>

                        {{-- OTP Box --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 30px;">
                            <tr>
                                <td align="center">
                                    <div style="background-color:#253c5b;border-left:4px solid #ed8b10;border-radius:8px;padding:25px;display:inline-block;min-width:200px;">
                                            <span style="font-size:42px;font-weight:bold;color:#ed8b10;letter-spacing:10px;">
                                                {{ $otpCode }}
                                            </span>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <p style="color:#555555;font-size:14px;line-height:1.6;margin:0 0 10px;">
                            ⏱ This code expires in <strong>{{ $expiryMinutes }} minutes</strong>.
                        </p>
                        <p style="color:#555555;font-size:14px;line-height:1.6;margin:0;">
                            🔒 Do not share this code with anyone.
                        </p>
                    </td>
                </tr>

                {{-- Divider --}}
                <tr>
                    <td style="padding:0 30px;">
                        <hr style="border:none;border-top:1px solid #eeeeee;margin:0;">
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td align="center" style="padding:30px;background-color:#f9f9f9;">
                        <p style="color:#555555;font-size:14px;margin:0 0 5px;">
                            Thanks,<br>
                            <strong style="color:#253c5b;">Atarikak Team</strong>
                        </p>
                        <p style="color:#aaaaaa;font-size:12px;margin:15px 0 0;">
                            © 2026 Atarikak. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
