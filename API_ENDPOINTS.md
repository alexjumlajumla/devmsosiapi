# API Endpoints Documentation

This document contains all API endpoints available in the application.

## Authentication Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/auth/register` | Register a new user |
| POST | `/api/v1/auth/login` | User login |
| POST | `/api/v1/auth/check/phone` | Verify phone number |
| POST | `/api/v1/auth/logout` | User logout |
| POST | `/api/v1/auth/verify/phone` | Verify phone number |
| POST | `/api/v1/send/otp` | Send OTP code |
| POST | `/api/v1/verify/otp` | Verify OTP code |
| POST | `/api/v1/auth/resend-verify` | Resend verification code |
| GET | `/api/v1/auth/verify/{hash}` | Verify email |
| POST | `/api/v1/auth/after-verify` | Actions after email verification |
| POST | `/api/v1/auth/forgot/password` | Forgot password request |
| POST | `/api/v1/auth/forgot/password/confirm` | Confirm forgot password |
| POST | `/api/v1/auth/forgot/email-password` | Forgot password via email |
| POST | `/api/v1/auth/forgot/email-password/{hash}` | Verify forgot password via email |
| POST | `/api/v1/auth/{provider}/callback` | Social login callback |

## Installation Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/install/init/check` | Check initialization file |
| POST | `/api/v1/install/init/set` | Set initialization file |
| POST | `/api/v1/install/database/update` | Update database settings |
| POST | `/api/v1/install/admin/create` | Create admin user |
| POST | `/api/v1/install/migration/run` | Run database migrations |
| POST | `/api/v1/install/check/licence` | Check license credentials |

## REST API Endpoints

### Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/bosya/test` | Test endpoint |
| GET | `/api/v1/rest/project/version` | Get project version |
| GET | `/api/v1/rest/timezone` | Get timezone information |
| GET | `/api/v1/rest/translations/paginate` | Get translations |
| GET | `/api/v1/rest/settings` | Get application settings |
| GET | `/api/v1/rest/referral` | Get referral information |
| GET | `/api/v1/rest/system/information` | Get system information |
| GET | `/api/v1/rest/stat` | Get statistics |
| GET | `/api/v1/rest/default-sms-payload` | Get default SMS payload |

### Languages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/languages/default` | Get default language |
| GET | `/api/v1/rest/languages/active` | Get active languages |
| GET | `/api/v1/rest/languages/{id}` | Get specific language |
| GET | `/api/v1/rest/languages` | Get all languages |

### Currencies

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/currencies` | Get all currencies |
| GET | `/api/v1/rest/currencies/active` | Get active currencies |

### Coupons

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/coupons` | Get all coupons |
| POST | `/api/v1/rest/coupons/check` | Check coupon validity |
| POST | `/api/v1/rest/cashback/check` | Check cashback |

### Products

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/rest/products/review/{uuid}` | Add product review |
| GET | `/api/v1/rest/products/reviews/{uuid}` | Get product reviews |
| GET | `/api/v1/rest/order/products/calculate` | Calculate order stocks |
| GET | `/api/v1/rest/products/paginate` | Get paginated products |
| GET | `/api/v1/rest/products/brand/{id}` | Get products by brand |
| GET | `/api/v1/rest/products/shop/{uuid}` | Get products by shop UUID |
| GET | `/api/v1/rest/products/category/{uuid}` | Get products by category UUID |
| GET | `/api/v1/rest/products/search` | Search products |
| GET | `/api/v1/rest/products/most-sold` | Get most sold products |
| GET | `/api/v1/rest/products/discount` | Get discounted products |
| GET | `/api/v1/rest/products/ids` | Get products by IDs |
| GET | `/api/v1/rest/products/{uuid}` | Get product by UUID |
| GET | `/api/v1/rest/products/slug/{slug}` | Get product by slug |
| GET | `/api/v1/rest/products/file/read` | Read product file |

### Categories

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/categories/types` | Get category types |
| GET | `/api/v1/rest/categories/parent` | Get parent categories |
| GET | `/api/v1/rest/categories/children/{id}` | Get children categories |
| GET | `/api/v1/rest/categories/paginate` | Get paginated categories |
| GET | `/api/v1/rest/categories/select-paginate` | Get categories for selection |
| GET | `/api/v1/rest/categories/product/paginate` | Get shop category products |
| GET | `/api/v1/rest/categories/shop/paginate` | Get shop categories |
| GET | `/api/v1/rest/categories/search` | Search categories |
| GET | `/api/v1/rest/categories/{uuid}` | Get category by UUID |
| GET | `/api/v1/rest/categories/slug/{slug}` | Get category by slug |

