<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Support\Str;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * إعداد البثّ يصل التطبيق مع كل منشأة.
 *
 * الأدمن يختار لكل منشأة Pusher أو Reverb، والتطبيق يعرض قائمة منشآت عند الدخول
 * ويبدّل بينها. فالإعداد يُرسَل مع كل منشأة في كل موضع تظهر فيه — قائمة الدخول،
 * والتبديل، ومغادرة منشأة، والملف الشخصي — فيتّصل التطبيق بمزوّد المنشأة التي
 * يختارها بلا مفاتيح مضمّنة فيه ولا طلبٍ إضافي. والسرّ لا يخرج أبداً.
 */
class MobileOrganizationBroadcastTest extends CycleTestCase
{
    private Organization $onReverb;

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'mobile',
            'name' => 'Mobile App',
            'logo' => 'mobile.png',
            'status' => 1,
            'is_active' => 1,
        ]);

        foreach ([
            BroadcastProvider::SETTING_KEY => 'pusher',
            'pusher_app_key' => 'cloud-key',
            'pusher_app_secret' => 'cloud-secret',
            'pusher_app_id' => '2089431',
            'pusher_app_cluster' => 'us2',
            'reverb_app_key' => 'reverb-key',
            'reverb_app_secret' => 'reverb-secret',
            'reverb_app_id' => '221796',
            'reverb_host' => 'reverb.mnjz.net',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // منشأة ثانية للمالك نفسه، على Reverb صراحةً. الأولى على افتراضي المنصّة.
        $this->onReverb = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'broadcast_provider' => 'reverb',
        ]);
        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->onReverb->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        BroadcastProvider::forget();
    }

    private function assertNoSecret(string $json): void
    {
        $this->assertStringNotContainsString('cloud-secret', $json);
        $this->assertStringNotContainsString('reverb-secret', $json);
        $this->assertStringNotContainsString('"secret"', $json);
    }

    public function test_the_login_list_carries_each_organizations_own_config(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
            'device_name' => 'Test Device',
        ])->assertOk();

        $organizations = collect($response->json('data.organizations'))->keyBy('id');

        $reverb = $organizations[$this->onReverb->id]['broadcast'];
        $this->assertSame('reverb', $reverb['provider']);
        $this->assertSame('reverb-key', $reverb['key']);
        $this->assertSame('reverb.mnjz.net', $reverb['host']);
        $this->assertSame('presence-chats.ch' . $this->onReverb->id . '.' . $this->owner->id, $reverb['channel']);
        $this->assertSame(route('api.broadcasting.auth'), $reverb['auth_endpoint']);

        $cloud = $organizations[$this->organization->id]['broadcast'];
        $this->assertSame('pusher', $cloud['provider']);
        $this->assertSame('cloud-key', $cloud['key']);
        $this->assertNull($cloud['host'], 'سحابة Pusher تشتقّ عنوانها من التجميعة');
        $this->assertSame('us2', $cloud['cluster']);

        $this->assertNoSecret($response->getContent());
    }

    public function test_switching_organization_returns_the_new_organizations_config(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $response = $this->postJson('/api/v1/auth/set-current-organization', ['organization_id' => $this->onReverb->id])
            ->assertOk()
            ->assertJsonPath('data.current_organization_id', $this->onReverb->id)
            ->assertJsonPath('data.broadcast.provider', 'reverb')
            ->assertJsonPath('data.broadcast.host', 'reverb.mnjz.net');

        $this->assertNoSecret($response->getContent());

        $this->postJson('/api/v1/auth/set-current-organization', ['organization_id' => $this->organization->id])
            ->assertOk()
            ->assertJsonPath('data.broadcast.provider', 'pusher');
    }

    public function test_leaving_an_organization_returns_the_config_of_the_one_left_selected(): void
    {
        // المالك لا يغادر منشأته، فيغادر عضوٌ عادي منشأةً من اثنتين.
        $member = \App\Models\User::factory()->create(['role' => 'user']);
        foreach ([$this->organization, $this->onReverb] as $organization) {
            Team::factory()->create([
                'user_id' => $member->id,
                'organization_id' => $organization->id,
                'role' => 'agent',
                'created_by' => $this->owner->id,
            ]);
        }
        $member->forceFill(['current_mobile_organization_id' => $this->organization->id])->save();
        $this->actingAs($member, 'sanctum');

        $this->postJson('/api/v1/auth/leave-current-organization')
            ->assertOk()
            ->assertJsonPath('data.current_organization_id', $this->onReverb->id)
            ->assertJsonPath('data.broadcast.provider', 'reverb')
            ->assertJsonPath('data.broadcast.channel', 'presence-chats.ch' . $this->onReverb->id . '.' . $member->id);
    }

    public function test_the_profile_carries_the_selected_organizations_config(): void
    {
        $this->owner->forceFill(['current_mobile_organization_id' => $this->onReverb->id])->save();
        $this->actingAs($this->owner->fresh(), 'sanctum');

        $response = $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $this->onReverb->id)
            ->assertJsonPath('data.organization.broadcast.provider', 'reverb')
            ->assertJsonPath('data.organization.broadcast.key', 'reverb-key');

        $this->assertNoSecret($response->getContent());
    }

    public function test_the_config_endpoint_names_the_users_channel(): void
    {
        $this->owner->forceFill(['current_mobile_organization_id' => $this->onReverb->id])->save();
        $this->actingAs($this->owner->fresh(), 'sanctum');

        $this->getJson('/api/v1/broadcast-config')
            ->assertOk()
            ->assertJsonPath('data.provider', 'reverb')
            ->assertJsonPath('data.channel', 'presence-chats.ch' . $this->onReverb->id . '.' . $this->owner->id);
    }
}
