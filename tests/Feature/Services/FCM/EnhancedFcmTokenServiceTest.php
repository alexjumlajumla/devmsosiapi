<?php

namespace Tests\Feature\Services\FCM;

use App\Models\User;
use App\Services\FCM\EnhancedFcmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Services\FCM\BaseFcmTest;

class EnhancedFcmTokenServiceTest extends BaseFcmTest
{
    use RefreshDatabase;

    private EnhancedFcmTokenService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = app(EnhancedFcmTokenService::class);
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_add_a_token()
    {
        $token = 'test_fcm_token_123';
        
        // Ensure firebase_token is initialized as an array
        $this->user->firebase_token = [];
        $this->user->save();
        
        $result = $this->service->addToken($this->user, $token);
        
        $this->assertTrue($result);
        $tokens = $this->service->getUserTokens($this->user);
        $this->assertContains($token, $tokens);
    }

    /** @test */
    public function it_can_add_token_with_device_id()
    {
        $token = 'test_fcm_token_123';
        $deviceId = 'test_device_123';
        
        // Ensure firebase_token is initialized as an array
        $this->user->firebase_token = [];
        $this->user->save();
        
        $result = $this->service->addToken($this->user, $token, $deviceId);
        
        $this->assertTrue($result);
        $tokens = $this->service->getUserTokensWithMetadata($this->user);
        $this->assertEquals($deviceId, $tokens[0]['device_id'] ?? null);
    }

    /** @test */
    public function it_does_not_add_duplicate_tokens()
    {
        $token = 'test_fcm_token_123';
        
        // Ensure firebase_token is initialized as an array
        $this->user->firebase_token = [];
        $this->user->save();
        
        $this->service->addToken($this->user, $token);
        $result = $this->service->addToken($this->user, $token);
        
        $this->assertTrue($result);
        $tokens = $this->service->getUserTokens($this->user);
        $this->assertCount(1, $tokens);
    }

    /** @test */
    public function it_can_remove_a_token()
    {
        $token = 'test_fcm_token_123';
        
        // Ensure firebase_token is initialized as an array with the token
        $this->user->firebase_token = [$token];
        $this->user->save();
        
        $result = $this->service->removeToken($this->user, $token);
        
        $this->assertTrue($result);
        $tokens = $this->service->getUserTokens($this->user);
        $this->assertNotContains($token, $tokens);
    }

    /** @test */
    public function it_returns_false_when_removing_nonexistent_token()
    {
        // Ensure firebase_token is initialized as an empty array
        $this->user->firebase_token = [];
        $this->user->save();
        
        $result = $this->service->removeToken($this->user, 'nonexistent_token');
        
        $this->assertFalse($result);
    }

    /** @test */
    public function it_validates_token_format()
    {
        $invalidToken = 'invalid_token';
        $validToken = 'test_fcm_token_123';
        
        $this->assertFalse($this->service->isValidFcmToken($invalidToken));
        $this->assertTrue($this->service->isValidFcmToken($validToken));
    }

    /** @test */
    public function it_limits_tokens_per_user()
    {
        // Ensure firebase_token is initialized as an empty array
        $this->user->firebase_token = [];
        $this->user->save();
        
        // Add max tokens
        for ($i = 1; $i <= EnhancedFcmTokenService::MAX_TOKENS_PER_USER; $i++) {
            $this->service->addToken($this->user, "test_token_$i");
        }
        
        // Add one more token
        $this->service->addToken($this->user, 'new_token');
        
        $tokens = $this->service->getUserTokens($this->user);
        $this->assertCount(EnhancedFcmTokenService::MAX_TOKENS_PER_USER, $tokens);
        $this->assertContains('new_token', $tokens);
        $this->assertNotContains('test_token_1', $tokens);
    }

    /** @test */
    public function it_caches_tokens()
    {
        $token = 'test_fcm_token_123';
        
        // Ensure firebase_token is initialized as an array with the token
        $this->user->firebase_token = [$token];
        $this->user->save();
        
        // Clear the cache to ensure we're testing the cache
        $cacheKey = 'fcm_tokens:' . $this->user->id;
        Cache::forget($cacheKey);
        
        // First call should cache the result
        $this->service->getUserTokens($this->user);
        $this->assertTrue(Cache::has($cacheKey));
        
        // Modify the database directly
        $this->user->firebase_token = [];
        $this->user->save();
        
        // Should still get the cached result
        $cachedTokens = $this->service->getUserTokens($this->user);
        $this->assertContains($token, $cachedTokens);
        
        // Clear cache and check again
        $this->service->clearUserTokensCache($this->user->id);
        $freshTokens = $this->service->getUserTokens($this->user);
        $this->assertNotContains($token, $freshTokens);
    }
}