### Brands

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/brands/paginate` | Get paginated brands |
| GET | `/api/v1/rest/brands/{id}` | Get brand by ID |
| GET | `/api/v1/rest/brands/slug/{slug}` | Get brand by slug |

### Landing Pages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/landing-pages/paginate` | Get paginated landing pages |
| GET | `/api/v1/rest/landing-pages/{type}` | Get landing page by type |

### Shops

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/branch/recommended/products` | Get recommended branch products |
| GET | `/api/v1/rest/shops/recommended` | Get recommended shops |
| GET | `/api/v1/rest/shops/paginate` | Get paginated shops |
| GET | `/api/v1/rest/shops/select-paginate` | Get shops for selection |
| GET | `/api/v1/rest/shops/search` | Search shops |
| GET | `/api/v1/rest/shops/{uuid}` | Get shop by UUID |
| GET | `/api/v1/rest/shops/slug/{slug}` | Get shop by slug |
| GET | `/api/v1/rest/shops` | Get shops by IDs |
| GET | `/api/v1/rest/shops-takes` | Get shop takes |
| GET | `/api/v1/rest/products-avg-prices` | Get products average prices |
| GET | `/api/v1/rest/branch/products` | Get branch products |
| GET | `/api/v1/rest/shops/{id}/categories` | Get shop categories |
| GET | `/api/v1/rest/shops/{id}/products` | Get shop products |
| GET | `/api/v1/rest/shops/{id}/galleries` | Get shop galleries |
| GET | `/api/v1/rest/shops/{id}/reviews` | Get shop reviews |
| POST | `/api/v1/rest/shops/review/{id}` | Add shop review |
| GET | `/api/v1/rest/shops/{id}/reviews-group-rating` | Get shop reviews grouped by rating |
| GET | `/api/v1/rest/shops/{id}/products/paginate` | Get paginated shop products |
| GET | `/api/v1/rest/shops/{id}/products/recommended/paginate` | Get recommended shop products |

### Banners

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/banners/paginate` | Get paginated banners |
| POST | `/api/v1/rest/banners/{id}/liked` | Like a banner |
| GET | `/api/v1/rest/banners/{id}` | Get banner by ID |
| GET | `/api/v1/rest/banners-ads` | Get paginated banner ads |
| GET | `/api/v1/rest/banners-ads/{id}` | Get banner ad by ID |

### FAQs

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/faqs/paginate` | Get paginated FAQs |
| GET | `/api/v1/rest/term` | Get terms and conditions |
| GET | `/api/v1/rest/policy` | Get privacy policy |

### Payments

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/payments` | Get all payments |
| GET | `/api/v1/rest/payments/{id}` | Get payment by ID |

### Blogs

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/blogs/paginate` | Get paginated blogs |
| GET | `/api/v1/rest/blogs/{uuid}` | Get blog by UUID |
| GET | `/api/v1/rest/last-blog/show` | Get latest blog |

### Cart

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/rest/cart` | Create cart |
| GET | `/api/v1/rest/cart/{id}` | Get cart by ID |
| POST | `/api/v1/rest/cart/insert-product` | Insert products to cart |
| POST | `/api/v1/rest/cart/open` | Open cart |
| DELETE | `/api/v1/rest/cart/product/delete` | Delete product from cart |
| DELETE | `/api/v1/rest/cart/member/delete` | Delete user cart |
| POST | `/api/v1/rest/cart/status/{user_cart_uuid}` | Change cart status |

### Stories

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/stories/paginate` | Get paginated stories |

### Receipts

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/receipts/paginate` | Get paginated receipts |
| GET | `/api/v1/rest/receipts/{id}` | Get receipt by ID |

### Order Statuses

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/order-statuses` | Get all order statuses |
| GET | `/api/v1/rest/order-statuses/select` | Get order statuses for selection |

### Tags

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/tags/paginate` | Get paginated tags |

### Delivery Zones

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/shop/delivery-zone/{shopId}` | Get delivery zones by shop ID |
| GET | `/api/v1/rest/shop/delivery-zone/calculate/price/{id}` | Calculate delivery price |
| GET | `/api/v1/rest/shop/delivery-zone/calculate/distance` | Calculate delivery distance |
| GET | `/api/v1/rest/shop/delivery-zone/check/distance` | Check delivery distance |
| GET | `/api/v1/rest/shop/{id}/delivery-zone/check/distance` | Check delivery distance by shop |
| GET | `/api/v1/rest/shop-payments/{id}` | Get shop payments |
| GET | `/api/v1/rest/shop-working-check/{id}` | Check shop working hours |

### Product Histories

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/product-histories/paginate` | Get paginated product histories |

