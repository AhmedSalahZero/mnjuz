<?php

namespace Tests\Feature;

use App\Events\ContactAiAssistanceUpdated;
use App\Events\ContactChatDeletedEvent;
use App\Events\NewPaymentEvent;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Pusher\Pusher;
use Tests\TestCase;

/**
 * مزوّد البثّ لكل منشأة.
 *
 * الأدمن يختار لكل منشأة Pusher أو Reverb، وما لم يُختَر يتبع افتراضي المنصّة.
 * والاختيار يبلغ كل حلقات البثّ: الحدث يُبثّ إلى مزوّد منشأته، والمصادقة على
 * قناتها تُوقَّع بسرّه، والداشبورد والتطبيق يتّصلان به. أي حلقة تتخلّف تعني أن
 * الرسالة تُحفظ ولا تصل لحظياً، بلا خطأ ظاهر.
 */
class OrganizationBroadcastProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET = '123.456';

    private User $owner;
    private User $member;
    private Organization $onReverb;
    private Organization $onDefault;

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();

        // بيئة الاختبار على المُذيع الصامت؛ الخوادم على pusher، والقنوات تُعاد
        // تسجيلها على المُذيع الذي يُقرأ فعلاً (انظر BroadcastAuthEndpointTest).
        Config::set('broadcasting.default', 'pusher');
        require base_path('routes/channels.php');

        foreach (['Google Authenticator' => 0, 'Mobile App' => 1] as $name => $active) {
            Addon::create([
                'uuid' => (string) Str::uuid(),
                'category' => 'test',
                'name' => $name,
                'logo' => 'x.png',
                'status' => 1,
                'is_active' => $active,
            ]);
        }

        foreach ([
            BroadcastProvider::SETTING_KEY => BroadcastProvider::PUSHER,
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

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->onReverb = Organization::factory()->create(['created_by' => $this->owner->id, 'broadcast_provider' => 'reverb']);
        $this->onDefault = Organization::factory()->create(['created_by' => $this->owner->id, 'broadcast_provider' => null]);

        $this->member = User::factory()->create(['role' => 'user']);
        foreach ([$this->onReverb, $this->onDefault] as $organization) {
            Team::create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'user_id' => $this->member->id,
                'role' => 'manager',
                'status' => 'active',
                'created_by' => $this->owner->id,
            ]);
        }

        BroadcastProvider::forget();
        BroadcastProvider::apply();
    }

    private function channelOf(Organization $organization): string
    {
        return 'presence-chats.ch' . $organization->id . '.' . $this->member->id;
    }

    private function signature(string $key, string $secret, string $channel, string $channelData): string
    {
        return $key . ':' . hash_hmac('sha256', self::SOCKET . ':' . $channel . ':' . $channelData, $secret);
    }

    // ------------------------------------------------- من أيّ مزوّد

    public function test_an_organization_without_a_choice_follows_the_platform_default(): void
    {
        $this->assertSame('pusher', BroadcastProvider::forOrganization($this->onDefault->id));

        Setting::where('key', BroadcastProvider::SETTING_KEY)->update(['value' => 'reverb']);
        BroadcastProvider::forget();

        $this->assertSame('reverb', BroadcastProvider::forOrganization($this->onDefault->id));
    }

    public function test_the_organizations_choice_wins_over_the_default(): void
    {
        $this->assertSame('reverb', BroadcastProvider::forOrganization($this->onReverb->id));
        $this->assertSame('pusher', BroadcastProvider::forOrganization($this->onDefault->id), 'اختيار منشأة لا يمسّ غيرها');
    }

    public function test_an_unknown_value_falls_back_to_the_default(): void
    {
        DB::table('organizations')->where('id', $this->onReverb->id)->update(['broadcast_provider' => 'ably']);
        BroadcastProvider::forget();

        $this->assertSame('pusher', BroadcastProvider::forOrganization($this->onReverb->id));
        $this->assertSame('pusher', BroadcastProvider::forOrganization(null));
    }

    /** العامل عملية طويلة العمر: تغيير الأدمن يبلغه بين وظيفة وأخرى. */
    public function test_a_running_worker_follows_a_change_between_jobs(): void
    {
        $this->assertSame('provider_reverb', BroadcastProvider::connectionNameForOrganization($this->onReverb->id));

        DB::table('organizations')->where('id', $this->onReverb->id)->update(['broadcast_provider' => 'pusher']);
        event(new Looping('redis', 'high'));

        $this->assertSame('provider_pusher', BroadcastProvider::connectionNameForOrganization($this->onReverb->id));
    }

    // ------------------------------------------------- البثّ

    public function test_both_provider_connections_are_defined_in_full(): void
    {
        $this->assertSame('reverb.mnjz.net', Config::get('broadcasting.connections.provider_reverb.options.host'));
        $this->assertSame('reverb-key', Config::get('broadcasting.connections.provider_reverb.key'));
        $this->assertSame('api-us2.pusher.com', Config::get('broadcasting.connections.provider_pusher.options.host'));
        $this->assertSame('cloud-key', Config::get('broadcasting.connections.provider_pusher.key'));
    }

    public function test_every_broadcast_event_goes_through_its_organizations_provider(): void
    {
        $events = [
            new ContactChatDeletedEvent($this->onReverb->id, 1),
            new NewPaymentEvent((object) ['id' => 1], $this->onReverb->id),
            new ContactAiAssistanceUpdated($this->onReverb->id, 1, '', true),
        ];

        foreach ($events as $event) {
            $this->assertSame(['provider_reverb'], $event->broadcastConnections(), get_class($event));
        }

        $this->assertSame(['provider_pusher'], (new ContactChatDeletedEvent($this->onDefault->id, 1))->broadcastConnections());
    }

    /** العامل نفسه، حدثان متتاليان لمنشأتين: كلٌّ يصل إلى مزوّد منشأته. */
    public function test_two_organizations_reach_their_own_provider_from_the_same_worker(): void
    {
        $reverb = Mockery::mock(Pusher::class);
        $reverb->shouldReceive('trigger')->once()
            ->withArgs(fn ($channels) => $channels === ['contact.chat.deleted.' . $this->onReverb->id])
            ->andReturn((object) []);

        $cloud = Mockery::mock(Pusher::class);
        $cloud->shouldReceive('trigger')->once()
            ->withArgs(fn ($channels) => $channels === ['contact.chat.deleted.' . $this->onDefault->id])
            ->andReturn((object) []);

        Broadcast::connection('provider_reverb')->setPusher($reverb);
        Broadcast::connection('provider_pusher')->setPusher($cloud);

        $manager = app(BroadcastingFactory::class);
        (new BroadcastEvent(new ContactChatDeletedEvent($this->onReverb->id, 1)))->handle($manager);
        (new BroadcastEvent(new ContactChatDeletedEvent($this->onDefault->id, 2)))->handle($manager);
    }

    // ------------------------------------------------- المصادقة

    /** @return array<string, array{0: string}> */
    public static function authEndpoints(): array
    {
        return ['الداشبورد' => ['/broadcasting/auth'], 'التطبيق' => ['/api/broadcasting/auth']];
    }

    /**
     * @dataProvider authEndpoints
     *
     * طلبان في العملية نفسها لمنشأتين: كلٌّ يُوقَّع بسرّ مزوّد منشأته.
     */
    public function test_each_channel_is_signed_with_its_organizations_secret(string $endpoint): void
    {
        $authorize = function (Organization $organization) use ($endpoint) {
            $request = $endpoint === '/broadcasting/auth'
                ? $this->actingAs($this->member)
                : tap($this, fn () => Sanctum::actingAs($this->member));

            return $request->postJson($endpoint, [
                'socket_id' => self::SOCKET,
                'channel_name' => $this->channelOf($organization),
            ])->assertOk()->json();
        };

        $reverb = $authorize($this->onReverb);
        $cloud = $authorize($this->onDefault);

        $this->assertSame(
            $this->signature('reverb-key', 'reverb-secret', $this->channelOf($this->onReverb), $reverb['channel_data']),
            $reverb['auth']
        );
        $this->assertSame(
            $this->signature('cloud-key', 'cloud-secret', $this->channelOf($this->onDefault), $cloud['channel_data']),
            $cloud['auth']
        );
    }

    public function test_an_outsider_is_still_refused(): void
    {
        $outsider = User::factory()->create(['role' => 'user']);

        $this->actingAs($outsider)
            ->postJson('/broadcasting/auth', [
                'socket_id' => self::SOCKET,
                'channel_name' => 'presence-chats.ch' . $this->onReverb->id . '.' . $outsider->id,
            ])
            ->assertForbidden();
    }

    public function test_the_organization_is_read_from_every_channel_shape(): void
    {
        $this->assertSame(7, BroadcastProvider::organizationFromChannel('presence-chats.ch7.12'));
        $this->assertSame(7, BroadcastProvider::organizationFromChannel('chats.ch7.12'));
        $this->assertSame(8, BroadcastProvider::organizationFromChannel('payments.ch8'));
        $this->assertSame(9, BroadcastProvider::organizationFromChannel('contact.chat.deleted.9'));
        $this->assertNull(BroadcastProvider::organizationFromChannel('presence-other.1'));
    }

    // ------------------------------------------------- الواجهة والتطبيق

    public function test_the_dashboard_connects_to_its_organizations_provider(): void
    {
        $reverb = BroadcastProvider::clientConfig($this->onReverb->id);
        $cloud = BroadcastProvider::clientConfig($this->onDefault->id);

        $this->assertSame(['reverb', 'reverb-key', 'reverb.mnjz.net'], [$reverb['provider'], $reverb['key'], $reverb['host']]);
        $this->assertSame(['pusher', 'cloud-key', null], [$cloud['provider'], $cloud['key'], $cloud['host']]);
        $this->assertStringNotContainsString('secret', json_encode([$reverb, $cloud]));
    }

    /** الإعداد المشترك لكل صفحات Inertia يتبع منشأة الجلسة. */
    public function test_the_shared_page_props_follow_the_session_organization(): void
    {
        $broadcastOn = function (?Organization $organization): array {
            $response = $this->withSession($organization ? ['current_organization' => $organization->id] : [])
                ->get('/login')
                ->assertOk();

            preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
            $this->assertNotEmpty($matches, 'الصفحة لا تحمل بيانات Inertia');

            return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props']['broadcast'];
        };

        $this->assertSame('reverb', $broadcastOn($this->onReverb)['provider']);
        $this->assertSame('pusher', $broadcastOn($this->onDefault)['provider']);
        $this->assertSame('pusher', $broadcastOn(null)['provider'], 'بلا منشأة (صفحات الأدمن) فالافتراضي');
    }

    public function test_the_mobile_app_connects_to_its_selected_organizations_provider(): void
    {
        $this->member->forceFill(['current_mobile_organization_id' => $this->onReverb->id])->save();
        Sanctum::actingAs($this->member->fresh());

        $this->getJson('/api/v1/broadcast-config')
            ->assertOk()
            ->assertJsonPath('data.provider', 'reverb')
            ->assertJsonPath('data.key', 'reverb-key')
            ->assertJsonPath('data.host', 'reverb.mnjz.net');

        $this->member->forceFill(['current_mobile_organization_id' => $this->onDefault->id])->save();
        BroadcastProvider::forget();
        Sanctum::actingAs($this->member->fresh());

        $this->getJson('/api/v1/broadcast-config')
            ->assertOk()
            ->assertJsonPath('data.provider', 'pusher')
            ->assertJsonPath('data.host', null);
    }
}
