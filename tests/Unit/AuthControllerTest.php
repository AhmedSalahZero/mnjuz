<?php

namespace Tests\Unit;

use App\Http\Controllers\AuthController;
use App\Http\Requests\UserHasOrganizationRequest;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected AuthController $controller;
    protected User $user;
    protected Organization $organization;
    protected string $password = 'password123';

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->controller = new AuthController();
        
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make($this->password),
            'role' => 'user',
        ]);

        $this->organization = Organization::factory()->create();
        
        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);
    }

    /**
     * Test doLogin method returns JSON for API requests
     */
    public function test_do_login_returns_json_for_api(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);
        
        $request->headers->set('Accept', 'application/json');
        $request->server->set('REQUEST_URI', '/api/auth/login');
        
        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('doLogin');
        $method->setAccessible(true);
        
        $response = $method->invoke($this->controller, $request, $this->user, false);
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertJson($response->getContent());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertArrayHasKey('user', $data['data']);
    }

    /**
     * Test doLogin method creates token with device name
     */
    public function test_do_login_creates_token_with_device_name(): void
    {
        $request = Request::create('/api/auth/login', 'POST', [
            'device_name' => 'My iPhone',
        ]);
        
        $request->headers->set('Accept', 'application/json');
        $request->server->set('REQUEST_URI', '/api/auth/login');
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('doLogin');
        $method->setAccessible(true);
        
        $response = $method->invoke($this->controller, $request, $this->user, false);
        
        $data = json_decode($response->getContent(), true);
        $this->assertNotEmpty($data['data']['token']);
        
        // Verify token was created in database
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'My iPhone',
        ]);
    }

    /**
     * Test doLogin method includes organizations in response
     */
    public function test_do_login_includes_organizations(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->server->set('REQUEST_URI', '/api/auth/login');
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('doLogin');
        $method->setAccessible(true);
        
        $response = $method->invoke($this->controller, $request, $this->user, false);
        
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('organizations', $data['data']);
        $this->assertIsArray($data['data']['organizations']);
        $this->assertCount(1, $data['data']['organizations']);
    }

    /**
     * Test doLogin method sets current_organization_id when single organization
     */
    public function test_do_login_sets_current_organization_single(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->server->set('REQUEST_URI', '/api/auth/login');
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('doLogin');
        $method->setAccessible(true);
        
        $response = $method->invoke($this->controller, $request, $this->user, false);
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals($this->organization->id, $data['data']['current_organization_id']);
    }

    /**
     * Test doLogin method doesn't set current_organization_id when multiple organizations
     */
    public function test_do_login_no_current_organization_multiple(): void
    {
        // Create second organization
        $org2 = Organization::factory()->create();
        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $org2->id,
            'role' => 'manager',
        ]);

        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->server->set('REQUEST_URI', '/api/auth/login');
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('doLogin');
        $method->setAccessible(true);
        
        $response = $method->invoke($this->controller, $request, $this->user, false);
        
        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['data']['current_organization_id']);
        $this->assertCount(2, $data['data']['organizations']);
    }

    /**
     * Test login method handles TFA requirement
     */
    public function test_login_handles_tfa_requirement(): void
    {
        Addon::factory()->create([
            'name' => 'Google Authenticator',
            'is_active' => 1,
            'status' => 1,
        ]);

        $this->user->update([
            'tfa' => 1, // Use 1 instead of true for database compatibility
            'tfa_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
        
        // Refresh user to ensure changes are loaded
        $this->user->refresh();

        $request = \App\Http\Requests\LoginRequest::create('/api/auth/login', 'POST', [
            'email' => $this->user->email,
            'password' => $this->password,
        ]);
        $request->headers->set('Accept', 'application/json');
        $request->server->set('REQUEST_URI', '/api/auth/login');

        $response = $this->controller->login($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertTrue($data['requires_tfa']);
        $this->assertArrayHasKey('tfa_token', $data);
    }

    /**
     * Test setCurrentOrganization method updates user
     */
    public function test_set_current_organization_updates_user(): void
    {
        $request = UserHasOrganizationRequest::create(
            '/api/auth/set-current-organization',
            'POST',
            ['organization_id' => $this->organization->id]
        );
        
        $request->setUserResolver(function () {
            return $this->user;
        });

        // Validate request
        $validator = Validator::make($request->all(), $request->rules());
        if ($validator->fails()) {
            $this->fail('Validation failed: ' . json_encode($validator->errors()));
        }

        $response = $this->controller->setCurrentOrganization($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->user->refresh();
        $this->assertEquals($this->organization->id, $this->user->current_organization_id);
    }

    /**
     * Test logout method deletes token
     */
    public function test_logout_deletes_token(): void
    {
        // Create token
        $token = $this->user->createToken('test-token');
        $tokenPlainText = $token->plainTextToken;
        
        // Verify token exists before logout
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'test-token',
        ]);
        
        $request = Request::create('/api/auth/logout', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer ' . $tokenPlainText);
        
        // Set user resolver to return the authenticated user
        $request->setUserResolver(function () {
            return $this->user;
        });

        $response = $this->controller->logout($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        // Verify token was deleted (check that all tokens are deleted since we use tokens()->delete() as fallback)
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
        ]);
    }
}
