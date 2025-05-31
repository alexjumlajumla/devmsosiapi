<?php

use App\Models\Order;
use App\Services\Order\VfdReceiptService;

if (!function_exists('vfd_receipt')) {
    /**
     * Get the VFD receipt service instance.
     *
     * @return VfdReceiptService
     */
    function vfd_receipt(): VfdReceiptService
    {
        return app(VfdReceiptService::class);
    }
}

if (!function_exists('has_vfd_receipt')) {
    /**
     * Check if an order has a VFD receipt.
     *
     * @param Order $order
     * @return bool
     */
    function has_vfd_receipt(Order $order): bool
    {
        return vfd_receipt()->hasReceipt($order);
    }
}

if (!function_exists('get_vfd_receipt_url')) {
    /**
     * Get the VFD receipt URL for an order.
     *
     * @param Order $order
     * @return string|null
     */
    function get_vfd_receipt_url(Order $order): ?string
    {
        return vfd_receipt()->getReceiptUrl($order);
    }
}

if (!function_exists('generate_vfd_receipt')) {
    /**
     * Generate a VFD receipt for an order.
     *
     * @param Order $order
     * @param string $paymentMethod
     * @return array
     */
    function generate_vfd_receipt(Order $order, string $paymentMethod = 'cash'): array
    {
        return vfd_receipt()->generateForOrder($order, $paymentMethod);
    }
}