### Careers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/careers/paginate` | Get paginated careers |
| GET | `/api/v1/rest/careers/{id}` | Get career by ID |

### Pages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/pages/paginate` | Get paginated pages |
| GET | `/api/v1/rest/pages/{type}` | Get page by type |

### Branches

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/branches` | Get all branches |
| GET | `/api/v1/rest/branches/paginate` | Get paginated branches |
| GET | `/api/v1/rest/branches/{id}` | Get branch by ID |

### Orders

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/orders` | Get all orders |
| POST | `/api/v1/rest/orders` | Create order |
| GET | `/api/v1/rest/orders/{id}` | Get order by ID |
| PUT | `/api/v1/rest/orders/{id}` | Update order |
| DELETE | `/api/v1/rest/orders/{id}` | Delete order |
| POST | `/api/v1/rest/orders/update-tips/{id}` | Update order tips |
| POST | `/api/v1/rest/orders/review/{id}` | Add order review |
| POST | `/api/v1/rest/orders/{id}/status/change` | Change order status |
| GET | `/api/v1/rest/orders/table/{id}` | Get order by table ID |
| POST | `/api/v1/rest/order-details/delete` | Delete order detail |
| GET | `/api/v1/rest/orders/clicked/{id}` | Mark order as clicked |
| GET | `/api/v1/rest/orders/call/waiter/{id}` | Call waiter for order |
| GET | `/api/v1/rest/orders/deliveryman/{id}` | Get order deliveryman |

### Notifications

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/rest/notifications` | Create notification |

### Parcel Orders

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/parcel-order/types` | Get parcel order types |
| GET | `/api/v1/rest/parcel-order/type/{id}` | Get parcel order type by ID |
| GET | `/api/v1/rest/parcel-order/calculate-price` | Calculate parcel order price |

### Payment Processing

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/order-stripe-process` | Process Stripe order payment |
| GET | `/api/v1/rest/order-my-fatoorah-process` | Process MyFatoorah order payment |
| GET | `/api/v1/rest/order-iyzico-process` | Process Iyzico order payment |
| GET | `/api/v1/rest/order-selcom-process` | Process Selcom order payment |
| GET | `/api/v1/rest/order-razorpay-process` | Process RazorPay order payment |
| GET | `/api/v1/rest/order-mercado-pago-process` | Process MercadoPago order payment |
| GET | `/api/v1/rest/order-paystack-process` | Process PayStack order payment |
| GET | `/api/v1/rest/order-paypal-process` | Process PayPal order payment |
| GET | `/api/v1/rest/order-flutter-wave-process` | Process FlutterWave order payment |
| GET | `/api/v1/rest/order-paytabs-process` | Process PayTabs order payment |
| POST | `/api/v1/rest/moya-sar-process` | Process Moyasar order payment |
| POST | `/api/v1/rest/zain-cash-process` | Process ZainCash order payment |
| POST | `/api/v1/rest/mollie-process` | Process Mollie order payment |
| ANY | `/api/v1/rest/maksekeskus-process` | Process Maksekeskus order payment |
| GET | `/api/v1/rest/order-pay-fast-process` | Process PayFast order payment |
| GET | `/api/v1/rest/selcom-result` | Process Selcom result |

### Split Payment Processing

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/split-stripe-process` | Process Stripe split payment |
| GET | `/api/v1/rest/split-my-fatoorah-process` | Process MyFatoorah split payment |
| GET | `/api/v1/rest/split-iyzico-process` | Process Iyzico split payment |
| GET | `/api/v1/rest/split-selcom-process` | Process Selcom split payment |
| GET | `/api/v1/rest/split-razorpay-process` | Process RazorPay split payment |
| GET | `/api/v1/rest/split-mercado-pago-process` | Process MercadoPago split payment |
| GET | `/api/v1/rest/split-paystack-process` | Process PayStack split payment |
| GET | `/api/v1/rest/split-paypal-process` | Process PayPal split payment |
| GET | `/api/v1/rest/split-flutter-wave-process` | Process FlutterWave split payment |
| GET | `/api/v1/rest/split-paytabs-process` | Process PayTabs split payment |
| POST | `/api/v1/rest/split-moya-sar-process` | Process Moyasar split payment |
| POST | `/api/v1/rest/split-zain-cash-process` | Process ZainCash split payment |
| POST | `/api/v1/rest/split-mollie-process` | Process Mollie split payment |
| POST | `/api/v1/rest/split-maksekeskus-process` | Process Maksekeskus split payment |
| GET | `/api/v1/rest/split-pay-fast-process` | Process PayFast split payment |

