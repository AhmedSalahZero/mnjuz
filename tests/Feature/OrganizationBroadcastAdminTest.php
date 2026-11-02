<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Broadcasting\BroadcastProvider;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الأدمن يختار مزوّد البثّ: لكل منشأة في صفحتها، والافتراضي وبيانات Reverb في
 * إعدادات البثّ. ولا يُختار مزوّدٌ بياناته ناقصة: يتّصل المتصفّح بلا مفتاح أو
 * عنوان، فتُحفظ الرسائل ولا تصل لحظياً بلا خطأ ظاهر.
 */
class OrganizationBroadcastAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Organization $organization;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'security',
            'name' => 'Google Authenticator',
            'logo' => 'ga.png',
            'status' => 1,
            'is_active' => 0,
        ]);

        foreach ([
            BroadcastProvider::SETTING_KEY => 'pusher',
            'broadcast_driver' => 'pusher',
            'pusher_app_key' => 'cloud-key',
            'pusher_app_secret' => 'cloud-secret',
            'pusher_app_id' => '2089431',
            'pusher_app_cluster' => 'us2',
            'reverb_app_key' => '',
            'reverb_app_secret' => '',
            'reverb_app_id' => '',
            'reverb_host' => '',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->plan = SubscriptionPlan::create(['name' => 'P', 'price' => 0, 'period' => 'monthly', 'metadata' => '{}']);
    }

    private function configureReverb(): void
    {
        foreach (['reverb_app_key' => 'reverb-key', 'reverb_app_secret' => 'reverb-secret', 'reverb_app_id' => '221796', 'reverb_host' => 'reverb.mnjz.net'] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        BroadcastProvider::forget();
    }

    private function updateOrganization(?string $provider)
    {
        return $this->actingAs($this->admin, 'admin')->put('/admin/organizations/' . $this->organization->uuid, [
            'name' => $this->organization->name,
            'plan' => $this->plan->uuid,
            'broadcast_provider' => $provider,
        ]);
    }

    /** @return array<string, mixed> */
    private function broadcastSettings(array $overrides = []): array
    {
        return array_merge([
            'broadcast_driver' => 'pusher',
            'pusher_app_key' => 'cloud-key',
            'pusher_app_cluster' => 'us2',
            'broadcast_provider' => 'pusher',
            'reverb_app_key' => '',
            'reverb_host' => '',
            'reverb_port' => '443',
            'reverb_scheme' => 'https',
        ], $overrides);
    }

    // ------------------------------------------------- صفحة المنشأة

    public function test_the_admin_sets_an_organizations_provider(): void
    {
        $this->configureReverb();

        $this->updateOrganization('reverb')->assertSessionHasNoErrors();

        $this->assertSame('reverb', $this->organization->fresh()->broadcast_provider);
        $this->assertSame('reverb', BroadcastProvider::forOrganization($this->organization->id));
    }

    public function test_an_empty_choice_returns_the_organization_to_the_platform_default(): void
    {
        $this->organization->forceFill(['broadcast_provider' => 'pusher'])->save();

        $this->updateOrganization('')->assertSessionHasNoErrors();

        $this->assertNull($this->organization->fresh()->broadcast_provider);
    }

    public function test_reverb_cannot_be_chosen_while_its_settings_are_incomplete(): void
    {
        $this->updateOrganization('reverb')->assertSessionHasErrors('broadcast_provider');

        $this->assertNull($this->organization->fresh()->broadcast_provider);
    }

    public function test_an_unknown_provider_is_rejected(): void
    {
        $this->updateOrganization('ably')->assertSessionHasErrors('broadcast_provider');
    }

    public function test_a_non_admin_cannot_change_it(): void
    {
        $this->configureReverb();
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'user')->put('/admin/organizations/' . $this->organization->uuid, [
            'name' => $this->organization->name,
            'plan' => $this->plan->uuid,
            'broadcast_provider' => 'reverb',
        ]);

        $this->assertNull($this->organization->fresh()->broadcast_provider);
    }

    public function test_the_page_knows_the_default_and_which_providers_are_ready(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->get('/admin/organizations/' . $this->organization->uuid)->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $props = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];

        $this->assertSame(['default' => 'pusher', 'ready' => ['pusher' => true, 'reverb' => false]], $props['broadcastProviders']);
    }

    // ------------------------------------------------- إعدادات البثّ

    public function test_reverb_settings_are_saved_and_its_secret_never_reaches_the_browser(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put('/admin/settings?type=broadcast', $this->broadcastSettings([
                'reverb_app_id' => '221796',
                'reverb_app_key' => 'reverb-key',
                'reverb_app_secret' => 'reverb-secret',
                'reverb_host' => 'reverb.mnjz.net',
            ]))
            ->assertSessionHasNoErrors();

        BroadcastProvider::forget();
        $this->assertTrue(BroadcastProvider::isConfigured('reverb'));

        $redacted = collect(SettingService::redactForClient(Setting::get()))->pluck('value', 'key');
        $this->assertSame('', $redacted['reverb_app_secret']);
        $this->assertTrue($redacted['reverb_app_secret_is_set']);
    }

    public function test_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->configureReverb();

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/settings?type=broadcast', $this->broadcastSettings([
                'reverb_app_key' => 'reverb-key',
                'reverb_host' => 'reverb.mnjz.net',
                'reverb_app_secret' => '',
                'reverb_app_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('reverb-secret', Setting::where('key', 'reverb_app_secret')->value('value'));
        $this->assertSame('221796', Setting::where('key', 'reverb_app_id')->value('value'));
    }

    public function test_the_default_cannot_become_reverb_without_its_settings(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->put('/admin/settings?type=broadcast', $this->broadcastSettings(['broadcast_provider' => 'reverb']))
            ->assertSessionHasErrors(['reverb_app_key', 'reverb_host', 'reverb_app_secret']);

        $this->assertSame('pusher', Setting::where('key', BroadcastProvider::SETTING_KEY)->value('value'));
    }

    /** منشأةٌ على Reverb: لا تُمسح بياناته من تحتها. */
    public function test_reverb_settings_cannot_be_emptied_while_an_organization_uses_it(): void
    {
        $this->configureReverb();
        $this->organization->forceFill(['broadcast_provider' => 'reverb'])->save();

        $this->actingAs($this->admin, 'admin')
            ->put('/admin/settings?type=broadcast', $this->broadcastSettings())
            ->assertSessionHasErrors(['reverb_app_key', 'reverb_host']);

        $this->assertSame('reverb.mnjz.net', Setting::where('key', 'reverb_host')->value('value'));
    }
}
