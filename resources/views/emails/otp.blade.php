<!DOCTYPE html>
<html>
<head>
    <title>JumlaJumla OTP Verification</title>
</head>
<body style="font-family: Arial, sans-serif;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Verification Code</h2>
        <p>Hello {{ $user->firstname ?? 'User' }},</p>
        <div style="background: #f4f4f4; padding: 15px; text-align: center; font-size: 24px; letter-spacing: 5px;">
            <strong>{{ $otp }}</strong>
        </div>
        <p>This code will expire in 5 minutes.</p>
        <p>If you didn't request this code, please ignore this email.</p>
    </div>
</body>
</html>