### Delivery Points

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/delivery-points` | Get all delivery points |
| GET | `/api/v1/rest/delivery-points/{id}` | Get delivery point by ID |

### Transaction Checking

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/rest/check-transaction/{transid}` | Check transaction status |
| GET | `/api/v1/rest/check-transaction-status/parcel/{parcelId}/{transid?}` | Check parcel transaction status |
| GET | `/api/v1/rest/check-transactiondirect/{transid}` | Check transaction direct |

## Payment Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/payments/{type}/{id}/transactions` | Create transaction |
| PUT | `/api/v1/payments/{type}/{id}/transactions` | Update transaction status |
| POST | `/api/v1/payments/wallet/payment/top-up` | Top up wallet payment |

## Dashboard Endpoints

### Galleries

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/galleries/paginate` | Get paginated galleries |
| GET | `/api/v1/dashboard/galleries/storage/files` | Get storage files |
| POST | `/api/v1/dashboard/galleries/storage/files/delete` | Delete storage file |
| POST | `/api/v1/dashboard/galleries` | Create gallery |
| POST | `/api/v1/dashboard/galleries/store-many` | Create multiple galleries |

### Notifications

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/notifications` | Get all notifications |
| GET | `/api/v1/dashboard/notifications/{id}` | Get notification by ID |
| POST | `/api/v1/dashboard/notifications/{id}/read-at` | Mark notification as read |
| POST | `/api/v1/dashboard/notifications/read-all` | Mark all notifications as read |

## User Dashboard Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/profile/show` | Show user profile |
| PUT | `/api/v1/dashboard/user/profile/update` | Update user profile |
| DELETE | `/api/v1/dashboard/user/profile/delete` | Delete user profile |
| POST | `/api/v1/dashboard/user/profile/firebase/token/update` | Update Firebase token |
| POST | `/api/v1/dashboard/user/profile/password/update` | Update password |
| GET | `/api/v1/dashboard/user/profile/liked/looks` | Get liked looks |
| GET | `/api/v1/dashboard/user/profile/notifications-statistic` | Get notifications statistics |
| GET | `/api/v1/dashboard/user/search-sending` | Search sending |
| POST | `/api/v1/dashboard/user/profile/phone/update-send-otp` | Update phone and send OTP |

### User Orders

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/orders/paginate` | Get paginated user orders |
| POST | `/api/v1/dashboard/user/orders/review/{id}` | Add order review |
| POST | `/api/v1/dashboard/user/orders/deliveryman-review/{id}` | Add deliveryman review |
| POST | `/api/v1/dashboard/user/orders/waiter-review/{id}` | Add waiter review |
| POST | `/api/v1/dashboard/user/orders/{id}/status/change` | Change order status |
| POST | `/api/v1/dashboard/user/orders/{id}/repeat` | Repeat order |
| DELETE | `/api/v1/dashboard/user/orders/{id}/delete-repeat` | Delete repeat order |
| GET | `/api/v1/dashboard/user/orders/{id}` | Get order by ID |
| POST | `/api/v1/dashboard/user/orders` | Create order |
| PUT | `/api/v1/dashboard/user/orders/{id}` | Update order |
| DELETE | `/api/v1/dashboard/user/orders/{id}` | Delete order |

### User Parcel Orders

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/parcel-orders` | Get all parcel orders |
| POST | `/api/v1/dashboard/user/parcel-orders` | Create parcel order |
| GET | `/api/v1/dashboard/user/parcel-orders/{id}` | Get parcel order by ID |
| PUT | `/api/v1/dashboard/user/parcel-orders/{id}` | Update parcel order |
| DELETE | `/api/v1/dashboard/user/parcel-orders/{id}` | Delete parcel order |
| POST | `/api/v1/dashboard/user/parcel-orders/{id}/status/change` | Change parcel order status |
| POST | `/api/v1/dashboard/user/parcel-orders/deliveryman-review/{id}` | Add deliveryman review for parcel order |

