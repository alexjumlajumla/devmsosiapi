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
        
        // Verify Firebase authentication is working
        try {
            $auth = app('firebase.auth');
            $auth->getApiClient(); // This will throw an exception if authentication fails
        } catch (\Exception $e) {
            Log::error('Firebase authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Check if it's an authentication error that we can recover from
            if (str_contains($e->getMessage(), 'invalid_grant') || 
                str_contains($e->getMessage(), 'invalid_credentials') ||
                str_contains($e->getMessage(), 'unsupported_grant_type')) {
                
                // Try to reinitialize the Firebase app
                try {
                    $this->reinitializeFirebaseApp();
                } catch (\Exception $reinitEx) {
                    Log::critical('Failed to reinitialize Firebase app', [
                        'error' => $reinitEx->getMessage(),
                        'trace' => $reinitEx->getTraceAsString(),
                    ]);
                    return; // Give up if we can't reinitialize
                }
            } else {
                return; // Unknown error, give up
            }
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
                $errorMessage = $e->getMessage();
                Log::error('FCM authentication error', [
                    'token' => $token,
                    'error' => $errorMessage,
                ]);
                
                // If it's a token error, try to refresh the token
                if (str_contains($errorMessage, 'invalid_grant') || 
                    str_contains($errorMessage, 'invalid_credentials') ||
                    str_contains($errorMessage, 'unsupported_grant_type')) {
                    
                    try {
                        $this->reinitializeFirebaseApp();
                        
                        // Retry sending the message
                        $message = $this->prepareMessage($fcmMessage);
                        $message = $message->withChangedTarget('token', $token);
                        $response = app('firebase.messaging')->send($message);
                        
                        $results[$token] = [
                            'success' => true,
                            'message_id' => $response,
                            'retry_success' => true,
                        ];
                        continue; // Skip to next token
                        
                    } catch (\Exception $retryEx) {
                        Log::error('Failed to retry FCM notification after reinitialization', [
                            'token' => $token,
                            'error' => $retryEx->getMessage(),
                            'trace' => $retryEx->getTraceAsString(),
                        ]);
                    }
                }
                
                $results[$token] = [
                    'success' => false,
                    'error' => 'Authentication error: ' . $errorMessage,
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
     * Prepare the FCM message with proper configuration.
     *
     * @param  mixed  $fcmMessage
     * @return \Kreait\Firebase\Messaging\Message
     */
    /**
     * Reinitialize the Firebase application
     * 
     * @throws \RuntimeException If reinitialization fails
     */
    protected function reinitializeFirebaseApp()
    {
        try {
            // Clear any existing Firebase instances
            $this->app->forgetInstance('firebase');
            $this->app->forgetInstance('firebase.auth');
            $this->app->forgetInstance('firebase.messaging');
            
            // Rebind the Firebase service provider
            $provider = new \App\Providers\FirebaseServiceProvider($this->app);
            $provider->register();
            $provider->boot();
            
            Log::info('Successfully reinitialized Firebase application');
            
        } catch (\Exception $e) {
            Log::error('Failed to reinitialize Firebase application', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new \RuntimeException('Failed to reinitialize Firebase application: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Prepare the FCM message with proper configuration.
     *
     * @param  mixed  $fcmMessage
     * @return \Kreait\Firebase\Messaging\Message
     */
    protected function prepareMessage($fcmMessage)
    {
        if ($fcmMessage instanceof CloudMessage) {
            return $this->applyDefaultConfig($fcmMessage);
        }
        
        if (is_array($fcmMessage)) {
            // Create a new CloudMessage instance
            $message = CloudMessage::new();
            
            // Prepare notification data
            $notificationData = [];
            
            // Handle both formats: with 'notification' key and direct title/body keys
            if (isset($fcmMessage['notification']) && is_array($fcmMessage['notification'])) {
                $notificationData = $fcmMessage['notification'];
            } else {
                // For backward compatibility with older format
                if (isset($fcmMessage['title']) || isset($fcmMessage['body'])) {
                    $notificationData = [
                        'title' => $fcmMessage['title'] ?? $this->config['default_title'] ?? 'New Notification',
                        'body' => $fcmMessage['body'] ?? $this->config['default_body'] ?? 'You have a new notification',
                    ];
                }
            }
            
            // Set notification if we have data
            if (!empty($notificationData)) {
                $message = $message->withNotification($notificationData);
            }
            
            // Set data if provided
            if (isset($fcmMessage['data']) && is_array($fcmMessage['data'])) {
                $message = $message->withData($fcmMessage['data']);
            }
            
            // Set other message properties
            if (isset($fcmMessage['android'])) {
                $message = $message->withAndroidConfig($fcmMessage['android']);
            }
            
            if (isset($fcmMessage['apns'])) {
                $message = $message->withApnsConfig($fcmMessage['apns']);
            }
            
            if (isset($fcmMessage['webpush'])) {
                $message = $message->withWebPushConfig($fcmMessage['webpush']);
            }
            
            if (isset($fcmMessage['priority'])) {
                // For Android, set priority in the android config
                $androidConfig = $fcmMessage['android'] ?? [];
                $androidConfig['priority'] = $fcmMessage['priority'];
                $message = $message->withAndroidConfig($androidConfig);
                
                // For APNS (iOS), set priority in the apns config
                $apnsConfig = $fcmMessage['apns'] ?? [];
                $apnsConfig['headers'] = $apnsConfig['headers'] ?? [];
                $apnsConfig['headers']['apns-priority'] = $fcmMessage['priority'] == 'high' ? '10' : '5';
                $message = $message->withApnsConfig($apnsConfig);
            }
            
            return $this->applyDefaultConfig($message);
        }
        
        throw new \InvalidArgumentException('Invalid FCM message format. Expected array or CloudMessage instance.');
    }
    
    /**
     * Apply default configuration to the message.
     *
     * @param  CloudMessage  $message
     * @return CloudMessage
     */
    protected function applyDefaultConfig(CloudMessage $message): CloudMessage
    {
        // Get notification data from the message
        $notification = [];
        $messageData = [];
        
        if (method_exists($message, 'jsonSerialize')) {
            $messageData = $message->jsonSerialize();
            
            // Handle different formats of notification data
            if (isset($messageData['notification']) && is_array($messageData['notification'])) {
                $notification = $messageData['notification'];
            } elseif (isset($messageData['notification']) && is_string($messageData['notification'])) {
                $notification = ['body' => $messageData['notification']];
            }
            
            // Ensure priority is set for both Android and iOS
            $priority = $messageData['priority'] ?? 'high';
            
            // Get existing configs
            $androidConfig = $messageData['android'] ?? [];
            $apnsConfig = $messageData['apns'] ?? [];
            
            // Set Android priority
            $androidConfig['priority'] = $androidConfig['priority'] ?? $priority;
            
            // Set APNS priority (iOS)
            $apnsConfig['headers'] = $apnsConfig['headers'] ?? [];
            $apnsConfig['headers']['apns-priority'] = $apnsConfig['headers']['apns-priority'] ?? ($priority === 'high' ? '10' : '5');
            
            // Apply the configs
            $message = $message->withAndroidConfig($androidConfig);
            $message = $message->withApnsConfig($apnsConfig);
        }

        // Apply Android config
        $androidConfig = [
            'priority' => $this->config['android_priority'] ?? 'high',
            'ttl' => $this->config['android_ttl'] ?? '2419200s', // 28 days
            'notification' => [
                'channel_id' => $this->config['default_channel_id'] ?? 'fcm_default_channel',
                'sound' => $this->config['default_sound'] ?? 'default',
                'icon' => $this->config['default_notification_icon'] ?? 'notification_icon',
                'color' => $this->config['default_notification_color'] ?? '#FF0000',
                'click_action' => $this->config['default_click_action'] ?? 'FLUTTER_NOTIFICATION_CLICK',
            ],
        ];
        
        // Prepare APNS (iOS) config
        $apnsConfig = [
            'headers' => [
                'apns-priority' => $this->config['apns_priority'] ?? '10',
                'apns-push-type' => $this->config['apns_push_type'] ?? 'alert',
            ],
            'payload' => [
                'aps' => [
                    'alert' => [
                        'title' => $notification['title'] ?? '',
                        'body' => $notification['body'] ?? '',
                    ],
                    'sound' => $this->config['default_sound'] ?? 'default',
                    'badge' => 1,
                    'mutable-content' => 1,
                ],
            ],
        ];
        
        // Apply the configurations to the message
        $message = $message
            ->withAndroidConfig($androidConfig)
            ->withApnsConfig($apnsConfig);
        
        // Set additional configurations if provided in the message data
        if (isset($messageData)) {
            // Set APNS config if provided
            if (isset($messageData['apns'])) {
                $message = $message->withApnsConfig($messageData['apns']);
            }
            
            // Set web push config if provided
            if (isset($messageData['webpush'])) {
                $message = $message->withWebPushConfig($messageData['webpush']);
            }
            
            // Set priority if provided
            if (isset($messageData['priority'])) {
                $message = $message->withPriority($messageData['priority']);
            }
            
            // Set content available flag if provided
            if (isset($messageData['content_available'])) {
                $message = $message->withContentAvailable($messageData['content_available']);
            }
            
            // Set mutable content flag if provided (for iOS)
            if (isset($messageData['mutable_content'])) {
                $message = $message->withMutableContent($messageData['mutable_content']);
            }
        }
        
        return $message;
    }
}
