<?php

namespace Tests\Feature\MobileApi;

use App\Models\ChannelAccount;
use App\Models\WebChatFaq;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatFaqs;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatWidgetService;
use App\Support\OrganizationRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * إعدادات المحادثة المباشرة والأسئلة الشائعة من التطبيق. القواعد والحفظ هما
 * ما يستعمله الويب (InboxAndSettingsTest و FaqTest)؛ هنا الصلاحية والباقة
 * وشكل الردّ.
 */
class MobileWebChatSettingsTest extends MobileApiTestCase
{
    private bool $webChatInPlan = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function planMetadata(): array
    {
        $metadata = parent::planMetadata();
        $metadata['addons']['Web Chat'] = $this->webChatInPlan;

        return $metadata;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'allowed_domains' => ['shop.example.com'],
            'appearance' => ['color' => '#123ABC', 'position' => 'left', 'offset_x' => 10, 'offset_y' => 30, 'title' => 'تحدّث معنا', 'subtitle' => '', 'welcome_message' => 'أهلاً بك', 'language' => 'ar'],
            'prechat' => ['default_country' => 'ae', 'phone' => 'optional', 'email' => 'optional', 'name_label' => '', 'phone_label' => 'جوالك', 'consent_text' => ''],
            'behavior' => ['show_agent_names' => false, 'sound' => true, 'uploads' => false],
        ], $overrides);
    }

    private function widget(): ?ChannelAccount
    {
        return app(WebChatWidgetService::class)->forOrganization($this->organization->id);
    }

    public function test_opening_the_settings_creates_the_widget_and_returns_the_options(): void
    {
        $this->assertNull($this->widget());

        $data = $this->getJson('/api/v1/settings/webchat')->assertOk()->json('data');

        $this->assertNotNull($this->widget());
        $this->assertSame((string) $this->widget()->external_id, $data['key']);
        $this->assertStringContainsString('data-mnjz-key="' . $data['key'] . '"', $data['snippet']);
        $this->assertArrayNotHasKey('preview_url', $data, 'المعاينة صفحة ويب بجلسة');
        $this->assertSame(WebChatSettings::PHONE_MODES, $data['options']['phone_modes']);
        $this->assertSame(WebChatFaqs::MAX, $data['options']['faqs_max']);
        $this->assertArrayHasKey('questions', $data);
    }

    public function test_saving_updates_the_widget_and_keeps_what_the_form_did_not_send(): void
    {
        $this->getJson('/api/v1/settings/webchat')->assertOk();
        $account = $this->widget();
        $settings = WebChatSettings::for($account);
        $settings['appearance']['title_en'] = 'Chat with us';
        $settings['appearance']['logo_path'] = 'public/webchat-logos/' . $this->organization->id . '/abc.png';
        $account->forceFill(['settings' => WebChatSettings::merge($settings)])->save();

        $this->postJson('/api/v1/settings/webchat', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.settings.appearance.color', '#123abc');

        $saved = WebChatSettings::for($this->widget()->fresh());
        $this->assertSame('left', $saved['appearance']['position']);
        $this->assertSame('Chat with us', $saved['appearance']['title_en'], 'الإنجليزية الغائبة تبقى');
        $this->assertSame('public/webchat-logos/' . $this->organization->id . '/abc.png', $saved['appearance']['logo_path'], 'الشعار لا يُمحى');
        // الأسئلة الغائبة عن الطلب تبقى، ومنها يُشتقّ prechat.phone كما في الويب.
        $this->assertSame(['name', 'email', 'phone'], array_column($saved['prechat']['questions'], 'id'));
        $this->assertSame('required', $saved['prechat']['phone']);
    }

    /**
     * رسالة بعد أسئلة البداية: التطبيق يقرؤها ويحفظها، وإصدارٌ أقدم لا يرسلها
     * لا يمحوها (HandoffMessageTest للويب).
     */
    public function test_the_handoff_message_is_read_saved_and_kept_by_older_builds(): void
    {
        $this->getJson('/api/v1/settings/webchat')->assertOk()
            ->assertJsonPath('data.settings.prechat.handoff_message', null)
            ->assertJsonPath('data.settings.prechat.handoff_message_en', null);

        $this->postJson('/api/v1/settings/webchat', $this->payload(['prechat' => [
            'handoff_message' => 'سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.',
            'handoff_message_en' => 'We are transferring you to an agent.',
        ]]))->assertOk()
            ->assertJsonPath('data.settings.prechat.handoff_message', 'سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.')
            ->assertJsonPath('data.settings.prechat.handoff_message_en', 'We are transferring you to an agent.');

        // إصدارٌ أقدم من الحقلين يحفظ بدونهما.
        $this->postJson('/api/v1/settings/webchat', $this->payload())->assertOk();

        $saved = WebChatSettings::for($this->widget()->fresh());
        $this->assertSame('سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.', $saved['prechat']['handoff_message']);
        $this->assertSame('We are transferring you to an agent.', $saved['prechat']['handoff_message_en']);
    }

    public function test_the_questions_decide_whether_the_phone_is_asked(): void
    {
        $this->postJson('/api/v1/settings/webchat', $this->payload(['prechat' => ['questions' => [
            ['target' => 'name', 'required' => true],
            ['target' => 'phone', 'required' => false],
        ]]]))->assertOk();

        $saved = WebChatSettings::for($this->widget()->fresh());
        $this->assertSame('optional', $saved['prechat']['phone']);
        $this->assertSame('off', $saved['prechat']['email']);
    }

    public function test_invalid_settings_are_field_errors(): void
    {
        $this->postJson('/api/v1/settings/webchat', $this->payload(['appearance' => ['color' => 'red'], 'prechat' => ['phone' => 'maybe']]))
            ->assertStatus(400)
            ->assertJsonValidationErrors(['appearance.color', 'prechat.phone'], 'errors');
    }

    public function test_the_same_question_twice_is_refused(): void
    {
        $this->postJson('/api/v1/settings/webchat', $this->payload(['prechat' => ['questions' => [
            ['target' => 'name', 'required' => true],
            ['target' => 'name', 'required' => false],
        ]]]))->assertStatus(400)->assertJsonValidationErrors('prechat.questions.1.target', 'errors');
    }

    public function test_the_logo_is_uploaded_and_removed(): void
    {
        $this->postJson('/api/v1/settings/webchat/logo', ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])
            ->assertOk();

        $path = WebChatSettings::for($this->widget()->fresh())['appearance']['logo_path'];
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);

        $this->postJson('/api/v1/settings/webchat/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')])
            ->assertStatus(400);

        $this->deleteJson('/api/v1/settings/webchat/logo')->assertOk();
        $this->assertNull(WebChatSettings::for($this->widget()->fresh())['appearance']['logo_path']);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_rotating_the_key_retires_the_old_one(): void
    {
        $old = $this->getJson('/api/v1/settings/webchat')->json('data.key');

        $new = $this->postJson('/api/v1/settings/webchat/rotate-key')->assertOk()->json('data.key');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, (string) $this->widget()->fresh()->external_id);
    }

    public function test_faqs_are_created_updated_reordered_and_deleted(): void
    {
        $first = $this->postJson('/api/v1/settings/webchat/faqs', ['question_ar' => 'كم مدة التوصيل؟', 'answer_ar' => 'يومان'])
            ->assertOk()
            ->assertJsonPath('data.faq.question_ar', 'كم مدة التوصيل؟')
            ->json('data.faq.id');
        $second = $this->post('/api/v1/settings/webchat/faqs', [
            'question_ar' => 'أين فرعكم؟',
            'image' => UploadedFile::fake()->image('map.png'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.faq.id');

        $this->postJson('/api/v1/settings/webchat/faqs/' . $first, ['question_ar' => 'مدة التوصيل؟', 'answer_ar' => 'يومان', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.faq.is_active', false);

        $order = $this->postJson('/api/v1/settings/webchat/faqs/reorder', ['ids' => [$second, $first]])->assertOk()->json('data.faqs');
        $this->assertSame([$second, $first], array_column($order, 'id'));

        $this->deleteJson('/api/v1/settings/webchat/faqs/' . $first)->assertOk();
        $this->assertNull(WebChatFaq::find($first));
        $this->deleteJson('/api/v1/settings/webchat/faqs/' . $first)->assertNotFound();
    }

    public function test_a_faq_needs_an_answer_or_an_image(): void
    {
        $this->postJson('/api/v1/settings/webchat/faqs', ['question_ar' => 'سؤال بلا إجابة'])
            ->assertStatus(400)
            ->assertJsonValidationErrors('answer_ar', 'errors');
    }

    public function test_the_faq_limit_holds(): void
    {
        for ($i = 0; $i < WebChatFaqs::MAX; $i++) {
            WebChatFaq::create(['organization_id' => $this->organization->id, 'position' => $i + 1, 'question_ar' => "سؤال {$i}", 'answer_ar' => 'إجابة', 'is_active' => true]);
        }

        $this->postJson('/api/v1/settings/webchat/faqs', ['question_ar' => 'واحد زائد', 'answer_ar' => 'إجابة'])
            ->assertStatus(400)
            ->assertJsonValidationErrors('question_ar', 'errors');
    }

    public function test_an_agent_is_refused(): void
    {
        $this->actAs($this->member(OrganizationRole::AGENT));

        $this->getJson('/api/v1/settings/webchat')->assertForbidden();
        $this->postJson('/api/v1/settings/webchat', $this->payload())->assertForbidden();
        $this->postJson('/api/v1/settings/webchat/faqs', ['question_ar' => 'س', 'answer_ar' => 'ج'])->assertForbidden();
        $this->assertNull($this->widget(), 'الموظّف لا يُنشئ widget');
    }

    public function test_without_the_add_on_the_settings_are_closed(): void
    {
        $this->webChatInPlan = false;
        $this->plan->update(['metadata' => json_encode($this->planMetadata())]);

        $this->getJson('/api/v1/settings/webchat')
            ->assertForbidden()
            ->assertJsonPath('message', __('This feature is not available in your plan.'));
        $this->assertSame(0, ChannelAccount::where('channel', Channel::WEBCHAT)->count());
    }
}
