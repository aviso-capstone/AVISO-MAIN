<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #000000;
            color: #fafafa;
            padding: 40px 20px;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            background-color: #09090b;
            border: 1px solid #27272a;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.4);
        }
        .header {
            background-color: #09090b;
            padding: 32px 40px 20px 40px;
            text-align: center;
            border-bottom: 1px solid #18181b;
        }
        .logo {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #ffffff;
        }
        .tagline {
            font-size: 13px;
            color: #a1a1aa;
            margin-top: 6px;
            font-weight: 500;
        }
        .body {
            padding: 32px 40px;
        }
        .greeting {
            font-size: 15px;
            color: #d4d4d8;
            margin-bottom: 28px;
            line-height: 1.6;
            text-align: center;
        }
        .otp-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #a1a1aa;
            margin-bottom: 12px;
            text-align: center;
        }
        .otp-box {
            background-color: #18181b;
            border: 1px solid #27272a;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            margin-bottom: 32px;
        }
        .otp-code {
            font-size: 40px;
            font-weight: 700;
            letter-spacing: 16px;
            color: #ffffff;
            font-variant-numeric: tabular-nums;
            margin-left: 16px; /* Offset the letter-spacing on the last char */
        }
        .otp-expiry {
            font-size: 13px;
            color: #71717a;
            margin-top: 12px;
        }
        .warning {
            background-color: #18181b;
            border-left: 4px solid #3f3f46;
            padding: 16px;
            font-size: 13px;
            color: #a1a1aa;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .footer {
            border-top: 1px solid #27272a;
            background-color: #000000;
            padding: 24px 40px;
            text-align: center;
        }
        .footer p {
            font-size: 12px;
            color: #71717a;
            line-height: 1.6;
        }
        .footer strong {
            color: #a1a1aa;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">AVISO</div>
            <div class="tagline">Road Hazard Detection System</div>
        </div>

        <div class="body">
            <p class="greeting">
                We received a request to reset the password for the admin account
                associated with <strong>{{ $email }}</strong>. Use the code below
                to verify your identity and set a new password.
            </p>

            <div class="otp-label">Your One-Time Password</div>
            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-expiry">Valid for 10 minutes</div>
            </div>

            <div class="warning">
                ⚠️ Never share this code with anyone. The AVISO team will never ask for your OTP.
                If you did not request a password reset, you can safely ignore this email.
            </div>
        </div>

        <div class="footer">
            <p>
                This email was sent to <strong>{{ $email }}</strong><br>
                © {{ date('Y') }} AVISO · Road Hazard Detection System
            </p>
        </div>
    </div>
</body>
</html>
