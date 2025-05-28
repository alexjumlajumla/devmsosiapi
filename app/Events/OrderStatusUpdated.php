<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $oldStatus;
    public $newStatus;
    public $reason;

    /**
     * Create a new event instance.
     *
     * @param Order $order
     * @param string $oldStatus
     * @param string $newStatus
     * @param string|null $reason
     */
    public function __construct(Order $order, string $oldStatus, string $newStatus, ?string $reason = null)
    {
        $this->order = $order;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->reason = $reason;
    }
}
