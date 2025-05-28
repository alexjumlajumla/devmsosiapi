<html>
<head>
    <meta charset="utf-8" />
    <title>{{ $title }}</title>
</head>
<body style="font-family: Arial, sans-serif; background-color:#f5f5f5; padding:20px;">
    <table align="center" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden;">
        <!-- header with logo -->
        <tr>
            <td style="background:#003466; padding:20px; text-align:center;">
                <img src="{{ asset('storage/images/logo-email.png') }}" alt="{{ config('app.name') }}" style="max-height:60px;">
            </td>
        </tr>

        <!-- body -->
        <tr>
            <td style="padding:30px 25px; color:#003466;">
                <h2 style="margin-top:0; color:#003466;">{{ $title }}</h2>
                <div style="font-size:16px; line-height:1.5; color:#333;">{!! $body !!}</div>
            </td>
        </tr>

        <!-- footer -->
        <tr>
            <td style="background:#fbc618; height:1px;"></td>
        </tr>
        <tr>
            <td style="padding:15px 25px; font-size:12px; color:#777;">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="left">
                            <a href="{{ url('/privacy') }}" style="color:#003466; text-decoration:none;">Privacy Policy</a>
                        </td>
                        <td align="right">
                            <a href="{{ url('/terms') }}" style="color:#003466; text-decoration:none;">Terms & Conditions</a>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding-top:10px; text-align:center; color:#999;">
                            © {{ date('Y') }} {{ config('app.name') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html> 