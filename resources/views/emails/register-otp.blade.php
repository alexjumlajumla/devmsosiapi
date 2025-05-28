<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Msosi App Registration Verification</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #2d3748;">Complete Your Registration</h2>
        <p>Hello {{ $name }},</p>
        <p>Thank you for registering. Please use this verification code to complete your registration:</p>
        
        <div style="background-color: #f7fafc; border: 1px solid #e2e8f0; border-radius: 5px; padding: 20px; text-align: center; margin: 20px 0;">
            <h1 style="font-size: 32px; margin: 0; letter-spacing: 5px; color: #003466;">{{ $otp }}</h1>
        </div>
        
        <p style="color: #003466; font-size: 14px;">This code will expire in 5 minutes for security reasons.</p>
        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;">
        <p style="color: #003466; font-size: 12px;">If you didn't request this registration, please ignore this email.</p>
    </div>
</body>
</html>