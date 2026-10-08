<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your AVISO Password</title>
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
            background-color: #0274DF;
            padding: 32px 40px 20px 40px;
            text-align: center;
        }
        .logo {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #ffffff;
        }
        .tagline {
            font-size: 13px;
            color: rgba(255,255,255,0.75);
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
            margin-left: 16px;
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
                Hi <strong>{{ $firstName }}</strong>,<br><br>
                We received a request to reset your AVISO rider password. Use the code below to continue.
            </p>

            <div class="otp-label">Your Password Reset Code</div>
            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-expiry">Valid for 10 minutes</div>
            </div>

            <div class="warning">
                ⚠️ Never share this code with anyone. The AVISO team will never ask for your OTP.
                If you did not request a password reset, you can safely ignore this email — your password has not been changed.
            </div>
        </div>

        <div class="footer">
            <p>
                © {{ date('Y') }} AVISO · Road Hazard Detection System
            </p>
        </div>
    </div>
</body>
</html>
