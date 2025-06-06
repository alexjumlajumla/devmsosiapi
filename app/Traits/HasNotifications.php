<?php

namespace App\Traits;

use App\Models\User;
use App\Notifications\OrderStatusNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Trait HasNotifications
 * 
 * This trait provides notification functionality to any model that uses it.
 */
trait HasNotifications
{
    /**
     * Send a notification to the given users.
     *
     * @param  User|array|\Illuminate\Support\Collection  $users
     * @param  Notification  $notification
     * @return void
     */
    public function notifyUsers($users, Notification $notification)
    {
        try {
            if ($users instanceof User) {
                $users = [$users];
            } elseif (!is_array($users) && !$users instanceof \Illuminate\Support\Collection) {
                throw new \InvalidArgumentException('Users must be an instance of User, array, or Collection');
            }

            foreach ($users as $user) {
                if ($user instanceof User) {
                    $user->notify($notification);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Send an order status notification.
     *
     * @param  User|array|\Illuminate\Support\Collection  $users
     * @param  string  $status
     * @param  string|null  $title
     * @param  string|null  $body
     * @return void
     */
    public function notifyOrderStatus($users, string $status, ?string $title = null, ?string $body = null)
    {
        $notification = new OrderStatusNotification($this, $status, $title, $body);
        $this->notifyUsers($users, $notification);
    }

    /**
     * Get the notification routing information for the FCM channel.
     * This method is used by the FcmChannel to get the FCM tokens.
     *
     * @return array
     */
    public function routeNotificationForFcm()
    {
        // If the model has getFcmTokens method, use it
        if (method_exists($this, 'getFcmTokens')) {
            return $this->getFcmTokens();
        }
        
        // Fallback to firebase_token column for backward compatibility
        if (isset($this->firebase_token)) {
            if (is_string($this->firebase_token) && $this->isJson($this->firebase_token)) {
                return json_decode($this->firebase_token, true) ?: [];
            }
            
            if (is_array($this->firebase_token)) {
                return $this->firebase_token;
            }
            
            if (is_string($this->firebase_token) && !empty($this->firebase_token)) {
                return [$this->firebase_token];
            }
        }
        
        return [];
    }
    
    /**
     * Check if a string is valid JSON
     * 
     * @param string $string
     * @return bool
     */
    protected function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get the notification routing information for the given channel.
     * This is a fallback method that won't conflict with Laravel's Notifiable trait.
     *
     * @param  string  $channel
     * @param  \Illuminate\Notifications\Notification|null  $notification
     * @return mixed
     */
    public function getNotificationRouting($channel, $notification = null)
    {
        if ($channel === 'fcm') {
            return $this->routeNotificationForFcm();
        }

        $method = 'routeNotificationFor'.Str::studly($channel);
        if (method_exists($this, $method)) {
            return $this->{$method}($notification);
        }

        return [];
    }
}
