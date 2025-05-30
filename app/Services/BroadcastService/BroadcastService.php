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

        // Users with specific roles
        if (!empty($roleGroups)) {
            $query->whereHas('roles', function ($q) use ($roleGroups) {
                $q->whereIn('name', $roleGroups);
            });
        }

        // Include plain customers without any specific role when "user" requested
        if (in_array('user', $groups)) {
            $query->orWhereDoesntHave('roles');
        }

        $stats = [
            'emailed' => 0,
            'pushed'  => 0,
            'total'   => 0,
            'custom_emailed' => 0,
        ];

        $query->chunkById(500, function ($users) use ($payload, $channels, &$stats, $customEmails) {
            if (in_array('push', $channels)) {
                $tokens = [];
                $userIds = [];
                
                foreach ($users as $user) {
                    // Use the getFcmTokens() method which handles all token formats and validation
                    $userTokens = $user->getFcmTokens();
                    if (!empty($userTokens)) {
                        $userIds[] = $user->id;
                        $tokens = array_merge($tokens, $userTokens);
                    }
                }
                
                if (!empty($tokens)) {
                    $this->sendNotification(
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
                        'title' => $payload['title']
                    ]);
                } else {
                    \Log::warning('No valid FCM tokens found for any users in chunk', [
                        'user_count' => $users->count()
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