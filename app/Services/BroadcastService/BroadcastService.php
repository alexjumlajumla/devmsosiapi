<?php

namespace App\Services\BroadcastService;

use App\Mail\BroadcastMailable;
use App\Models\User;
use App\Models\Broadcast;
use App\Services\CoreService;
use App\Traits\Notification;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

class BroadcastService extends CoreService
{
    use Notification; // gives sendNotificationSimple

    protected function getModelClass(): string
    {
        // not persisting; we return User class
        return User::class;
    }

    /**
     * Send broadcast to selected user groups through chosen channels.
     *
     * @param array $payload validated data from request
     * @return array stats
     */
    public function send(array $payload): array
    {
        $groups   = $payload['groups'];      // ['admin','seller'] etc.
        $channels = $payload['channels'];    // ['push','email']
        $customEmails = $payload['custom_emails'] ?? [];

        // decode body if it looks base64
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $payload['body'] ?? '')) {
            $decoded = base64_decode($payload['body'], true);
            if ($decoded !== false) {
                $payload['body'] = $decoded;
            }
        }

        // Ensure referenced roles exist (idempotent)
        foreach ($groups as $roleName) {
            Role::findOrCreate($roleName);
        }

        $roleGroups = array_diff($groups, ['user']);

        $query = User::query();

        // Log the initial request
        \Log::debug('Starting broadcast with parameters', [
            'groups' => $groups,
            'channels' => $channels,
            'role_groups' => $roleGroups,
            'custom_emails_count' => count($customEmails)
        ]);

        // Get all users if 'all' is in groups
        if (in_array('all', $groups)) {
            \Log::debug('Broadcasting to all users');
            // No additional where clauses needed, we want all users
        } 
        // Handle role-based filtering
        else if (!empty($roleGroups) || in_array('user', $groups)) {
            $query->where(function($q) use ($roleGroups, $groups) {
                // Include users with specific roles if any role groups are specified
                if (!empty($roleGroups)) {
                    $q->whereHas('roles', function($roleQuery) use ($roleGroups) {
                        $roleQuery->whereIn('name', $roleGroups);
                    });
                }
                
                // Include users without any roles when 'user' is in groups
                if (in_array('user', $groups)) {
                    if (!empty($roleGroups)) {
                        $q->orWhereDoesntHave('roles');
                    } else {
                        $q->whereDoesntHave('roles');
                    }
                }
            });
            
            // Log the query for debugging
            \Log::debug('Broadcast user query', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'role_groups' => $roleGroups,
                'all_groups' => $groups
            ]);
        } else {
            \Log::warning('No valid user groups specified for broadcast', [
                'groups' => $groups,
                'role_groups' => $roleGroups
            ]);
        }

        $stats = [
            'emailed' => 0,
            'pushed'  => 0,
            'total'   => 0,
            'custom_emailed' => 0,
        ];

        $query->chunkById(500, function ($users) use ($payload, $channels, &$stats, $customEmails) {
            \Log::debug('Processing chunk of users', [
                'total_users' => $users->count(),
                'channels' => $channels
            ]);
            
            if (in_array('push', $channels)) {
                $tokens = [];
                $userIds = [];
                $usersWithTokens = 0;
                $totalTokens = 0;
                
                foreach ($users as $user) {
                    // Log each user being processed
                    $userTokens = $user->getFcmTokens();
                    $tokenCount = count($userTokens);
                    
                    if ($tokenCount > 0) {
                        $userIds[] = $user->id;
                        $tokens = array_merge($tokens, $userTokens);
                        $usersWithTokens++;
                        $totalTokens += $tokenCount;
                        
                        \Log::debug('User has valid FCM tokens', [
                            'user_id' => $user->id,
                            'token_count' => $tokenCount,
                            'first_token_prefix' => !empty($userTokens[0]) ? substr($userTokens[0], 0, 10) . '...' : 'none'
                        ]);
                    } else {
                        \Log::debug('User has no valid FCM tokens', [
                            'user_id' => $user->id,
                            'firebase_token' => !empty($user->firebase_token) ? 'exists' : 'none',
                            'firebase_token_type' => $user->firebase_token ? gettype($user->firebase_token) : 'null'
                        ]);
                    }
                }
                
                if (!empty($tokens)) {
                    $notificationResult = $this->sendNotification(
                        $tokens,
                        $payload['body'], // message
                        $payload['title'],
                        [
                            'id'      => 0,
                            'type'    => 'broadcast',
                            'channels'=> $channels,
                        ] + $payload,
                        $userIds,
                    );
                    
                    $stats['pushed'] += count($tokens);
                    
                    \Log::info('Sent push notifications to users', [
                        'user_count' => count($userIds),
                        'token_count' => count($tokens),
                        'title' => $payload['title'],
                        'notification_result' => $notificationResult['status'] ?? 'unknown',
                        'users_with_tokens' => $usersWithTokens,
                        'total_users_processed' => $users->count()
                    ]);
                } else {
                    \Log::warning('No valid FCM tokens found for any users in chunk', [
                        'total_users' => $users->count(),
                        'users_with_tokens' => $usersWithTokens,
                        'total_tokens_found' => $totalTokens
                    ]);
                }
            }

            if (in_array('email', $channels)) {
                foreach ($users as $u) {
                    $addr = trim($u->email);
                    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        continue; // skip invalid
                    }

                    try {
                        Mail::to($addr)->queue(new BroadcastMailable($payload['title'], $payload['body']));
                        $stats['emailed']++;
                    } catch (\Throwable $e) {
                        \Log::error('[Broadcast] email send failed', ['email' => $addr, 'err' => $e->getMessage()]);
                    }
                }
            }
            $stats['total'] += $users->count();
        });

        // handle custom email recipients if provided
        if (!empty($customEmails) && in_array('email', $channels)) {
            foreach ($customEmails as $email) {
                $addr = trim($email);
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                try {
                    Mail::to($addr)->queue(new BroadcastMailable($payload['title'], $payload['body']));
                    $stats['emailed']++;
                    $stats['custom_emailed']++;
                } catch (\Throwable $e) {
                    \Log::error('[Broadcast] custom email send failed', ['email' => $addr, 'err' => $e->getMessage()]);
                }
            }
        }

        // Save broadcast stats
        $broadcast = Broadcast::create([
            'title'    => $payload['title'],
            'body'     => $payload['body'],
            'channels' => $channels,
            'groups'   => $groups,
            'custom_emails' => array_values($customEmails),
            'stats'    => $stats,
        ]);

        return [
            'id'    => $broadcast->id,
            'stats' => $stats,
        ];
    }

    /**
     * Resend an existing broadcast by its ID.
     */
    public function resend(int $id): array
    {
        $broadcast = Broadcast::findOrFail($id);

        return $this->send($broadcast->toArray());
    }
} 