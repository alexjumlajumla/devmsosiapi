<?php

namespace Tests\Feature;

use App\Jobs\RetryFailedNotifications;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected $notificationService;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->notificationService = app(NotificationService::class);
        $this->user = User::factory()->create([
            'firebase_token' => 'test_token',
        ]);
    }

    /** @test */
    public function it_can_send_notification_to_user()
    {
        $notification = $this->notificationService->sendToUser(
            user: $this->user,
            title: 'Test Title',
            message: 'Test Message',
            type: 'test_type',
            data: ['key' => 'value']
        );

        $this->assertNotNull($notification);
        $this->assertEquals('Test Title', $notification->title);
        $this->assertEquals('Test Message', $notification->body);
        $this->assertEquals('test_type', $notification->type);
        $this->assertEquals(['key' => 'value'], $notification->data);
        $this->assertEquals(PushNotification::STATUS_SENT, $notification->status);
        $this->assertNotNull($notification->sent_at);
    }

    /** @test */
    public function it_handles_invalid_firebase_token()
    {
        $user = User::factory()->create([
            'firebase_token' => 'invalid_token',
        ]);

        $notification = $this->notificationService->sendToUser(
            user: $user,
            title: 'Test Title',
            message: 'Test Message',
            type: 'test_type',
        );

        $this->assertDatabaseHas('push_notifications', [
            'user_id' => $user->id,
            'status' => PushNotification::STATUS_FAILED,
        ]);
    }

    /** @test */
    public function it_can_mark_notification_as_read()
    {
        $notification = PushNotification::factory()->create([
            'user_id' => $this->user->id,
            'status' => PushNotification::STATUS_SENT,
        ]);

        $result = $this->notificationService->markAsRead($notification->id, $this->user->id);
        
        $this->assertTrue($result);
        $this->assertDatabaseHas('push_notifications', [
            'id' => $notification->id,
            'status' => PushNotification::STATUS_READ,
        ]);
    }

    /** @test */
    public function it_can_retry_failed_notifications()
    {
        $failedNotification = PushNotification::factory()->create([
            'user_id' => $this->user->id,
            'status' => PushNotification::STATUS_FAILED,
            'error_message' => 'Test error',
        ]);

        $job = new RetryFailedNotifications();
        $job->handle($this->notificationService);

        $this->assertDatabaseHas('push_notifications', [
            'id' => $failedNotification->id,
            'retry_attempts' => 1,
        ]);
    }

    /** @test */
    public function it_cleans_up_old_notifications()
    {
        // Create old notifications
        PushNotification::factory()->count(5)->create([
            'created_at' => now()->subDays(31),
        ]);

        $this->artisan('notifications:cleanup', ['--days' => 30])
             ->assertExitCode(0);

        $this->assertDatabaseCount('push_notifications', 0);
    }
}
