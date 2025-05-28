<?php

namespace App\Traits;

use App\Models\PushNotification;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\App;

/**
 * Trait Notifiable
 * 
 * This trait can be added to any model to enable notification capabilities.
 */
trait Notifiable
{
    /**
     * Get the notification service instance.
     */
    protected function getNotificationService(): NotificationService
    {
        return App::make(NotificationService::class);
    }

    /**
     * Send a notification to the user.
     * 
     * @param string $title
     * @param string $message
     * @param string $type
     * @param array $data
     * @return PushNotification|null
     */
    public function notify(string $title, string $message, string $type, array $data = []): ?PushNotification
    {
        if (!method_exists($this, 'user') || !$this->user) {
            return null;
        }

        return $this->getNotificationService()->sendToUser(
            user: $this->user,
            title: $title,
            message: $message,
            type: $type,
            data: $data
        );
    }

    /**
     * Mark a notification as read.
     * 
     * @param int $notificationId
     * @return bool
     */
    public function markNotificationAsRead(int $notificationId): bool
    {
        return $this->getNotificationService()->markAsRead($notificationId, $this->id);
    }

    /**
     * Mark a notification as delivered.
     * 
     * @param int $notificationId
     * @return bool
     */
    public function markNotificationAsDelivered(int $notificationId): bool
    {
        return $this->getNotificationService()->markAsDelivered($notificationId, $this->id);
    }

    /**
     * Get the number of unread notifications.
     * 
     * @return int
     */
    public function unreadNotificationsCount(): int
    {
        return $this->getNotificationService()->getUnreadCount($this->id);
    }
}
