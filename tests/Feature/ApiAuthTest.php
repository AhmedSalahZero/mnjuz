<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;
    protected Organization $organization;
    protected string $password = 'password123';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make($this->password),
            'role' => 'user',
            'language' => 'en',
        ]);

        // Create organization
        $this->organization = Organization::factory()->create([
            'name' => 'Test Organization',
        ]);

        // Create team (user belongs to organization)
        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);
    }

    /**
     * Test successful login via API
     */
    public function test_api_login_success(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
            'device_name' => 'Test Device',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'first_name',
                        'last_name',
                        'email',
                        'role',
                        'language',
                        'avatar',
                    ],
                    'token',
                    'token_type',
                    'organizations',
                    'current_organization_id',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $this->user->id,
                        'email' => $this->user->email,
                    ],
                    'token_type' => 'Bearer',
                ],
            ]);

        // Verify token was created
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'tokenable_type' => User::class,
        ]);
    }

    /**
     * Test login with invalid credentials
     */
    public function test_api_login_invalid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * Test login with non-existent email
     */
    public function test_api_login_email_not_found(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => $this->password,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test login when user has no active organization
     */
    public function test_api_login_no_active_organization(): void
    {
        // Create user without organization
        $userWithoutOrg = User::factory()->create([
            'email' => 'noorg@example.com',
            'password' => Hash::make($this->password),
            'role' => 'user',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $userWithoutOrg->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Test login when TFA is required
     */
    public function test_api_login_requires_tfa(): void
    {
        // Enable TFA addon
        Addon::factory()->create([
            'name' => 'Google Authenticator',
            'is_active' => 1,
            'status' => 1,
        ]);

        // Enable TFA for user
        $this->user->update([
            'tfa' => true,
            'tfa_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => false,
                'requires_tfa' => true,
            ])
            ->assertJsonStructure([
                'tfa_token',
            ]);
    }

    /**
     * Test TFA verification structure (code validation is complex, so we test structure only)
     */
    public function test_api_tfa_verify_structure(): void
    {
        // Enable TFA addon
        Addon::factory()->create([
            'name' => 'Google Authenticator',
            'is_active' => 1,
            'status' => 1,
        ]);

        // Enable TFA for user
        $this->user->update([
            'tfa' => true,
            'tfa_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        // First, get TFA token from login
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $tfaToken = $loginResponse->json('tfa_token');
        $this->assertNotEmpty($tfaToken);

        // Test with invalid code (will fail validation but shows structure)
        $response = $this->postJson('/api/auth/tfa/verify', [
            'tfa_token' => $tfaToken,
            'tfa_code' => '000000', // Invalid code
        ]);

        // Should return error response with proper structure
        $response->assertJsonStructure([
            'success',
            'message',
        ]);
    }

    /**
     * Test TFA verification with invalid token
     */
    public function test_api_tfa_verify_invalid_token(): void
    {
        $response = $this->postJson('/api/auth/tfa/verify', [
            'tfa_token' => 'invalid-token',
            'tfa_code' => '123456',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Test TFA verification with missing parameters
     */
    public function test_api_tfa_verify_missing_parameters(): void
    {
        $response = $this->postJson('/api/auth/tfa/verify', []);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Test logout success
     */
    public function test_api_logout_success(): void
    {
        // Login first to get token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $token = $loginResponse->json('data.token');

        // Logout
        $response = $this->postJson('/api/auth/logout', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify token was deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
        ]);
    }

    /**
     * Test logout without authentication
     */
    public function test_api_logout_unauthenticated(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Test set current organization success
     */
    public function test_api_set_current_organization_success(): void
    {
        // Login first
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $token = $loginResponse->json('data.token');

        // Set current organization
        $response = $this->postJson('/api/auth/set-current-organization', [
            'organization_id' => $this->organization->id,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify organization was set
        $this->user->refresh();
        $this->assertEquals($this->organization->id, $this->user->current_organization_id);
    }

    /**
     * Test set current organization with invalid organization
     */
    public function test_api_set_current_organization_invalid(): void
    {
        // Login first
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $token = $loginResponse->json('data.token');

        // Try to set non-existent organization
        $response = $this->postJson('/api/auth/set-current-organization', [
            'organization_id' => 99999,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);
    }

    /**
     * Test set current organization user doesn't belong to
     */
    public function test_api_set_current_organization_not_member(): void
    {
        // Create another organization
        $otherOrg = Organization::factory()->create();

        // Login first
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $token = $loginResponse->json('data.token');

        // Try to set organization user doesn't belong to
        $response = $this->postJson('/api/auth/set-current-organization', [
            'organization_id' => $otherOrg->id,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);
    }

    /**
     * Test mobile app test endpoint with authentication
     */
    public function test_api_test_endpoint_success(): void
    {
        // Enable Mobile App addon
        Addon::factory()->create([
            'name' => 'Mobile App',
            'is_active' => 1,
            'status' => 1,
        ]);

        // Create subscription plan with Mobile App addon enabled
        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'metadata' => json_encode([
                'addons' => [
                    'Mobile App' => true,
                ],
            ]),
        ]);

        // Create subscription for organization
        Subscription::create([
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'trial', // Use 'trial' status to allow testing
            'valid_until' => now()->addYear(),
        ]);

        // Login first
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $token = $loginResponse->json('data.token');

        // Call test endpoint
        $response = $this->postJson('/api/test', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Test successful',
            ]);
    }

    /**
     * Test mobile app test endpoint without authentication
     */
    public function test_api_test_endpoint_unauthenticated(): void
    {
        $response = $this->postJson('/api/test');

        $response->assertStatus(401);
    }

    /**
     * Test mobile app test endpoint when addon is disabled
     */
    public function test_api_test_endpoint_addon_disabled(): void
    {
        // Login first
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);

        $token = $loginResponse->json('data.token');

        // Call test endpoint (addon not enabled)
        $response = $this->postJson('/api/test', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }
}
