<?php

namespace App\Models;

use App\Helpers\Utility;
use App\Models\Booking\Table;
use App\Traits\Activity;
use App\Traits\HasNotifications;
use App\Traits\Loadable;
use App\Traits\RequestToModel;
use Database\Factories\UserFactory;
use Eloquent;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * App\Models\User
 *
 * @property int $id
 * @property string $uuid
 * @property string $firstname
 * @property string|null $lastname
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $birthday
 * @property string $gender
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property string|null $ip_address
 * @property int|null $kitchen_id
 * @property boolean $isWork
 * @property int $active
 * @property string|null $img
 * @property array|null $firebase_token
 * @property string|null $password
 * @property string|null $remember_token
 * @property string|null $name_or_email
 * @property string|null $verify_token
 * @property string|null $referral
 * @property string|null $my_referral
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $language_id
 * @property Carbon|null $currency_id
 * @property Language|null $language
 * @property Currency|null $currency
 * @property-read Collection|Gallery[] $galleries
 * @property-read int|null $galleries_count
 * @property-read mixed $role
 * @property-read Collection|Invitation[] $invitations
 * @property-read int|null $invitations_count
 * @property-read Invitation|null $invite
 * @property-read Collection|Banner[] $likes
 * @property-read int|null $likes_count
 * @property-read Shop|null $moderatorShop
 * @property-read Collection|Review[] $reviews
 * @property-read int|null $reviews_count
 * @property-read Collection|UserAddress[] $addresses
 * @property-read int|null $addresses_count
 * @property-read Collection|Review[] $assignReviews
 * @property-read int|null $assign_reviews_count
 * @property-read Collection|Notification[] $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection|OrderDetail[] $orderDetails
 * @property-read int|null $order_details_count
 * @property-read int|null $orders_sum_total_price
 * @property-read Collection|Order[] $orders
 * @property-read int|null $orders_count
 * @property-read Collection|Order[] $deliveryManOrders
 * @property-read DeliveryManDeliveryZone|null $deliveryManDeliveryZone
 * @property-read int|null $delivery_man_orders_count
 * @property-read int|null $delivery_man_orders_sum_total_price
 * @property-read int|null $reviews_avg_rating
 * @property-read int|null $assign_reviews_avg_rating
 * @property-read Collection|Permission[] $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection|Permission[] $transactions
 * @property-read int|null $transactions_count
 * @property-read UserPoint|null $point
 * @property-read Collection|PointHistory[] $pointHistory
 * @property-read int|null $point_history_count
 * @property-read Collection|Role[] $roles
 * @property-read int|null $roles_count
 * @property-read Shop|null $shop
 * @property-read DeliveryManSetting|null $deliveryManSetting
 * @property-read EmailSubscription|null $emailSubscription
 * @property-read Collection|SocialProvider[] $socialProviders
 * @property-read int|null $social_providers_count
 * @property-read Collection|PersonalAccessToken[] $tokens
 * @property-read Collection|PaymentProcess[] $paymentProcess
 * @property-read int $payment_process_count
 * @property-read int|null $tokens_count
 * @property-read Wallet|Builder|null $wallet
 * @property-read static|void $create
 * @property-read Collection|Activity[] $activities
 * @property-read int $activities_count
 * @property-read Collection|Table[] $waiterTables
 * @property-read Collection|WaiterTable[] $waiterTableAssigned
 * @property-read int $waiter_tables_count
 * @property-read Collection|ModelLog[] $logs
 * @property-read int|null $logs_count
 * @property-read int|null $tg_user_id
 * @property-read int|null $location
 * @property-read int|null $referral_from_topup_price
 * @property-read int|null $referral_from_withdraw_price
 * @property-read int|null $referral_to_withdraw_price
 * @property-read int|null $referral_to_topup_price
 * @property-read int|null $referral_from_topup_count
 * @property-read int|null $referral_from_withdraw_count
 * @property-read int|null $referral_to_withdraw_count
 * @property-read int|null $referral_to_topup_count
 * @method static UserFactory factory(...$parameters)
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self onlyTrashed()
 * @method static Builder|self permission($permissions)
 * @method static Builder|self query()
 * @method static Builder|self filter($filter)
 * @method static Builder|self role($roles, $guard = null)
 * @method static Builder|self whereActive($value)
 * @method static Builder|self whereBirthday($value)
 * @method static Builder|self whereCreatedAt($value)
 * @method static Builder|self whereDeletedAt($value)
 * @method static Builder|self whereEmail($value)
 * @method static Builder|self whereEmailVerifiedAt($value)
 * @method static Builder|self whereFirebaseToken($value)
 * @method static Builder|self whereFirstname($value)
 * @method static Builder|self whereGender($value)
 * @method static Builder|self whereId($value)
 * @method static Builder|self whereImg($value)
 * @method static Builder|self whereIpAddress($value)
 * @method static Builder|self whereLastname($value)
 * @method static Builder|self wherePassword($value)
 * @method static Builder|self wherePhone($value)
 * @method static Builder|self wherePhoneVerifiedAt($value)
 * @method static Builder|self whereRememberToken($value)
 * @method static Builder|self whereUpdatedAt($value)
 * @method static Builder|self whereUuid($value)
 * @method static Builder|self withTrashed()
 * @method static Builder|self withoutTrashed()
 * @mixin Eloquent
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens,
        HasFactory,
        HasRoles,
        Loadable,
        Notifiable,
        RequestToModel,
        SoftDeletes,
        Activity,
        HasNotifications;

    const DATES = [
        'subMonth'  => 'subMonth',
        'subWeek'   => 'subWeek',
        'subYear'   => 'subYear',
    ];
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = ['id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'firebase_token' => 'array',
        'web_push_token' => 'array',
        'notification_settings' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['fcm_tokens_count'];

    /**
     * Get the user's FCM tokens with enhanced logging and debugging
     *
     * @return array
     */
    public function getFcmTokens(): array
    {
        try {
            $tokens = $this->firebase_token ?? [];
            
            // Log the raw token data for debugging
            $tokenType = gettype($tokens);
            $logContext = [
                'user_id' => $this->id,
                'raw_token_type' => $tokenType,
                'is_null' => is_null($tokens),
                'is_array' => is_array($tokens),
                'is_string' => is_string($tokens),
                'is_object' => is_object($tokens),
                'token_sample' => is_string($tokens) ? 
                    (strlen($tokens) > 20 ? substr($tokens, 0, 20) . '...' : $tokens) : 
                    (is_array($tokens) && !empty($tokens) ? 
                        json_encode(array_slice((array)$tokens, 0, min(3, count((array)$tokens)))) : 
                        'N/A'),
                'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'] ?? 'unknown',
                'trace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5), 1, 3)
            ];
            
            \Log::debug('Getting FCM tokens for user', $logContext);
            
            // If tokens are null or empty, return empty array
            if (empty($tokens)) {
                \Log::debug('No FCM tokens found for user', [
                    'user_id' => $this->id,
                    'firebase_token' => $this->firebase_token,
                    'firebase_token_type' => gettype($this->firebase_token)
                ]);
                return [];
            }
            
            // Handle string tokens (could be JSON or a single token)
            if (is_string($tokens)) {
                // Try to decode as JSON first
                $decoded = json_decode($tokens, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    \Log::debug('Decoded JSON token string', [
                        'user_id' => $this->id,
                        'decoded_type' => gettype($decoded)
                    ]);
                    $tokens = is_array($decoded) ? $decoded : [$decoded];
                } else {
                    // If not JSON, treat as a single token
                    \Log::debug('Treating as single token string', [
                        'user_id' => $this->id,
                        'token_length' => strlen($tokens)
                    ]);
                    $tokens = [$tokens];
                }
            } 
            // Handle other types (like JSON objects)
            elseif (is_object($tokens) && method_exists($tokens, 'toArray')) {
                $tokens = $tokens->toArray();
                \Log::debug('Converted object to array', [
                    'user_id' => $this->id,
                    'token_count' => count($tokens)
                ]);
            }
            
            // Ensure we have an array at this point
            if (!is_array($tokens)) {
                \Log::warning('Tokens could not be converted to array', [
                    'user_id' => $this->id,
                    'token_type' => gettype($tokens)
                ]);
                return [];
            }
            
            // Process and validate tokens
            $validTokens = [];
            
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    // Handle nested arrays
                    foreach ($token as $nestedToken) {
                        if (is_string($nestedToken) && $this->isValidFcmToken($nestedToken)) {
                            $validTokens[] = trim($nestedToken);
                        }
                    }
                } 
                elseif (is_string($token) && $this->isValidFcmToken($token)) {
                    $validTokens[] = trim($token);
                }
            }
            
            // Remove duplicates and empty values
            $validTokens = array_values(array_unique(array_filter($validTokens)));
            
            \Log::debug('Final valid tokens', [
                'user_id' => $this->id,
                'valid_token_count' => count($validTokens),
                'all_tokens_valid' => count($tokens) === count($validTokens),
                'token_prefixes' => array_map(function($t) {
                    return substr($t, 0, 10) . (strlen($t) > 10 ? '...' : '');
                }, $validTokens)
            ]);
            
            return $validTokens;
            
        } catch (\Exception $e) {
            \Log::error('Error in getFcmTokens: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'token_value' => $this->firebase_token ?? null,
                'token_type' => isset($this->firebase_token) ? gettype($this->firebase_token) : 'null',
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    
    /**
     * Check if string is JSON
     * 
     * @param string $string
     * @return bool
     */
    protected function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * Get the number of FCM tokens for the user
     * 
     * @return int
     */
    public function getFcmTokensCountAttribute(): int
    {
        return count($this->getFcmTokens());
    }
    
    /**
     * Add an FCM token to the user
     * 
     * @param string $token
     * @return bool True if the token was added, false if it already exists or is invalid
     */
    public function addFcmToken(string $token): bool
    {
        try {
            if (!$this->isValidFcmToken($token)) {
                \Log::warning('Invalid FCM token format', [
                    'user_id' => $this->id,
                    'token_prefix' => substr($token, 0, 10) . '...',
                    'token_length' => strlen($token)
                ]);
                return false;
            }
            
            $existingTokens = $this->getFcmTokens();
            
            // If token already exists, return true
            if (in_array($token, $existingTokens, true)) {
                \Log::debug('Token already exists for user', [
                    'user_id' => $this->id,
                    'token_prefix' => substr($token, 0, 10) . '...'
                ]);
                return true;
            }
            
            // Add the new token
            $existingTokens[] = $token;
            $this->firebase_token = array_values(array_unique($existingTokens));
            
            $saved = $this->save();
            
            if ($saved) {
                \Log::info('Added new FCM token for user', [
                    'user_id' => $this->id,
                    'token_count' => count($existingTokens)
                ]);
            } else {
                \Log::error('Failed to save FCM token for user', [
                    'user_id' => $this->id,
                    'token_prefix' => substr($token, 0, 10) . '...'
                ]);
            }
            
            return $saved;
            
        } catch (\Exception $e) {
            \Log::error('Error adding FCM token: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'token_prefix' => $token ? substr($token, 0, 10) . '...' : 'empty'
            ]);
            return false;
        }
    }
    
    /**
     * Remove an FCM token from the user
     * 
     * @param string $token
     * @return bool True if the token was removed, false if it didn't exist
     */
    public function removeFcmToken(string $token): bool
    {
        try {
            if (!$this->firebase_token) {
                return false;
            }

            $tokens = is_array($this->firebase_token) 
                ? $this->firebase_token 
                : [$this->firebase_token];

            $initialCount = count($tokens);
            $tokens = array_values(array_filter($tokens, fn($t) => $t !== $token));

            if (count($tokens) === $initialCount) {
                return false; // Token not found
            }

            $this->firebase_token = $tokens;
            $removed = $this->save();
            
            if ($removed) {
                \Log::info('Removed FCM token from user', [
                    'user_id' => $this->id,
                    'tokens_remaining' => count($tokens)
                ]);
            } else {
                \Log::error('Failed to remove FCM token from user', [
                    'user_id' => $this->id,
                    'token_prefix' => substr($token, 0, 10) . '...'
                ]);
            }
            
            return $removed;
        } catch (\Exception $e) {
            \Log::error('Error removing FCM token: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'token_prefix' => substr($token, 0, 10) . '...',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Add a web push token to the user
     * 
     * @param string $token The web push token to add
     * @return bool True if the token was added, false if it already exists or is invalid
     */
    public function addWebPushToken(string $token): bool
    {
        try {
            if (!$this->isValidWebPushToken($token)) {
                \Log::warning('Invalid web push token format', [
                    'user_id' => $this->id,
                    'token_prefix' => substr($token, 0, 20) . '...'
                ]);
                return false;
            }

            $tokens = $this->web_push_token ?? [];
            
            // Convert to array if it's a string (shouldn't happen due to $casts, but just in case)
            if (is_string($tokens)) {
                $tokens = json_decode($tokens, true) ?: [];
            }
            
            // Check if token already exists
            if (in_array($token, $tokens)) {
                return true; // Token already exists, no need to add it again
            }
            
            // Add the new token
            $tokens[] = $token;
            $this->web_push_token = array_values(array_unique($tokens));
            $saved = $this->save();
            
            if ($saved) {
                \Log::info('Added web push token to user', [
                    'user_id' => $this->id,
                    'total_web_push_tokens' => count($tokens)
                ]);
            } else {
                \Log::error('Failed to save web push token', [
                    'user_id' => $this->id,
                    'token_prefix' => substr($token, 0, 20) . '...'
                ]);
            }
            
            return $saved;
        } catch (\Exception $e) {
            \Log::error('Error adding web push token: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'token_prefix' => substr($token, 0, 20) . '...',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Remove a web push token from the user
     * 
     * @param string $token The web push token to remove
     * @return bool True if the token was removed, false if it didn't exist
     */
    public function removeWebPushToken(string $token): bool
    {
        try {
            if (empty($this->web_push_token)) {
                return false;
            }
            
            $tokens = $this->web_push_token;
            
            // Convert to array if it's a string (shouldn't happen due to $casts, but just in case)
            if (is_string($tokens)) {
                $tokens = json_decode($tokens, true) ?: [];
            }
            
            $initialCount = count($tokens);
            $tokens = array_values(array_filter($tokens, fn($t) => $t !== $token));
            
            if (count($tokens) === $initialCount) {
                return false; // Token not found
            }
            
            $this->web_push_token = $tokens;
            $removed = $this->save();
            
            if ($removed) {
                \Log::info('Removed web push token from user', [
                    'user_id' => $this->id,
                    'tokens_remaining' => count($tokens)
                ]);
            } else {
                \Log::error('Failed to remove web push token from user', [
                    'user_id' => $this->id,
                    'token_prefix' => substr($token, 0, 20) . '...'
                ]);
            }
            
            return $removed;
        } catch (\Exception $e) {
            \Log::error('Error removing web push token: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'token_prefix' => substr($token, 0, 20) . '...',
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Check if a web push token is valid
     * 
     * @param string $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public function isValidWebPushToken(string $token): bool
    {
        // Web push tokens are typically longer and contain specific patterns
        return is_string($token) && 
               strlen($token) > 100 && 
               (str_contains($token, 'key=') || str_contains($token, 'endpoint'));
    }
    
    /**
     * Get all valid web push tokens for this user
     * 
     * @return array
     */
    public function getWebPushTokens(): array
    {
        if (empty($this->web_push_token)) {
            return [];
        }
        
        $tokens = $this->web_push_token;
        
        // Convert to array if it's a string (shouldn't happen due to $casts, but just in case)
        if (is_string($tokens)) {
            $tokens = json_decode($tokens, true) ?: [];
        }
        
        // Filter out any invalid tokens
        return array_values(array_filter($tokens, [$this, 'isValidWebPushToken']));
    }
    
    /**
     * Validate an FCM token
     * 
     * @param mixed $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public function isValidFcmToken($token): bool
    {
        try {
            // Log the raw token data for debugging
            \Log::debug('Validating FCM token', [
                'token_type' => gettype($token),
                'is_string' => is_string($token),
                'is_array' => is_array($token),
                'is_object' => is_object($token),
                'is_null' => is_null($token),
                'token_sample' => is_string($token) ? (strlen($token) > 10 ? substr($token, 0, 10) . '...' : $token) : 'N/A',
                'token_length' => is_string($token) ? strlen($token) : 0,
                'user_id' => $this->id,
                'environment' => config('app.env')
            ]);

            // Check if token is a non-empty string
            if (!is_string($token) || trim($token) === '') {
                \Log::debug('Invalid FCM token: empty or not a string', [
                    'token_type' => gettype($token),
                    'token' => is_string($token) ? (strlen($token) > 10 ? substr($token, 0, 10) . '...' : $token) : $token,
                    'user_id' => $this->id
                ]);
                return false;
            }
            
            // Get environment configuration
            $isTestEnvironment = config('app.env') !== 'production';
            $allowTestTokens = config('services.firebase.allow_test_tokens', true);
            
            // Accept test tokens in non-production environments if allowed
            if (($isTestEnvironment && $allowTestTokens) && 
                (str_starts_with($token, 'test_fcm_token_') || str_starts_with($token, 'test_'))) {
                \Log::debug('Accepted test FCM token in test environment', [
                    'token_prefix' => substr($token, 0, 15) . '...',
                    'length' => strlen($token),
                    'user_id' => $this->id,
                    'environment' => config('app.env'),
                    'allow_test_tokens' => $allowTestTokens
                ]);
                return true;
            }
            
            // In production, reject test tokens
            if (str_starts_with($token, 'test_')) {
                \Log::warning('Rejecting test token in production', [
                    'token_prefix' => substr($token, 0, 15) . '...',
                    'user_id' => $this->id,
                    'environment' => config('app.env')
                ]);
                return false;
            }
            
            // Check token format (basic validation for real FCM tokens)
            // Real FCM tokens are typically much longer and follow a specific format
            $length = strlen($token);
            
            // Minimum length check (FCM tokens are usually 150+ characters)
            if ($length < 100) {
                \Log::debug('FCM token too short', [
                    'length' => $length,
                    'token_prefix' => substr($token, 0, 10) . '...',
                    'user_id' => $this->id,
                    'environment' => config('app.env')
                ]);
                return false;
            }
            
            // Check for common FCM token patterns
            if (!preg_match('/^[a-zA-Z0-9_\-:]+$/', $token)) {
                \Log::debug('FCM token contains invalid characters', [
                    'token_prefix' => substr($token, 0, 10) . '...',
                    'user_id' => $this->id,
                    'environment' => config('app.env')
                ]);
                return false;
            }
            
            // Log the token as valid
            \Log::debug('Accepted valid FCM token', [
                'token_prefix' => substr($token, 0, 10) . '...',
                'length' => $length,
                'user_id' => $this->id,
                'environment' => config('app.env')
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            \Log::error('Error validating FCM token: ' . $e->getMessage(), [
                'token_type' => gettype($token),
                'token_length' => is_string($token) ? strlen($token) : 0,
                'token_sample' => is_string($token) ? (strlen($token) > 10 ? substr($token, 0, 10) . '...' : $token) : null,
                'user_id' => $this->id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Get raw FCM token data for debugging
     * 
     * @return array
     */
    public function getRawFcmTokenData(): array
    {
        return [
            'user_id' => $this->id,
            'firebase_token' => $this->firebase_token,
            'firebase_token_type' => gettype($this->firebase_token),
            'firebase_token_json' => json_encode($this->firebase_token),
            'is_json' => $this->isJson($this->firebase_token),
            'is_array' => is_array($this->firebase_token),
            'is_string' => is_string($this->firebase_token),
            'is_null' => is_null($this->firebase_token),
            'is_object' => is_object($this->firebase_token),
            'count' => is_countable($this->firebase_token) ? count($this->firebase_token) : 0,
        ];
    }

    /**
     * Clear all FCM tokens for this user
     * 
     * @return bool True if tokens were cleared, false if no tokens existed or an error occurred
     */
    public function clearFcmTokens(): bool
    {
        try {
            $hadTokens = !empty($this->firebase_token);
            
            if ($hadTokens) {
                $this->firebase_token = [];
                
                \Log::info('Cleared all FCM tokens for user', [
                    'user_id' => $this->id
                ]);
            } else {
                \Log::debug('No FCM tokens to clear for user', [
                    'user_id' => $this->id
                ]);
            }
            
            return $hadTokens;
            
        } catch (\Exception $e) {
            \Log::error('Error clearing FCM tokens: ' . $e->getMessage(), [
                'user_id' => $this->id
            ]);
            return false;
        }
    }
    
    /**
     * Get validation rules for firebase token
     * 
     * @return array
     */
    public static function firebaseTokenRules(): array
    {
        return [
            'token' => [
                'required',
                'string',
                'min:100',
                'max:500',
                function ($attribute, $value, $fail) {
                    if (!preg_match('/^[a-zA-Z0-9_\-:]+$/', $value)) {
                        $fail('The FCM token format is invalid. Only alphanumeric, underscore, hyphen, and colon characters are allowed.');
                    }
                },
            ],
        ];
    }
    
    /**
     * Clean up expired or invalid FCM tokens
     * 
     * @param int $olderThanDays Remove tokens older than this many days (0 = all tokens)
     * @return int Number of tokens removed
     */
    public function cleanupFcmTokens(int $olderThanDays = 30): int
    {
        try {
            $tokens = $this->firebase_token ?? [];
            $originalCount = count($tokens);
            
            if (empty($tokens)) {
                return 0;
            }
            
            // Filter out invalid tokens
            $validTokens = array_filter($tokens, function($token) use ($olderThanDays) {
                // Basic format validation
                if (!is_string($token) || !preg_match('/^[a-zA-Z0-9_\-:]+$/', $token)) {
                    return false;
                }
                
                // Check token length
                $length = strlen($token);
                if ($length < 100 || $length > 500) {
                    return false;
                }
                
                // If we need to check token age (for future implementation)
                // This is a placeholder for actual token expiration check
                // Firebase tokens don't have a standard expiration, but you might store the timestamp
                // when the token was added and check against that
                
                return true;
            });
            
            $removedCount = $originalCount - count($validTokens);
            
            // Only update if we removed some tokens
            if ($removedCount > 0) {
                $this->firebase_token = array_values($validTokens);
                $this->save();
                
                \Log::info('Cleaned up invalid FCM tokens', [
                    'user_id' => $this->id,
                    'removed_count' => $removedCount,
                    'remaining_count' => count($validTokens)
                ]);
            }
            
            return $removedCount;
            
        } catch (\Exception $e) {
            \Log::error('Error cleaning up FCM tokens: ' . $e->getMessage(), [
                'user_id' => $this->id,
                'exception' => $e
            ]);
            return 0;
        }
    }

    public function isOnline(): ?bool
    {
        return Cache::has('user-online-' . $this->id);
    }

    public function getRoleAttribute(): string
    {
        return $this->roles[0]->name ?? 'no role';
    }

    public function getNameOrEmailAttribute(): ?string
    {
        return $this->firstname ?? $this->email;
    }

    public function shop(): HasOne
    {
        return $this->hasOne(Shop::class);
    }

    public function emailSubscription(): HasOne
    {
        return $this->hasOne(EmailSubscription::class);
    }

    public function notifications(): BelongsToMany
    {
        return $this->belongsToMany(Notification::class, NotificationUser::class)
            ->as('notification')
            ->withPivot('active');
    }

    public function invite(): HasOne
    {
        return $this->hasOne(Invitation::class);
    }

    public function moderatorShop(): HasOneThrough
    {
        return $this->hasOneThrough(Shop::class, Invitation::class,
            'user_id', 'id', 'id', 'shop_id');
    }

    public function wallet(): HasOne|Wallet
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function assignReviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'assignable');
    }

    public function invitations(): HasMany|Invitation
    {
        return $this->hasMany(Invitation::class);
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(Kitchen::class);
    }

    public function socialProviders(): HasMany
    {
        return $this->hasMany(SocialProvider::class,'user_id','id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class,'user_id');
    }

    public function paymentProcess(): HasMany
    {
        return $this->hasMany(PaymentProcess::class);
    }

    public function deliveryManOrders(): HasMany
    {
        return $this->hasMany(Order::class,'deliveryman');
    }

    public function orderDetails(): HasManyThrough
    {
        return $this->hasManyThrough(OrderDetail::class,Order::class);
    }

    public function point(): HasOne
    {
        return $this->hasOne(UserPoint::class, 'user_id');
    }

    public function pointHistory(): HasMany
    {
        return $this->hasMany(PointHistory::class, 'user_id');
    }

    public function deliveryManSetting(): HasOne
    {
        return $this->hasOne(DeliveryManSetting::class, 'user_id');
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(Banner::class, Like::class);
    }

    public function activity(): HasOne
    {
        return $this->hasOne(UserActivity::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(UserActivity::class);
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(ModelLog::class, 'model');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

	public function waiterTables(): BelongsToMany
	{
		return $this->belongsToMany(Table::class, WaiterTable::class);
	}

	public function waiterTableAssigned(): HasMany
	{
		return $this->hasMany(WaiterTable::class);
	}

    public function deliveryManDeliveryZone(): HasOne
    {
        return $this->hasOne(DeliveryManDeliveryZone::class);
    }

      public function creditScores()
    {
        return $this->hasMany(CreditScore::class);
    }

    public function scopeFilter($query, array $filter) {

        $userIds  = [];
		$addressCheckRequired = false;

        if (data_get($filter, 'address.latitude') && data_get($filter, 'address.longitude')) {

            DeliveryManDeliveryZone::whereNotNull('user_id')
                ->get()
                ->map(function (DeliveryManDeliveryZone $deliveryManDeliveryZone) use ($filter, &$userIds) {
                    if (
                        Utility::pointInPolygon(data_get($filter, 'address'), $deliveryManDeliveryZone->address)
                    ) {
                        $userIds[] = $deliveryManDeliveryZone->user_id;
                    }

                    return null;
                })
                ->toArray();

			$addressCheckRequired = true;
        }

        $query
			->when(data_get($filter, 'role'), function ($query, $role) {
                $query->whereHas('roles', fn($q) => $q->where('name', $role));
            })
            ->when(data_get($filter, 'roles'), function ($q, $roles) {
                $q->whereHas('roles', function ($q) use($roles) {
                    $q->whereIn('name', (array)$roles);
                });
            })
            ->when(data_get($filter, 'shop_id'), function ($query, $shopId) {

				if (request('role') === 'deliveryman') {
					return $query->where(function ($q) use ($shopId) {
						$q
							->whereHas('invitations', fn($q) => $q->where('shop_id', $shopId))
							->orWhereDoesntHave('invitations');
					});
				}

				return $query->whereHas('invitations', fn($q) => $q->where('shop_id', $shopId));
            })
            ->when($addressCheckRequired, function ($query) use ($userIds) {
                $query->whereIn('id', $userIds);
            })
            ->when(data_get($filter, 'empty-shop'), function ($query) {
                $query->whereDoesntHave('shop');
            })
			->when(data_get($filter, 'empty-table'), function ($query) {
				$query->whereDoesntHave('waiterTables');
			})
			->when(data_get($filter,'table_id'), function ($q, $id) {
				$q->whereHas('waiterTableAssigned', fn($q) => $q->where('table_id', $id));
			})
			->when(data_get($filter,'table_ids'), function ($q, $ids) {
				$q->whereHas('waiterTableAssigned', fn($q) => $q->whereIn('table_id', $ids));
			})
            ->when(data_get($filter, 'empty-kitchen'), function ($query) {
                $query->whereNull('kitchen_id');
            })
            ->when(data_get($filter,'kitchen_id'), function ($q) use ($filter) {
                $q->where('kitchen_id', data_get($filter, 'kitchen_id'));
            })
            ->when(data_get($filter,'isWork'), function ($q) use ($filter) {
                $q->where('isWork', data_get($filter,'isWork'));
            })
            ->when(data_get($filter, 'search'), function ($q, $search) {
                $q->where(function($query) use ($search) {

                    $firstNameLastName = explode(' ', $search);

                    if (data_get($firstNameLastName, 1)) {
                        return $query
                            ->where('firstname',  'LIKE', '%' . $firstNameLastName[0] . '%')
                            ->orWhere('lastname',   'LIKE', '%' . $firstNameLastName[1] . '%');
                    }

                    return $query
                        ->where('id',           'LIKE', "%$search%")
                        ->orWhere('firstname',  'LIKE', "%$search%")
                        ->orWhere('lastname',   'LIKE', "%$search%")
                        ->orWhere('email',      'LIKE', "%$search%")
                        ->orWhere('phone',      'LIKE', "%$search%");
                });
            })
            ->when(data_get($filter, 'statuses'), function ($query, $statuses) use ($filter) {

                if (!is_array($statuses)) {
                    return $query;
                }

                $statuses = array_intersect($statuses, Order::STATUSES);

                return $query->when(data_get($filter, 'role') === 'deliveryman',
                    fn($q) => $q->whereHas('deliveryManOrders', fn($q) => $q->whereIn('status', $statuses)),
                    fn($q) => $q->whereHas('orders', fn($q) => $q->whereIn('status', $statuses)),
                );
            })
            ->when(data_get($filter, 'date_from'), function ($query, $dateFrom) use ($filter) {

                $dateFrom = date('Y-m-d', strtotime($dateFrom . ' -1 day'));
                $dateTo = data_get($filter, 'date_to', date('Y-m-d'));

                $dateTo = date('Y-m-d', strtotime($dateTo . ' +1 day'));

                return $query->when(data_get($filter, 'role') === 'deliveryman',
                    fn($q) => $q->whereHas('deliveryManOrders',
                        fn($q) => $q->where('created_at', '>=', $dateFrom)->where('created_at', '<=', $dateTo)
                    ),
                    fn($q) => $q->whereHas('orders',
                        fn($q) => $q->where('created_at', '>=', $dateFrom)->where('created_at', '<=', $dateTo)
                    ),
                );
            })
            ->when(isset($filter['online']) || data_get($filter, 'type_of_technique'), function ($query) use($filter) {

                $query->whereHas('deliveryManSetting', function (Builder $query) use($filter) {
                    $online = data_get($filter, 'online');

                    $typeOfTechnique = data_get($filter, 'type_of_technique');

                    $query
                        ->when($online === "1" || $online === "0", function ($q) use($online) {
                            $q->whereOnline(!!(int)$online)->where('location', '!=', null);
                        })
                        ->when($typeOfTechnique, function ($q, $type) {
                            $q->where('type_of_technique', data_get(DeliveryManSetting::TYPE_OF_TECHNIQUES, $type));
                        });

                });

            })
            ->when(isset($filter['active']), fn($q) => $q->where('active', $filter['active']))
            ->when(data_get($filter, 'exist_token'), fn($query) => $query->whereNotNull('firebase_token'))
            ->when(data_get($filter, 'walletSort'), function ($q, $walletSort) use($filter) {
                $q->whereHas('wallet', function ($q) use($walletSort, $filter) {
                    $q->orderBy($walletSort, data_get($filter, 'sort', 'desc'));
                });
            })
            ->when(data_get($filter, 'empty-setting'), function (Builder $query) {
                $query->whereHas('deliveryManSetting', fn($q) => $q, '=', '0');
            })
            ->when(isset($filter['deleted_at']), function ($q) {
                $q->onlyTrashed();
            })
            ->when(data_get($filter,'column'), function (Builder $query, $column) use($filter) {

                $addIfDeliveryMan = '';

                if (data_get($filter, 'role') === 'deliveryman') {
                    $addIfDeliveryMan .= 'delivery_man_';
                }

                switch ($column) {
                    case 'rating':
                        $column = 'assign_reviews_avg_rating';
                        break;
                    case 'count':
                        $column = $addIfDeliveryMan . 'orders_count';
                        break;
                    case 'sum':
                        $column = $addIfDeliveryMan . 'orders_sum_total_price';
                        break;
                    case 'wallet_sum':
                        $column = 'wallet_sum_price';
                        break;
                }

                $query->orderBy($column, data_get($filter, 'sort', 'desc'));

                if (data_get($filter, 'by_rating')) {

                    if (data_get($filter, 'by_rating') === 'top') {
                        return $query->having('assign_reviews_avg_rating', '>=', 3.99);
                    }

                    return $query->having('assign_reviews_avg_rating', '<', 3.99);
                }

                return $query;
            }, fn($query) => $query->orderBy('id', 'desc'));

    }
}
