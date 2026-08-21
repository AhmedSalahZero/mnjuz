<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * مصادقة قناة الحضور — الحلقة التي لا تُرى حين تنكسر.
 *
 * قناة chats.ch{org}.{user} قناة حضور، فالمتصفّح لا يشترك فيها مباشرة: يطلب
 * توقيعاً من /broadcasting/auth، ثم يرسله إلى خادم البثّ الذي يتحقّق منه
 * بسرّه. أي اختلاف بين السرّ الذي وقّعنا به والسرّ الذي يتحقّق به الخادم يعني
 * رفض الاشتراك — والصفحة تبقى صامتة بلا خطأ ظاهر.
 *
 * وبعد تبديل المزوّد يجب أن يتبع التوقيع الوجهة الجديدة: توقيعٌ بسرّ Pusher
 * يُرسَل إلى Reverb يُرفض دائماً.
 */
class BroadcastAuthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SOCKET = '123.456';

    private User $user;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();

        // بيئة الاختبار تستعمل المُذيع الصامت (null): يقبل كل قناة ويردّ فارغاً،
        // فيمرّ اختبارُ رفضٍ وهو لم يرفض شيئاً. الخوادم على pusher.
        Config::set('broadcasting.default', 'pusher');

        // القنوات تُسجَّل على كائن المُذيع، وقد سُجّلت عند الإقلاع على الصامت.
        // إعادة تحميلها بعد تبديل الافتراضي تضعها حيث تُقرأ فعلاً.
        require base_path('routes/channels.php');

        // HandleInertiaRequests يقرأ is_active بلا حارس null على مسارات الويب،
        // و/broadcasting/auth منها. البذرة هنا لا في الكود: تعديل الإنتاج
        // لأجل اختبار يغيّر ما نقيسه.
        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'security',
            'name' => 'Google Authenticator',
            'logo' => 'ga.png',
            'status' => 1,
            'is_active' => 0,
        ]);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->user = User::factory()->create(['role' => 'user']);

        Team::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'role' => 'manager',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        BroadcastProvider::forget();
        BroadcastProvider::apply();
    }

    private function useReverb(): void
    {
        $this->set('reverb_app_key', 'reverb-key');
        $this->set('reverb_app_secret', 'reverb-secret');
        $this->set('reverb_app_id', '221796');
        $this->set('reverb_host', 'reverb.mnjz.net');
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::REVERB);
    }

    private function usePusher(): void
    {
        $this->set('pusher_app_key', 'cloud-key');
        $this->set('pusher_app_secret', 'cloud-secret');
        $this->set('pusher_app_id', '2089431');
        $this->set('pusher_app_cluster', 'us2');
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);
    }

    private function channel(?int $userId = null): string
    {
        return 'presence-chats.ch' . $this->organization->id . '.' . ($userId ?? $this->user->id);
    }

    /** @return array{auth: string, channel_data: string} */
    private function authorize(?string $channel = null, ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->user)
            ->postJson('/broadcasting/auth', [
                'socket_id' => self::SOCKET,
                'channel_name' => $channel ?? $this->channel(),
            ]);

        $response->assertOk();

        return $response->json();
    }

    /** التوقيع كما يحسبه خادم البثّ عند التحقّق. */
    private function expectedSignature(string $key, string $secret, string $channel, string $channelData): string
    {
        return $key . ':' . hash_hmac(
            'sha256',
            self::SOCKET . ':' . $channel . ':' . $channelData,
            $secret
        );
    }

    // ------------------------------------------------------ التوقيع

    public function test_it_signs_a_presence_channel_with_the_reverb_secret(): void
    {
        $this->useReverb();

        $body = $this->authorize();

        $this->assertSame(
            $this->expectedSignature('reverb-key', 'reverb-secret', $this->channel(), $body['channel_data']),
            $body['auth'],
            'التوقيع لا يطابق سرّ Reverb — الخادم سيرفض الاشتراك والصفحة تبقى صامتة.'
        );
    }

    public function test_it_signs_with_the_pusher_secret_when_on_pusher(): void
    {
        $this->usePusher();

        $body = $this->authorize();

        $this->assertSame(
            $this->expectedSignature('cloud-key', 'cloud-secret', $this->channel(), $body['channel_data']),
            $body['auth']
        );
    }

    /**
     * الحلقة الحرجة: التبديل يجب أن يبلغ المصادقة أيضاً. توقيعٌ بسرّ المزوّد
     * القديم يُرفض دائماً — وهذا يبدو «الرسالة لا تصل» لا «المصادقة فشلت».
     */
    public function test_the_signature_follows_a_switch(): void
    {
        $this->usePusher();
        $onPusher = $this->authorize();

        $this->useReverb();
        $onReverb = $this->authorize();

        $this->assertNotSame($onPusher['auth'], $onReverb['auth']);
        $this->assertStringStartsWith('reverb-key:', $onReverb['auth']);
        $this->assertStringStartsWith('cloud-key:', $onPusher['auth']);
    }

    // ------------------------------------------------------ الصلاحية

    /** بيانات الحضور تحمل هوية المشترك كما تتوقّعها القناة. */
    public function test_the_channel_data_carries_the_user_id(): void
    {
        $this->useReverb();

        $data = json_decode($this->authorize()['channel_data'], true);

        $this->assertSame((string) $this->user->id, (string) $data['user_id']);
    }

    /** لا يشترك أحد في قناة غيره. */
    public function test_a_user_cannot_subscribe_to_another_users_channel(): void
    {
        $this->useReverb();
        $other = User::factory()->create(['role' => 'user']);

        $this->actingAs($this->user)
            ->postJson('/broadcasting/auth', [
                'socket_id' => self::SOCKET,
                'channel_name' => $this->channel($other->id),
            ])
            ->assertForbidden();
    }

    /** ولا من هو خارج المنظّمة. */
    public function test_an_outsider_is_refused(): void
    {
        $this->useReverb();
        $outsider = User::factory()->create(['role' => 'user']);

        $this->actingAs($outsider)
            ->postJson('/broadcasting/auth', [
                'socket_id' => self::SOCKET,
                'channel_name' => 'presence-chats.ch' . $this->organization->id . '.' . $outsider->id,
            ])
            ->assertForbidden();
    }
}
