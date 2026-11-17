<?php

namespace Tests\Feature\WebChat;

use App\Models\Addon;
use App\Services\WebChat\WebChatSettings;

/**
 * رسالة بعد أسئلة البداية («سنحوّل محادثتك إلى أحد موظفي خدمة العملاء»).
 *
 * طلبها مالك Waz (2026-11-17): زائرٌ أدخل اسمه ولا أسئلة شائعة عند التاجر،
 * فلم يصله بعدها شيء. الرسالة نصٌّ يكتبه التاجر بالعربية والإنجليزية، وفارغة
 * افتراضياً فلا يتغيّر widget أحد. عرضها (فقاعة محلّية بعد الأسئلة حين لا
 * قائمة) في المتصفّح: tests/js/webchat-widget-render.mjs.
 *
 * التطبيق يحفظها بالنموذج نفسه (WebChatSettingsForm): انظر
 * MobileApi/MobileWebChatSettingsTest.
 */
class HandoffMessageTest extends WebChatTestCase
{
    public function test_it_is_empty_by_default_so_no_widget_changes(): void
    {
        $settings = WebChatSettings::for($this->widget);
        $this->assertNull($settings['prechat']['handoff_message']);
        $this->assertNull($settings['prechat']['handoff_message_en']);

        $this->getJson($this->url('config'))->assertOk()
            ->assertJsonPath('widget.prechat.handoff_message', null)
            ->assertJsonPath('widget.prechat.handoff_message_en', null);
    }

    public function test_the_texts_reach_the_widget_trimmed_and_capped(): void
    {
        $this->setSettings(['prechat' => [
            'handoff_message' => '  سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.  ',
            'handoff_message_en' => str_repeat('a', 600),
        ]]);

        $this->getJson($this->url('config'))->assertOk()
            ->assertJsonPath('widget.prechat.handoff_message', 'سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.')
            ->assertJsonPath('widget.prechat.handoff_message_en', str_repeat('a', 500));

        $this->setSettings(['prechat' => ['handoff_message' => '   ', 'handoff_message_en' => '']]);
        $prechat = WebChatSettings::for($this->widget->fresh())['prechat'];
        $this->assertNull($prechat['handoff_message'], 'blank → nothing shown');
        $this->assertNull($prechat['handoff_message_en']);
    }

    public function test_the_settings_page_saves_both_texts(): void
    {
        $this->actAsOwnerWithAddon();

        $this->post('/settings/webchat', $this->settingsForm([
            'handoff_message' => 'سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.',
            'handoff_message_en' => 'We are transferring you to a customer service agent.',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $prechat = WebChatSettings::for($this->widget->fresh())['prechat'];
        $this->assertSame('سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.', $prechat['handoff_message']);
        $this->assertSame('We are transferring you to a customer service agent.', $prechat['handoff_message_en']);

        // تفريغها من الصفحة يعيد السلوك القديم.
        $this->post('/settings/webchat', $this->settingsForm(['handoff_message' => '', 'handoff_message_en' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull(WebChatSettings::for($this->widget->fresh())['prechat']['handoff_message']);
    }

    /** صفحة إعدادات فُتحت قبل الحقلين تحفظ بدونهما: لا تمحو ما كتبه التاجر. */
    public function test_a_page_without_the_fields_keeps_them(): void
    {
        $this->actAsOwnerWithAddon();
        $this->post('/settings/webchat', $this->settingsForm(['handoff_message' => 'سنحوّلك', 'handoff_message_en' => 'Transferring you']))
            ->assertSessionHasNoErrors();

        $this->post('/settings/webchat', $this->settingsForm())->assertSessionHasNoErrors();

        $prechat = WebChatSettings::for($this->widget->fresh())['prechat'];
        $this->assertSame('سنحوّلك', $prechat['handoff_message']);
        $this->assertSame('Transferring you', $prechat['handoff_message_en']);
    }

    public function test_a_text_over_500_characters_is_refused(): void
    {
        $this->actAsOwnerWithAddon();

        $this->post('/settings/webchat', $this->settingsForm(['handoff_message' => str_repeat('ب', 501)]))
            ->assertSessionHasErrors('prechat.handoff_message');
        $this->post('/settings/webchat', $this->settingsForm(['handoff_message_en' => str_repeat('b', 501)]))
            ->assertSessionHasErrors('prechat.handoff_message_en');

        $this->assertNull(WebChatSettings::for($this->widget->fresh())['prechat']['handoff_message']);
    }

    // ------------------------------------------------- أدوات

    private function actAsOwnerWithAddon(): void
    {
        Addon::factory()->create(['name' => 'Google Authenticator']);
        $this->actAsOwner();
    }

    /** نموذج الصفحة كما يرسله WebChat.vue، بلا حقلَي الرسالة إلا ما يُمرَّر. */
    private function settingsForm(array $prechat = []): array
    {
        return [
            'enabled' => true,
            'allowed_domains' => [],
            'appearance' => [
                'color' => '#16a34a', 'position' => 'right', 'offset_x' => 20, 'offset_y' => 20,
                'title' => '', 'subtitle' => '', 'welcome_message' => '', 'language' => 'ar',
            ],
            'prechat' => $prechat + [
                'default_country' => 'SA', 'phone' => 'required', 'email' => 'off',
                'name_label' => '', 'phone_label' => '', 'consent_text' => '',
            ],
            'behavior' => ['show_agent_names' => true, 'sound' => true, 'uploads' => true],
        ];
    }
}