### User Addresses

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/dashboard/user/address/set-active/{id}` | Set active address |
| GET | `/api/v1/dashboard/user/address/get-active` | Get active address |
| GET | `/api/v1/dashboard/user/addresses` | Get all addresses |
| POST | `/api/v1/dashboard/user/addresses` | Create address |
| GET | `/api/v1/dashboard/user/addresses/{id}` | Get address by ID |
| PUT | `/api/v1/dashboard/user/addresses/{id}` | Update address |
| DELETE | `/api/v1/dashboard/user/addresses/{id}` | Delete address |

### User Invites

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/invites/paginate` | Get paginated invites |
| POST | `/api/v1/dashboard/user/shop/invitation/{uuid}/link` | Create shop invitation link |

### User Points and Wallet

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/point/histories` | Get point histories |
| GET | `/api/v1/dashboard/user/wallet/histories` | Get wallet histories |
| POST | `/api/v1/dashboard/user/wallet/withdraw` | Withdraw from wallet |
| POST | `/api/v1/dashboard/user/wallet/history/{uuid}/status/change` | Change wallet history status |
| POST | `/api/v1/dashboard/user/wallet/send` | Send wallet funds |

### User Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/transactions/paginate` | Get paginated transactions |
| GET | `/api/v1/dashboard/user/transactions/{id}` | Get transaction by ID |

### User Shop

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/dashboard/user/shops` | Create shop |
| GET | `/api/v1/dashboard/user/shops` | Get user shops |
| PUT | `/api/v1/dashboard/user/shops` | Update shop |

### User Request Models

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/request-models` | Get all request models |
| POST | `/api/v1/dashboard/user/request-models` | Create request model |
| GET | `/api/v1/dashboard/user/request-models/{id}` | Get request model by ID |
| PUT | `/api/v1/dashboard/user/request-models/{id}` | Update request model |
| DELETE | `/api/v1/dashboard/user/request-models/{id}` | Delete request model |

### User Tickets

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/dashboard/user/tickets/paginate` | Get paginated tickets |

## VFD Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/vfd/receipts/generate` | Generate a new fiscal receipt |
| GET | `/api/v1/vfd/receipts/{id}` | Get receipt by ID |
| GET | `/api/v1/vfd/receipts` | Get all receipts |

# Voice Order API Endpoints Reference

## Core Voice Processing Endpoints

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|--------------|
| `/api/voice/process` | POST | **Primary endpoint** - Process voice recordings and return product recommendations | Yes |
| `/api/voice-dialogue/process` | POST | Alternative endpoint for dialogue-based voice processing | Yes |
| `/api/v1/voice-order` | POST | REST API endpoint for voice order processing | Yes |
| `/api/voice-test-api` | POST | Test endpoint for development (no auth required) | No |

## Voice Order Management

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|--------------|
| `/api/voice-order/history` | GET | Get user's voice order history | Yes |
| `/api/voice-order/feedback` | POST | Submit feedback for a voice order | Yes |
| `/api/voice-order/repeat` | POST | Repeat a previous voice order | Yes |

## Request Parameters

For all voice processing endpoints:

```
POST /api/voice/process
Content-Type: multipart/form-data

Parameters:
- audio: File (audio/webm, audio/ogg, or audio/wav)
- session_id: String (optional, unique identifier for session)
- language: String (default: 'en-US')
```

## Response Format

All voice processing endpoints return responses in this format:

```json
{
  "success": true,
  "transcription": "User's transcribed speech",
  "intent_data": {
    "intent": "primary intent (e.g., burger)",
    "filters": ["vegetarian", "etc"],
    "cuisine_type": "American",
    "exclusions": ["item to exclude"],
    "portion_size": "Regular",
    "spice_level": "Not specified"
  },
  "recommendations": [
    {
      "id": 123,
      "slug": "product-slug",
      "img": "image-url",
      "translation": {
        "title": "Product Title",
        "description": "Product Description"
      },
      "stocks": [
        {
          "price": 12000,
          "quantity": 200
        }
      ]
    }
  ],
  "recommendation_text": "AI-generated recommendation text",
  "session_id": "unique-session-id",
  "log_id": 123,
  "voice_order_id": 456
}
```

## Cart Integration Endpoints

| Endpoint | Method | Description | Auth Required |
|----------|--------|-------------|--------------|
| `/api/cart/add` | POST | Add product to cart | Yes |
| `/api/orders/create` | POST | Create an order | Yes |

