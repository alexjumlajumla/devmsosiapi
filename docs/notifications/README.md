# Notification System

This document provides an overview of the notification system and how to use it.

## Overview

The notification system provides a robust way to send push notifications to users through Firebase Cloud Messaging (FCM). It includes features like delivery tracking, retry mechanisms, and cleanup of old notifications.

## Key Components

1. **NotificationService**: Main service for sending and managing notifications
2. **FirebaseTokenService**: Handles Firebase token verification and cleanup
3. **PushNotification Model**: Database model for storing notification records
4. **Console Commands**: For maintenance tasks like cleanup and retries
5. **Jobs**: Background jobs for handling retries

## Sending Notifications

### Basic Usage

```php
use App\Services\Notification\NotificationService;
use App\Models\User;

// Inject the service or use the facade
$notificationService = app(NotificationService::class);

// Get the user
$user = User::find(1);

// Send a notification
$notification = $notificationService->sendToUser(
    user: $user,
    title: 'Order Update',
    message: 'Your order #123 has been shipped',
    type: 'order_status',
    data: [
        'order_id' => 123,
        'status' => 'shipped'
    ]
);
```

### Using the Notifiable Trait

Any model can use the `Notifiable` trait to send notifications:

```php
use App\Traits\Notifiable;

class Order extends Model
{
    use Notifiable;
    
    // ...
}

// Then in your code:
$order->notify(
    'Order Update',
    'Your order has been shipped',
    'order_shipped',
    ['order_id' => $order->id]
);
```

## Console Commands

### Test the Notification System

```bash
php artisan notifications:test
```

### Clean Up Old Notifications

```bash
# Clean up notifications older than 30 days (default)
php artisan notifications:cleanup

# Clean up notifications older than 60 days
php artisan notifications:cleanup --days=60

# Dry run to see what would be deleted
php artisan notifications:cleanup --dry-run
```

### Retry Failed Notifications

```bash
# Manually trigger retry of failed notifications
php artisan notifications:retry-failed
```

## Scheduled Tasks

The following tasks are scheduled to run automatically:

- **Daily at 2:00 AM**: Clean up old notifications (default: older than 30 days)
- **Every 5 minutes**: Retry failed notifications

## Testing

Run the test suite to ensure the notification system is working correctly:

```bash
php artisan test tests/Feature/NotificationTest.php
```

## Best Practices

1. **Use Meaningful Types**: Use consistent and meaningful notification types for better tracking and filtering.
2. **Include Relevant Data**: Always include relevant data in the notification payload to handle it properly in the frontend.
3. **Handle Failures**: Implement proper error handling and retry logic for failed notifications.
4. **Monitor Performance**: Keep an eye on the performance of the notification system, especially when sending to many users.
5. **Clean Up**: Regularly clean up old notifications to keep the database size in check.

## Troubleshooting

### Common Issues

1. **Notifications not being delivered**:
   - Check if the Firebase token is valid and not expired
   - Verify that the user has granted notification permissions
   - Check the error logs for any delivery failures

2. **High failure rate**:
   - Check the `error_message` field in the notifications table
   - Verify Firebase credentials and configuration
   - Check if you're hitting rate limits

3. **Performance issues**:
   - Consider using queues for sending notifications in bulk
   - Monitor database performance and add indexes if needed

### Checking Logs

All notification-related errors are logged to Laravel's default log file:

```bash
tail -f storage/logs/laravel.log | grep -i notification
```

## API Endpoints

### Mark Notification as Read

```
POST /api/v1/notifications/{id}/read
```

### Mark Notification as Delivered

```
POST /api/v1/notifications/{id}/delivered
```

### List Notifications

```
GET /api/v1/notifications
```

### Get Unread Count

```
GET /api/v1/notifications/unread-count
```
