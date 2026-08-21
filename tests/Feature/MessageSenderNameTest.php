<?php

namespace Tests\Feature;

use App\Http\Controllers\ApiController;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Services\Chat\ChatBroadcastPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * اسم الموظّف المُرسِل في مسارات تطبيق الجوال والبثّ.
 *
 * الداشبورد يعرض «أرسلها: فلان» فيعرف الفريق من ردّ على العميل. والتطبيق كان
 * يستقبل الاسم مجزّأً (first_name و last_name) بلا اسم كامل جاهز، لأن القائمة
 * البيضاء في بناء الحمولة تُسقط full_name رغم أنه مُلحَق على نموذج المستخدم.
 *
 * ثلاثة مسارات تبني الحمولة — v1 و v2 والبثّ — فالاختبارات تحرسها معاً:
 * إصلاح واحد منها وترك الآخر يعني أن الاسم يظهر في شاشة ويغيب في أخرى.
 */
class MessageSenderNameTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pusher_app_key', 'pusher_app_secret', 'pusher_app_id', 'pusher_app_cluster'] as $key) {
            Setting::create(['key' => $key, 'value' => 'test']);
        }

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+966500000001',
            'created_by' => $owner->id,
        ]);
    }

    private function agent(string $first, string $last): User
    {
        return User::factory()->create([
            'role' => 'user',
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    private function outboundFrom(?User $user): Chat
    {
        return Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(10),
            'type' => 'outbound',
            'user_id' => $user?->id,
            'status' => 'sent',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'created_at' => now(),
        ]);
    }

    /** @return array{v1: ?array, v2: ?array, broadcast: ?array} */
    private function senderFromEveryPath(Chat $chat): array
    {
        $chat = Chat::with('user', 'media', 'logs')->find($chat->id);
        $controller = app(ApiController::class);

        $invoke = function (string $method, ...$args) use ($controller) {
            $m = new ReflectionMethod(ApiController::class, $method);
            $m->setAccessible(true);

            return $m->invoke($controller, ...$args);
        };

        return [
            'v1' => $invoke('formatChatValue', $chat, $this->contact)['user'],
            'v2' => $invoke('formatChatMessageV2', $chat)['user'],
            'broadcast' => (new ChatBroadcastPayloadBuilder())
                ->buildMinimalValue($chat, (int) $this->organization->id, false)['user'],
        ];
    }

    // ------------------------------------------------- الاسم الكامل

    /**
     * جوهر الطلب: يظهر في التطبيق من أرسل الرسالة، بالاسم كاملاً — وفي
     * المسارات الثلاثة معاً لا في واحد.
     */
    public function test_the_full_sender_name_reaches_every_path(): void
    {
        $chat = $this->outboundFrom($this->agent('فواز', 'الشريف'));

        foreach ($this->senderFromEveryPath($chat) as $path => $sender) {
            $this->assertSame('فواز الشريف', $sender['full_name'] ?? null, "المسار {$path}");
        }
    }

    /**
     * الاسم الكامل وحده يُرسَل: الحقلان المنفصلان لا مستهلك لهما، ووجودهما
     * يُغري بتركيب اسمٍ يخالف ما يعرضه الداشبورد.
     */
    public function test_only_the_full_name_is_sent(): void
    {
        $chat = $this->outboundFrom($this->agent('فواز', 'الشريف'));

        foreach ($this->senderFromEveryPath($chat) as $path => $sender) {
            $this->assertSame(['full_name'], array_keys($sender), "المسار {$path}");
        }
    }

    /** موظّفان مختلفان يظهران بأسمائهما — وهو الغرض كلّه. */
    public function test_two_agents_are_told_apart(): void
    {
        $fromAhmed = $this->outboundFrom($this->agent('أحمد', 'صلاح'));
        $fromFawaz = $this->outboundFrom($this->agent('فواز', 'الشريف'));

        $this->assertSame('أحمد صلاح', $this->senderFromEveryPath($fromAhmed)['v2']['full_name']);
        $this->assertSame('فواز الشريف', $this->senderFromEveryPath($fromFawaz)['v2']['full_name']);
    }

    /** اسم بلا لقب لا يخرج بمسافة معلّقة في نهايته. */
    public function test_a_missing_last_name_does_not_leave_a_trailing_space(): void
    {
        $chat = $this->outboundFrom($this->agent('فواز', ''));

        foreach ($this->senderFromEveryPath($chat) as $path => $sender) {
            $this->assertSame('فواز', $sender['full_name'] ?? null, "المسار {$path}");
        }
    }

    // ------------------------------------- الرسائل بلا مُرسِل بشري

    /**
     * 6.6% من الصادر بلا موظّف — حملات وردود آلية ومسارات أتمتة. يصل
     * user = null، ويجب أن يحتمله التطبيق بلا انهيار.
     */
    public function test_an_automated_message_reports_no_sender(): void
    {
        $chat = $this->outboundFrom(null);

        foreach ($this->senderFromEveryPath($chat) as $path => $sender) {
            $this->assertNull($sender, "المسار {$path} يجب أن يُرجع null لا كائناً فارغاً");
        }
    }

    /** الوارد لا مُرسِل له من طرفنا. */
    public function test_an_inbound_message_reports_no_sender(): void
    {
        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(10),
            'type' => 'inbound',
            'status' => 'delivered',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'created_at' => now(),
        ]);

        $this->assertNull($this->senderFromEveryPath($chat)['v2']);
    }

    // ------------------------------------------- حراسة المسارات

    /**
     * القائمة البيضاء هي موضع العلّة: إغفال full_name في أحد المواضع الثلاثة
     * يُظهر الاسم في شاشة ويُخفيه في أخرى، وهو انحدار يصعب ملاحظته.
     */
    public function test_every_payload_builder_whitelists_the_full_name(): void
    {
        $sources = [
            'app/Http/Controllers/ApiController.php' => 2,
            'app/Services/Chat/ChatBroadcastPayloadBuilder.php' => 1,
        ];

        foreach ($sources as $file => $expected) {
            $source = file_get_contents(base_path($file));

            $this->assertSame(
                $expected,
                substr_count($source, "'full_name' => trim((string) (\$arr['user']['full_name']"),
                "بناء المُرسِل في {$file} يجب أن يبقى في {$expected} موضع"
            );

            $this->assertStringNotContainsString(
                "array_flip(['first_name', 'last_name'",
                $source,
                "{$file} ما زال يُرسل الاسم مجزّأً"
            );
        }
    }
}
