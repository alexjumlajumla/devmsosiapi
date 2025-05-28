<?php

namespace App\Channels;

use App\Services\FCM\FcmTokenService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class FcmChannel
{
    /**
     * @var FcmTokenService
     */
    private $fcmTokenService;

    /**
     * @param FcmTokenService $fcmTokenService
     */
    public function __construct(FcmTokenService $fcmTokenService)
    {
        $this->fcmTokenService = $fcmTokenService;
    }

    /**
     * Send the given notification.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     * @return void
     * @throws \Kreait\Firebase\Exception\FirebaseException
     * @throws \Kreait\Firebase\Exception\MessagingException
     */
    public function send($notifiable, Notification $notification)
    {
        if (!method_exists($notification, 'toFcm')) {
            Log::warning('Notification is missing toFcm method', [
                'notification' => get_class($notification),
                'notifiable' => get_class($notifiable),
            ]);
            return;
        }

        // Get the FCM message data from the notification
        $fcmMessage = $notification->toFcm($notifiable);
        
        if (empty($fcmMessage)) {
            Log::warning('Empty FCM message', [
                'notification' => get_class($notification),
                'notifiable_id' => $notifiable->id,
            ]);
            return;
        }

        // Get tokens for the notifiable entity
        $tokens = $this->fcmTokenService->getTokensForNotifiable($notifiable);
        
        if (empty($tokens)) {
            Log::debug('No FCM tokens found for notifiable', [
                'notifiable_id' => $notifiable->id,
                'notification' => get_class($notification),
            ]);
            return;
        }

        // Prepare the message
        $message = $this->prepareMessage($fcmMessage);
        
        // Send to each token
        $results = [];
        $invalidTokens = [];
        
        foreach ($tokens as $token) {
            try {
                $message = $message->withChangedTarget('token', $token);
                $response = app('firebase.messaging')->send($message);
                $results[$token] = [
                    'success' => true,
                    'message_id' => $response,
                ];
            } catch (\Kreait\Firebase\Exception\Messaging\InvalidMessage $e) {
                Log::error('Invalid FCM message', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                    'notification' => get_class($notification),
                ]);
                $results[$token] = [
                    'success' => false,
                    'error' => 'Invalid message: ' . $e->getMessage(),
                ];
            } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
                Log::warning('FCM token not found or not registered', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                ]);
                $invalidTokens[] = $token;
                $results[$token] = [
                    'success' => false,
                    'error' => 'Token not registered: ' . $e->getMessage(),
                ];
            } catch (\Kreait\Firebase\Exception\Messaging\AuthenticationError $e) {
                Log::error('FCM authentication error', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                ]);
                $results[$token] = [
                    'success' => false,
                    'error' => 'Authentication error: ' . $e->getMessage(),
                ];
            } catch (\Exception $e) {
                Log::error('Failed to send FCM notification', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $results[$token] = [
                    'success' => false,
                    'error' => 'Failed to send: ' . $e->getMessage(),
                ];
            }
        }

        // Remove invalid tokens
        if (!empty($invalidTokens)) {
            $this->fcmTokenService->removeInvalidTokens($notifiable, $invalidTokens);
        }

        // Log the results
        $successCount = count(array_filter($results, function($r) { return $r['success']; }));
        $failureCount = count($results) - $successCount;
        
        Log::info('FCM notification sent', [
            'notifiable_id' => $notifiable->id,
            'notification' => get_class($notification),
            'total_recipients' => count($tokens),
            'successful' => $successCount,
            'failed' => $failureCount,
            'invalid_tokens_removed' => count($invalidTokens),
        ]);

        // Call the sent method on the notification if it exists
        if (method_exists($notification, 'sent')) {
            $notification->sent($notifiable, $results);
        }
    }

    /**
     * Prepare the FCM message from the notification data
     *
     * @param array $fcmMessage
     * @return CloudMessage
     */
    protected function prepareMessage(array $fcmMessage): CloudMessage
    {
        $message = CloudMessage::new();
        
        // Set notification data if provided
        if (isset($fcmMessage['notification'])) {
            $message = $message->withNotification(
                FcmNotification::fromArray($fcmMessage['notification'])
            );
        }
        
        // Set data payload if provided
        if (isset($fcmMessage['data'])) {
            $message = $message->withData($fcmMessage['data']);
        }
        
        // Set Android config if provided
        if (isset($fcmMessage['android'])) {
            $message = $message->withAndroidConfig($fcmMessage['android']);
        }
        
        // Set APNS config if provided
        if (isset($fcmMessage['apns'])) {
            $message = $message->withApnsConfig($fcmMessage['apns']);
        }
        
        // Set web push config if provided
        if (isset($fcmMessage['webpush'])) {
            $message = $message->withWebPushConfig($fcmMessage['webpush']);
        }
        
        // Set priority if provided
        if (isset($fcmMessage['priority'])) {
            $message = $message->withPriority($fcmMessage['priority']);
        }
        
        // Set content available flag if provided
        if (isset($fcmMessage['content_available'])) {
            $message = $message->withContentAvailable($fcmMessage['content_available']);
        }
        
        // Set mutable content flag if provided (for iOS)
        if (isset($fcmMessage['mutable_content'])) {
            $message = $message->withMutableContent($fcmMessage['mutable_content']);
        }
        
        return $message;
    }
}
