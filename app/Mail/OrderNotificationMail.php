<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $message;

    /**
     * Create a new message instance.
     *
     * @param $order
     * @param $message
     */
    public function __construct($order, $message)
    {
        $this->order = $order;
        $this->message = $message;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Order Notification')
                    ->view('emails.order_notification');
    }
}
