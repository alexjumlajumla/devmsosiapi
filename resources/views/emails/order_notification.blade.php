<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #065c94;
            color: #ffffff;
            text-align: center;
            padding: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px;
        }
        .order-info {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .order-info h2 {
            margin: 0 0 15px;
            color: #065c94;
            font-size: 18px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .label {
            font-weight: bold;
            color: #555;
        }
        .value {
            color: #333;
        }
        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            font-weight: bold;
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        .footer {
            text-align: center;
            padding: 20px;
            background-color: #f8f9fa;
            font-size: 14px;
            color: #666;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #065c94;
            color: #ffffff;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        .help-text {
            margin-top: 20px;
            font-size: 14px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Order Update</h1>
        </div>
        <div class="content">
            @if($order)
                <p>Dear {{ $order->user?->firstname }},</p>
                <p>{{ $message }}</p>

                <div class="order-info">
                    <h2>Order Details</h2>
                    <div class="info-row">
                        <span class="label">Order ID:</span>
                        <span class="value">#{{ $order->id }}</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Order Date:</span>
                        <span class="value">{{ $order->created_at->format('M d, Y H:i') }}</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Status:</span>
                        <span class="status">{{ ucfirst($order->status) }}</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Total Amount:</span>
                        <span class="value">{{ $order->currency?->symbol }}{{ number_format($order->total_price, 2) }}</span>
                    </div>
                    @if($order->delivery_date)
                    <div class="info-row">
                        <span class="label">Delivery Date:</span>
                        <span class="value">{{ $order->delivery_date->format('M d, Y') }}</span>
                    </div>
                    @endif
                    @if($order->delivery_time)
                    <div class="info-row">
                        <span class="label">Delivery Time:</span>
                        <span class="value">{{ $order->delivery_time }}</span>
                    </div>
                    @endif
                </div>

                <div style="text-align: center;">
                    <a href="{{ config('app.url') }}/orders/{{ $order->id }}" class="button">View Order Details</a>
                </div>
            @else
                <p>Hello,</p>
                <p>{{ $message }}</p>
            @endif

            <p class="help-text">
                If you have any questions about your order, please don't hesitate to contact our customer support team.
                <br>
                Thank you for shopping with us!
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
