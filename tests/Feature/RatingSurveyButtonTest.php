<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConversationRatingService;
use App\Services\WhatsappService;
use App\Models\Chat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * استبيان الرضا يصل زرّاً لا رابطاً عارياً.
 *
 * كان يُرسل نصّاً يحمل رابطاً طويلاً: يُقرأ رديئاً على الجوال، ويبدو مريباً،
 * ويحتاج من العميل نسخاً أو ضغطاً على نصّ غير واضح أنه قابل للضغط. زرّ cta_url
 * يخفي الرابط ويجعلها خطوة واحدة.
 *
 * وشكل الحمولة هو ما يقرّر النجاح: مفتاح ناقص أو زائد تردّه Meta كلّه، والردّ
 * يمرّ في مسار لا يراقبه أحد — فلا يصل الاستبيان ولا يُشتكى منه.
 */
class RatingSurveyButtonTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://chat.waz.com.sa/rate/abc123';

    private Organization $organization;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'أحمد',
            'phone' => '+201025894984',
            'created_by' => $owner->id,
        ]);
    }

    /** @param array<string, mixed> $ratings */
    private function withRatingSettings(array $ratings): array
    {
        $this->organization->forceFill([
            'metadata' => json_encode(['ratings' => $ratings]),
        ])->save();

        return ConversationRatingService::settings((int) $this->organization->id);
    }

    /** حمولة زرّ التقييم كما تصل Meta. */
    private function payload(string $body, string $label = 'تقييم مستوى الخدمة'): array
    {
        return WhatsappService::buildInteractivePayload(
            WhatsappService::CTA_URL,
            $body,
            ['display_text' => $label, 'url' => self::LINK]
        );
    }

    // ------------------------------------------------- شكل الحمولة

    public function test_the_survey_is_sent_as_a_cta_url_button(): void
    {
        $payload = $this->payload('شكراً لتواصلك معنا');

        $this->assertSame('interactive', $payload['type']);
        $this->assertSame('cta_url', $payload['interactive']['type']);
        $this->assertSame('cta_url', $payload['interactive']['action']['name']);
    }

    public function test_the_button_carries_the_label_and_the_link(): void
    {
        $parameters = $this->payload('متن')['interactive']['action']['parameters'];

        $this->assertSame('تقييم مستوى الخدمة', $parameters['display_text']);
        $this->assertSame(self::LINK, $parameters['url']);
    }

    public function test_the_body_text_travels_with_the_button(): void
    {
        $payload = $this->payload('شكراً لتواصلك معنا 🌟');

        $this->assertSame('شكراً لتواصلك معنا 🌟', $payload['interactive']['body']['text']);
    }

    /**
     * الترويسة والتذييل اختياريان، وإرسال مفتاح فارغ يجعل Meta ترفض الرسالة.
     */
    public function test_an_absent_header_or_footer_is_omitted_entirely(): void
    {
        $payload = $this->payload('متن');

        $this->assertArrayNotHasKey('header', $payload['interactive']);
        $this->assertArrayNotHasKey('footer', $payload['interactive']);
    }

    public function test_a_header_and_footer_are_included_when_given(): void
    {
        $payload = WhatsappService::buildInteractivePayload(
            WhatsappService::CTA_URL,
            'متن',
            ['display_text' => 'تقييم', 'url' => self::LINK],
            ['type' => 'text', 'text' => 'عزيزنا العميل'],
            'منجز'
        );

        $this->assertSame(['type' => 'text', 'text' => 'عزيزنا العميل'], $payload['interactive']['header']);
        $this->assertSame(['text' => 'منجز'], $payload['interactive']['footer']);
    }

    /** الأنواع التفاعلية الأخرى لم تتغيّر بإخراج البنّاء. */
    public function test_reply_buttons_still_build_as_before(): void
    {
        $payload = WhatsappService::buildInteractivePayload(
            'interactive buttons',
            'اختر',
            [['id' => 'yes', 'title' => 'نعم'], ['id' => 'no', 'title' => 'لا']]
        );

        $this->assertSame('button', $payload['interactive']['type']);
        $this->assertSame(
            [
                ['type' => 'reply', 'reply' => ['id' => 'yes', 'title' => 'نعم']],
                ['type' => 'reply', 'reply' => ['id' => 'no', 'title' => 'لا']],
            ],
            $payload['interactive']['action']['buttons']
        );
    }

    // --------------------------------------------------- متن الرسالة

    /** الرابط صار زرّاً: بقاؤه في المتن يُكرّره ويُفقد الزرّ معناه. */
    public function test_the_link_placeholder_leaves_no_trace_in_the_body(): void
    {
        $settings = $this->withRatingSettings(['active' => true]);

        $body = ConversationRatingService::buildBody(
            (int) $this->organization->id,
            $this->contact,
            $settings
        );

        $this->assertStringNotContainsString('{rating_link}', $body);
        $this->assertStringNotContainsString('http', $body);
    }

    /** ولا ينتهي المتن بنقطتين معلّقتين بعد نزع الرابط. */
    public function test_the_dangling_punctuation_is_trimmed(): void
    {
        $settings = $this->withRatingSettings([
            'active' => true,
            'message' => 'يسعدنا تقييمك لمستوى الخدمة: {rating_link}',
        ]);

        $body = ConversationRatingService::buildBody((int) $this->organization->id, $this->contact, $settings);

        $this->assertSame('يسعدنا تقييمك لمستوى الخدمة', $body);
    }

    /** متن بلا شيء سوى الرابط يعود إلى النصّ الافتراضي — Meta ترفض متناً فارغاً. */
    public function test_a_message_that_is_only_a_link_falls_back_to_the_default_text(): void
    {
        $settings = $this->withRatingSettings(['active' => true, 'message' => '{rating_link}']);

        $body = ConversationRatingService::buildBody((int) $this->organization->id, $this->contact, $settings);

        $this->assertNotSame('', trim($body));
        $this->assertStringContainsString('تقييمك', $body);
    }

    /** المتن لا يتجاوز حدّ واتساب. */
    public function test_the_body_is_capped_at_the_whatsapp_limit(): void
    {
        $settings = $this->withRatingSettings([
            'active' => true,
            'message' => str_repeat('ن', ConversationRatingService::BODY_MAX + 200),
        ]);

        $body = ConversationRatingService::buildBody((int) $this->organization->id, $this->contact, $settings);

        $this->assertSame(ConversationRatingService::BODY_MAX, mb_strlen($body));
    }

    // ---------------------------------------------------- نصّ الزرّ

    public function test_the_button_label_defaults_when_unset(): void
    {
        $settings = $this->withRatingSettings(['active' => true]);

        $this->assertSame(ConversationRatingService::DEFAULT_BUTTON_LABEL, $settings['button_label']);
    }

    public function test_a_company_can_choose_its_own_button_label(): void
    {
        $settings = $this->withRatingSettings(['active' => true, 'button_label' => 'قيّم خدمتنا']);

        $this->assertSame('قيّم خدمتنا', $settings['button_label']);
    }

    /** واتساب يقصر نصّ الزرّ على ٢٠ حرفاً، وتجاوزه يردّ الرسالة كلّها. */
    public function test_a_long_label_is_cut_to_the_whatsapp_limit(): void
    {
        $label = ConversationRatingService::normalizeButtonLabel(str_repeat('ط', 40));

        $this->assertSame(ConversationRatingService::BUTTON_LABEL_MAX, mb_strlen($label));
    }

    public function test_a_blank_label_returns_to_the_default(): void
    {
        $this->assertSame(
            ConversationRatingService::DEFAULT_BUTTON_LABEL,
            ConversationRatingService::normalizeButtonLabel('   ')
        );
    }

    // ------------------------------------------------- سجلّ المحادثة

    /**
     * الموظّف يجب أن يرى ما استلمه العميل. حفظ المتن وحده يُخفي الوجهة تماماً.
     */
    public function test_the_conversation_record_keeps_the_button_and_its_destination(): void
    {
        $metadata = WhatsappService::ctaUrlMetadata('شكراً لتواصلك معنا', [
            'display_text' => 'تقييم مستوى الخدمة',
            'url' => self::LINK,
        ]);

        $this->assertSame('text', $metadata['type'], 'تغيير النوع يُسقط تطبيق الجوال في فرعه الافتراضي.');
        $this->assertStringContainsString('شكراً لتواصلك معنا', $metadata['text']['body']);
        $this->assertStringContainsString(self::LINK, $metadata['text']['body']);
        $this->assertSame(['display_text' => 'تقييم مستوى الخدمة', 'url' => self::LINK], $metadata['cta_url']);
    }

    /** بلا رابط لا علامة: نترك السجلّ كما كان بدل اختراع حقل فارغ. */
    public function test_a_missing_url_records_nothing_special(): void
    {
        $this->assertNull(WhatsappService::ctaUrlMetadata('متن', ['display_text' => 'تقييم']));
    }

    // ---------------------------------------------------- التفعيل

    /** رسالة واردة حديثة تفتح نافذة الـ٢٤ ساعة. */
    private function openMessagingWindow(): void
    {
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(12),
            'type' => 'inbound',
            'status' => 'delivered',
            'is_read' => 1,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'created_at' => now()->subMinutes(5),
        ]);

        // النموذج المحمَّل قبل الرسالة يحمل «آخر وارد = لا شيء»، فيبدو الفحص
        // مغلقاً ويتوقّف التدفّق قبل ما نقيسه.
        $this->contact = $this->contact->fresh();
    }

    /**
     * مفتاح الشركة: مطفأ ⇒ لا استبيان أصلاً.
     *
     * النافذة مفتوحة عمداً هنا. بدونها يتوقّف التدفّق عند فحص النافذة، فيمرّ
     * الاختبار ولو أُلغي المفتاح كلّياً — نجاحٌ لسببٍ آخر لا يحرس شيئاً.
     */
    /**
     * أثر التدفّق: ما بلغه قبل أن يتوقّف.
     *
     * @return array<int, string>
     */
    private function trace(callable $run): array
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages) {
            $messages[] = (string) $event->message;
        });

        $run();

        return $messages;
    }

    private const REACHED_SENDING = 'WhatsApp is not configured';

    public function test_a_company_can_switch_the_survey_off(): void
    {
        $this->openMessagingWindow();
        $this->withRatingSettings(['active' => false]);

        $result = null;
        $trace = $this->trace(function () use (&$result) {
            $result = ConversationRatingService::onConversationClosed($this->contact);
        });

        $this->assertNull($result);
        $this->assertDatabaseCount('conversation_ratings', 0);

        // لو تُجوهل المفتاح لمضى التدفّق إلى خطوة الإرسال وترك أثره هناك.
        $this->assertEmpty(
            array_filter($trace, fn ($m) => str_contains($m, self::REACHED_SENDING)),
            'المفتاح مطفأ ومع ذلك بلغ التدفّق خطوة الإرسال.'
        );
    }

    /** ومفعّلاً يمضي إلى الإرسال — يقف عند إعداد واتساب لا عند المفتاح. */
    public function test_turning_it_on_lets_the_flow_reach_the_sending_step(): void
    {
        $this->openMessagingWindow();
        $this->withRatingSettings(['active' => true]);

        $trace = $this->trace(fn () => ConversationRatingService::onConversationClosed($this->contact));

        $this->assertNotEmpty(
            array_filter($trace, fn ($m) => str_contains($m, self::REACHED_SENDING)),
            'المفتاح مفعّل ولم يبلغ التدفّق خطوة الإرسال.'
        );
    }

    /** والمفتاح مطفأ افتراضياً: تفعيل الميزة قرار الشركة لا قرارنا. */
    public function test_the_survey_is_off_until_a_company_turns_it_on(): void
    {
        $settings = ConversationRatingService::settings((int) $this->organization->id);

        $this->assertFalse($settings['active']);
    }

    public function test_turning_it_on_is_reflected_in_the_settings(): void
    {
        $this->assertTrue($this->withRatingSettings(['active' => true])['active']);
    }
}
