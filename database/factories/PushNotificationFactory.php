<?php

namespace Database\Factories;

use App\Models\PushNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PushNotificationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PushNotification::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'type' => $this->faker->randomElement([
                PushNotification::NEW_ORDER,
                PushNotification::ORDER_STATUS_CHANGED,
                PushNotification::NEW_MESSAGE,
            ]),
            'title' => $this->faker->sentence,
            'body' => $this->faker->paragraph,
            'data' => [],
            'status' => $this->faker->randomElement([
                PushNotification::STATUS_PENDING,
                PushNotification::STATUS_SENT,
                PushNotification::STATUS_DELIVERED,
                PushNotification::STATUS_READ,
                PushNotification::STATUS_FAILED,
            ]),
            'sent_at' => $this->faker->optional(0.8)->dateTimeThisYear,
            'delivered_at' => function (array $attributes) {
                return $this->faker->optional(0.7, null)->dateTimeBetween(
                    $attributes['sent_at'] ?? '-1 month',
                    'now'
                );
            },
            'read_at' => function (array $attributes) {
                return $this->faker->optional(0.6, null)->dateTimeBetween(
                    $attributes['delivered_at'] ?? ($attributes['sent_at'] ?? '-1 month'),
                    'now'
                );
            },
            'error_message' => $this->faker->optional(0.2)->sentence,
            'retry_attempts' => $this->faker->optional(0.3, 0)->numberBetween(0, 3),
            'last_retry_at' => $this->faker->optional(0.3)->dateTimeThisMonth,
        ];
    }

    /**
     * Configure the model factory with a specific status.
     */
    public function withStatus(string $status)
    {
        return $this->state(function (array $attributes) use ($status) {
            return [
                'status' => $status,
                'sent_at' => in_array($status, [
                    PushNotification::STATUS_SENT,
                    PushNotification::STATUS_DELIVERED,
                    PushNotification::STATUS_READ,
                ]) ? now() : null,
                'delivered_at' => in_array($status, [
                    PushNotification::STATUS_DELIVERED,
                    PushNotification::STATUS_READ,
                ]) ? now() : null,
                'read_at' => $status === PushNotification::STATUS_READ ? now() : null,
                'error_message' => $status === PushNotification::STATUS_FAILED 
                    ? 'Test error message' 
                    : null,
            ];
        });
    }
}
