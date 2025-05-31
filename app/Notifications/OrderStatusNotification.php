<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

class OrderStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $order;
    protected $status;
    protected $title;
    protected $body;

    /**
     * Create a new notification instance.
     *
     * @param Order $order
     * @param string $status
     * @param string $title
     * @param string $body
     */
    public function __construct(Order $order, string $status, string $title = null, string $body = null)
    {
        $this->order = $order;
        $this->status = $status;
        $this->title = $title ?? $this->getDefaultTitle($status);
        $this->body = $body ?? $this->getDefaultBody($status);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        $channels = ['database'];
        
        // Only send FCM if the user has a token
        if (!empty($notifiable->firebase_token)) {
            // Convert to array if it's a single token
            $tokens = is_array($notifiable->firebase_token) 
                ? $notifiable->firebase_token 
                : [$notifiable->firebase_token];
                
            // Filter out empty tokens
            $tokens = array_filter($tokens);
            
            if (!empty($tokens)) {
                $channels[] = 'fcm';
            }
        }
        
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->line('The order status has been updated.')
                    ->action('View Order', url('/orders/'.$this->order->id))
                    ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'order_id' => $this->order->id,
            'status' => $this->status,
            'title' => $this->title,
            'body' => $this->body,
            'type' => 'order_status_update',
            'created_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Get the FCM representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array|string
     */
    public function toFcm($notifiable)
    {
        // Return the FCM message
        return CloudMessage::new()
            ->withNotification(FirebaseNotification::create($this->title, $this->body))
            ->withData([
                'type' => 'order_status_update',
                'order_id' => (string) $this->order->id,
                'status' => $this->status,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ])
            ->withDefaultSounds()
            ->withAndroidConfig([
                'notification' => [
                    'channel_id' => config('fcm.default_channel_id'),
                    'sound' => config('fcm.default_sound'),
                    'icon' => config('fcm.default_notification_icon'),
                    'color' => config('fcm.default_notification_color'),
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]);
    }

    /**
     * Get the default title for the notification.
     *
     * @param string $status
     * @return string
     */
    protected function getDefaultTitle(string $status): string
    {
        $titles = [
            'new' => 'New Order #' . $this->order->id,
            'processing' => 'Order #' . $this->order->id . ' is being processed',
            'on_the_way' => 'Order #' . $this->order->id . ' is on the way',
            'delivered' => 'Order #' . $this->order->id . ' has been delivered',
            'cancelled' => 'Order #' . $this->order->id . ' has been cancelled',
            'refunded' => 'Order #' . $this->order->id . ' has been refunded',
            'default' => 'Order #' . $this->order->id . ' status updated',
        ];

        return $titles[strtolower($status)] ?? $titles['default'];
    }

    /**
     * Get the default body for the notification.
     *
     * @param string $status
     * @return string
     */
    protected function getDefaultBody(string $status): string
    {
        $bodies = [
            'new' => 'A new order has been placed. Order #' . $this->order->id,
            'processing' => 'Your order is being prepared. It will be ready soon!',
            'on_the_way' => 'Your order is on the way to you!',
            'delivered' => 'Your order has been delivered. Enjoy!',
            'cancelled' => 'Your order has been cancelled.',
            'refunded' => 'Your order has been refunded.',
            'default' => 'The status of your order has been updated.',
        ];

        return $bodies[strtolower($status)] ?? $bodies['default'];
    }
}
