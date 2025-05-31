<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\VfdReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

class VfdReceiptFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = VfdReceipt::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'receipt_number' => 'VFD-' . time() . '-' . $this->faker->randomNumber(4),
            'receipt_url' => $this->faker->url,
            'vfd_response' => json_encode(['success' => true, 'receiptUrl' => $this->faker->url]),
            'receipt_type' => $this->faker->randomElement([
                VfdReceipt::TYPE_DELIVERY,
                VfdReceipt::TYPE_SUBSCRIPTION,
            ]),
            'model_id' => Order::factory(),
            'model_type' => Order::class,
            'amount' => $this->faker->numberBetween(1000, 100000), // 10.00 to 1000.00
            'payment_method' => $this->faker->randomElement(['cash', 'card', 'bank_transfer']),
            'customer_name' => $this->faker->name,
            'customer_phone' => $this->faker->phoneNumber,
            'customer_email' => $this->faker->safeEmail,
            'status' => $this->faker->randomElement([
                VfdReceipt::STATUS_PENDING,
                VfdReceipt::STATUS_GENERATED,
                VfdReceipt::STATUS_FAILED,
            ]),
            'error_message' => $this->faker->boolean(20) ? $this->faker->sentence : null,
        ];
    }

    /**
     * Set the receipt type to delivery.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function delivery()
    {
        return $this->state(function (array $attributes) {
            return [
                'receipt_type' => VfdReceipt::TYPE_DELIVERY,
            ];
        });
    }

    /**
     * Set the receipt type to subscription.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function subscription()
    {
        return $this->state(function (array $attributes) {
            return [
                'receipt_type' => VfdReceipt::TYPE_SUBSCRIPTION,
            ];
        });
    }

    /**
     * Set the receipt status to pending.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function pending()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => VfdReceipt::STATUS_PENDING,
            ];
        });
    }

    /**
     * Set the receipt status to generated.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function generated()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => VfdReceipt::STATUS_GENERATED,
            ];
        });
    }

    /**
     * Set the receipt status to failed.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function failed()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => VfdReceipt::STATUS_FAILED,
                'error_message' => $this->faker->sentence,
            ];
        });
    }
}
