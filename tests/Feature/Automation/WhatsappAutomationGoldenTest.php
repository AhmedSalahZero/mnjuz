<?php

namespace Tests\Feature\Automation;

use App\Jobs\SendTextMessageJob;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Services\ConversationRatingService;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Models\FlowLog;
use Illuminate\Support\Facades\Http;
use Modules\IntelliReply\Services\AIResponseService;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;
use Tests\Support\FakeHttpsStream;

/**
 * لقطة لما يرسله الردّ الآلي إلى واتساب اليوم — حرفاً بحرف.
 *
 * الردّ الآلي صار يمرّ بناقلٍ يعرف القنوات (المحادثة المباشرة). هذه اللقطات
 * سُجّلت على الكود قبل ذلك التغيير، وتضمن أن مسار واتساب لم يتغيّر فيه بايت:
 * عنوان Graph (والـ AI ما زال على v18.0)، والترويسة، والجسم، وصفوف المحادثة
 * المحفوظة، وسجلّ الـ flow.
 *
 * لإعادة التسجيل عمداً: GOLDEN_RECORD=1 php artisan test --filter=WhatsappAutomationGoldenTest
 */
class WhatsappAutomationGoldenTest extends FlowBuilderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // نوع الملف يُسأل عنه بـ HEAD، وحجمه بتنزيله (FakeHttpsStream).
        Http::fake(['cdn.example.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg'])]);
        FakeHttpsStream::register('0123456789');
    }

    protected function tearDown(): void
    {
        FakeHttpsStream::restore();

        parent::tearDown();
    }

    public function test_basic_text_reply(): void
    {
        $this->basicReply('مرحبا', 'أهلاً {first_name}، كيف نخدمك؟');

        $this->receive('مرحبا');

        $this->assertMatchesGolden('basic_text_reply');
    }

    public function test_basic_location_request_reply(): void
    {
        $this->autoReply('موقع', ['type' => 'location_request', 'data' => ['text' => 'أرسل موقعك يا {first_name}']]);

        $this->receive('موقع');

        $this->assertMatchesGolden('basic_location_request_reply');
    }

    public function test_basic_image_reply(): void
    {
        $this->autoReply('صورة', ['type' => 'image', 'data' => ['file' => [
            'name' => 'menu.png',
            'location' => 'uploads/media/menu.png',
            'url' => 'https://cdn.example.com/uploads/media/menu.png',
        ]]]);

        $this->receive('صورة');

        $this->assertMatchesGolden('basic_image_reply');
    }

    public function test_flow_text_buttons_and_reply(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->textNode('2', 'مرحباً {first_name}'),
            $this->buttonsNode('3', 'هل تريد المتابعة؟', ['نعم', 'لا'], 'اختر واحداً'),
            $this->textNode('4', 'تمّ، شكراً'),
            $this->textNode('5', 'حسناً'),
        ], [['1', '2'], ['2', '3'], ['3', '4', 'a'], ['3', '5', 'b']]);

        // عقدة رسالة واحدة لكل رسالة واردة: النصّ، ثم الأزرار، ثم ما بعد الضغطة.
        $this->receive('start');
        $this->receive('التالي');
        $this->receiveButton('نعم');

        $this->assertMatchesGolden('flow_text_buttons_and_reply');
    }

    public function test_flow_media_cta_and_list(): void
    {
        $media = $this->rawNode('2', 'media', [
            'type' => 'media',
            'mediaType' => 'image',
            'media' => [
                'path' => 'https://cdn.example.com/catalog.jpg',
                'location' => 'aws',
                'metadata' => json_encode(['name' => 'catalog.jpg']),
            ],
            'caption' => 'كتالوج {first_name}',
        ]);
        $cta = $this->rawNode('3', 'buttons', [
            'type' => 'interactive buttons',
            'buttonType' => 'cta',
            'body' => 'تصفّح المتجر',
            'footer' => 'متجرنا',
            'headerType' => 'text',
            'headerText' => 'عرض اليوم',
            'ctaUrlButton' => ['displayText' => 'افتح المتجر', 'url' => 'https://shop.example.com/?c={first_name}'],
        ]);
        $list = $this->rawNode('4', 'list', [
            'type' => 'interactive list',
            'body' => 'اختر القسم',
            'footer' => 'نردّ خلال دقائق',
            'headerType' => 'image',
            'headerMedia' => ['path' => 'https://cdn.example.com/header.jpg'],
            'buttonLabel' => 'الأقسام',
            'sections' => [
                ['title' => 'المبيعات', 'rows' => [['title' => 'طلب جديد', 'description' => 'ابدأ طلباً'], ['title' => 'تتبّع طلب', 'description' => '']]],
                ['title' => 'الدعم', 'rows' => [['title' => 'مشكلة', 'description' => 'أخبرنا']]],
            ],
        ]);

        $this->makeFlow(
            [$this->startNode('keywords', 'start'), $media, $cta, $list],
            [['1', '2'], ['2', '3'], ['3', '4']]
        );

        $this->receive('start');
        $this->receive('التالي');
        $this->receive('التالي');

        $this->assertMatchesGolden('flow_media_cta_and_list');
    }

    /** المساعد الذكي يرسل على v18.0 بخلاف البقية — يجب أن يبقى كذلك. */
    public function test_ai_text_reply(): void
    {
        $service = app(AIResponseService::class);
        $send = new \ReflectionMethod($service, 'sendResponse');
        $send->setAccessible(true);

        $this->assertTrue($send->invoke($service, $this->contact, 'text', ['text' => 'أهلاً، المنتج متوفّر.'], ['allow_audio_response' => false]));

        $this->assertMatchesGolden('ai_text_reply');
    }

    public function test_working_hours_notice(): void
    {
        (new SendTextMessageJob($this->organization->id, $this->contact->uuid, 'نحن خارج أوقات العمل وسنعود قريباً'))->handle();

        $this->assertMatchesGolden('working_hours_notice');
    }

    public function test_rating_survey_button(): void
    {
        $metadata = json_decode($this->organization->metadata, true);
        $metadata['ratings'] = ['active' => true, 'message' => 'قيّمنا يا {first_name}: {rating_link}', 'button_label' => 'قيّم الخدمة', 'cooldown_days' => 0];
        $this->organization->update(['metadata' => json_encode($metadata)]);
        $this->inbound('شكراً');

        $this->assertNotNull(ConversationRatingService::onConversationClosed($this->contact->fresh()));

        $this->assertMatchesGolden('rating_survey_button');
    }

    // ───────────────────── أدوات ─────────────────────

    private function autoReply(string $trigger, array $metadata): void
    {
        AutoReply::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $trigger,
            'trigger' => $trigger,
            'match_criteria' => 'contains',
            'metadata' => json_encode($metadata),
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);
    }

    private function rawNode(string $id, string $type, array $fields): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'label' => "{$type} node",
            'position' => ['x' => 0, 'y' => 0],
            'data' => ['title' => $type, 'metadata' => ['fields' => $fields]],
        ];
    }

    private function assertMatchesGolden(string $name): void
    {
        $snapshot = [
            'requests' => array_map(static function (array $entry) {
                $request = $entry['request'];
                $raw = (string) $request->getBody();

                return [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'authorization' => $request->getHeaderLine('Authorization'),
                    'body' => json_decode($raw, true) ?? $raw,
                ];
            }, $this->graphRequests),
            'chats' => Chat::with('media')
                ->where('contact_id', $this->contact->id)
                ->where('type', 'outbound')
                ->orderBy('id')
                ->get()
                ->map(static fn (Chat $chat) => [
                    'wam_id' => $chat->wam_id,
                    'status' => $chat->status,
                    'user_id' => $chat->user_id,
                    'channel' => $chat->channel,
                    'metadata' => json_decode((string) $chat->metadata, true),
                    'media' => $chat->media ? [
                        'name' => $chat->media->name,
                        'path' => $chat->media->path,
                        'type' => $chat->media->type,
                        'location' => $chat->media->location,
                    ] : null,
                ])
                ->all(),
            'flow_logs' => FlowLog::count(),
        ];

        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        // رمز رابط التقييم عشوائي في كل تشغيل.
        $json = preg_replace('#/rate/[A-Za-z0-9]{48}#', '/rate/{token}', $json);

        $path = base_path("tests/Fixtures/automation-golden/{$name}.json");

        if (!is_file($path) || env('GOLDEN_RECORD')) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $json);
            $this->markTestIncomplete("Golden snapshot recorded: {$name}");
        }

        $this->assertSame(file_get_contents($path), $json, "WhatsApp automation output changed: {$name}");
    }
}