## Common Error Responses

```json
{
  "success": false,
  "message": "Error message description",
  "transcription_error": "Details about transcription failure (optional)"
}
```

## Audio Format Requirements

For optimal voice recognition:

- Sample Rate: 16 kHz (supported rates: 8000, 12000, 16000, 24000, 48000)
- Format: audio/webm with opus codec preferred
- Channels: 1 (mono)
- Echo cancellation and noise suppression recommended

## Authentication

All endpoints except `/api/voice-test-api` require authentication. Include authentication credentials with all requests:

```
Authorization: Bearer <token>
```

Or use cookie-based authentication with `credentials: 'include'` when using fetch.

## Text-based AI Chat Endpoints

| Endpoint | Method | Description | Authentication |
|----------|--------|-------------|----------------|
| `/api/v1/ai-chat` | POST | Process text-based food orders | Required |
| `/api/v1/ai-chat/context` | POST | Update conversation context | Required |

## Service Test Endpoints

| Endpoint | Method | Description | Authentication |
|----------|--------|-------------|----------------|
| `/api/v1/test-openai-key` | POST | Test OpenAI API key | None |
| `/api/v1/openai-chat` | GET/POST | Test OpenAI chat completion | None |
| `/public/voice-api-test.php?auth=testing` | GET/POST | Direct voice API testing | Query param `auth=testing` |
| `/public/voice-test.html` | GET | Voice API test interface | None |

## Diagnostic Endpoints

| Endpoint | Method | Description | Authentication |
|----------|--------|-------------|----------------|
| `/public/check-openai.php?auth=fixit` | GET | Check OpenAI API configuration | Query param `auth=fixit` |
| `/public/fix-credentials.php?auth=fixit` | GET | Fix Google credentials | Query param `auth=fixit` |
| `/public/clear-cache.php?auth=fixit` | GET | Clear Laravel cache | Query param `auth=fixit` |
| `/public/google-check.php` | GET | Check Google credentials status | None |

## Request/Response Reference

### Voice Order Processing

**Request:**
```json
{
  "audio": "base64_encoded_audio_data",
  "audio_format": "wav",  // optional, defaults to wav
  "language": "en-US",    // optional, defaults to en-US
  "user_id": 123,         // optional if authenticated
  "context": {            // optional context information
    "previous_orders": [],
    "preferences": {}
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Voice processed successfully",
  "data": {
    "transcription": "I want to order a cheeseburger with fries",
    "confidence": 0.95,
    "recommendations": [
      {
        "product_id": 101,
        "product_name": "Cheeseburger",
        "shop_id": 5,
        "shop_name": "Burger Place",
        "quantity": 1,
        "confidence": 0.92
      },
      {
        "product_id": 205,
        "product_name": "French Fries",
        "shop_id": 5,
        "shop_name": "Burger Place",
        "quantity": 1,
        "confidence": 0.85
      }
    ],
    "ai_suggestions": "Would you like to add a drink to your order?",
    "log_id": 456
  }
}
```

### Voice Transcription Only

**Request:**
```json
{
  "audio": "base64_encoded_audio_data",
  "audio_format": "wav",  // optional
  "language": "en-US"     // optional
}
```

**Response:**
```json
{
  "success": true,
  "transcription": "I want to order a cheeseburger with fries",
  "confidence": 0.95
}
```

### AI Chat Processing

**Request:**
```json
{
  "message": "I want to order a cheeseburger with fries",
  "user_id": 123,         // optional if authenticated
  "context": {            // optional context
    "previous_messages": [],
    "preferences": {}
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Chat processed successfully",
  "data": {
    "ai_response": "I'll help you order a cheeseburger with fries. Would you like to add a drink?",
    "recommendations": [
      {
        "product_id": 101,
        "product_name": "Cheeseburger",
        "shop_id": 5,
        "shop_name": "Burger Place",
        "quantity": 1
      },
      {
        "product_id": 205,
        "product_name": "French Fries",
        "shop_id": 5, 
        "shop_name": "Burger Place",
        "quantity": 1
      }
    ],
    "log_id": 789
  }
}
```

## Test Endpoints Usage

### Voice Test HTML Form
Access `/public/voice-test.html` in your browser to:
- Record audio directly from your browser
- Upload audio files for processing
- Test the transcription service without authentication

### Direct Voice API Testing
Access `/public/voice-api-test.php?auth=testing` to:
- Check the status of voice API services
- See detailed credential information
- Test audio transcription directly 