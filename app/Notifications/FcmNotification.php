<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class FcmNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var string
     */
    public $title;

    /**
     * @var string
     */
    public $message;

    /**
     * @var array
     */
    public $data;

    /**
     * @var string
     */
    public $type;

    /**
     * Create a new notification instance.
     *
     * @param string $title
     * @param string $message
     * @param array $data
     * @param string $type
     * @return void
     */
    public function __construct(string $title, string $message, array $data = [], string $type = 'general')
    {
        $this->title = $title;
        $this->message = $message;
        $this->data = $data;
        $this->type = $type;

        // Set queue for this notification
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database', 'fcm'];
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
                    ->line($this->title)
                    ->line($this->message)
                    ->action('View Notification', url('/notifications'))
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
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'data' => $this->data,
            'read_at' => null,
        ];
    }

    /**
     * Get the FCM representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toFcm($notifiable)
    {
        Log::debug('Sending FCM notification', [
            'notifiable_id' => $notifiable->id,
            'title' => $this->title,
            'type' => $this->type,
            'data' => $this->data,
        ]);

        return [
            'notification' => [
                'title' => $this->title,
                'body' => $this->message,
                'sound' => 'default',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ],
            'data' => array_merge($this->data, [
                'type' => $this->type,
                'title' => $this->title,
                'body' => $this->message,
                'timestamp' => now()->toDateTimeString(),
            ]),
            'priority' => 'high',
            'content_available' => true,
            'mutable_content' => true,
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'badge' => 1,
                        'mutable-content' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * Get the notification's delivery delay time.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function withDelay($notifiable)
    {
        return [
            'fcm' => now()->addSeconds(5), // Delay FCM notification by 5 seconds
        ];
    }
}
