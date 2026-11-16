<?php

namespace Tests\Feature\WebChat;

use App\Models\Addon;
use App\Services\WebChat\WebChatSettings;

/**
 * لغة الـ widget: العربية افتراضياً، والزائر يبدّل بين العربية والإنجليزية،
 * ونصوص التاجر لها نسخة إنجليزية اختيارية، ورسائل الخادم بلغة الزائر.
 *
 * تبديل الزائر نفسه (الزرّ، وحفظ اختياره) في المتصفّح: tests/js.
 */
class WidgetLanguageTest extends WebChatTestCase
{
    public function test_a_widget_opens_in_arabic_by_default(): void
    {
        $this->assertSame('ar', WebChatSettings::for($this->widget)['appearance']['language']);

        $this->getJson($this->url('config'))->assertOk()->assertJsonPath('widget.appearance.language', 'ar');
    }

    /** «زي لغة الموقع» والإنجليزية ما زالا اختيارين للتاجر. */
    public function test_the_merchant_can_still_choose_the_site_language_or_english(): void
    {
        foreach (['auto', 'en'] as $language) {
            $this->setSettings(['appearance' => ['language' => $language]]);
            $this->assertSame($language, WebChatSettings::for($this->widget->fresh())['appearance']['language']);
        }

        $this->setSettings(['appearance' => ['language' => 'fr']]);
        $this->assertSame('ar', WebChatSettings::for($this->widget->fresh())['appearance']['language'], 'unknown → Arabic');
    }

    public function test_english_versions_of_the_merchant_texts_reach_the_widget(): void
    {
        $this->setSettings([
            'appearance' => ['title' => 'تحدّث معنا', 'title_en' => '  Chat with us ', 'welcome_message_en' => 'Hello!'],
            'prechat' => ['consent_text' => 'أوافق على الشروط', 'consent_text_en' => 'I accept the terms'],
        ]);

        $this->getJson($this->url('config'))->assertOk()
            ->assertJsonPath('widget.appearance.title', 'تحدّث معنا')
            ->assertJsonPath('widget.appearance.title_en', 'Chat with us')
            ->assertJsonPath('widget.appearance.subtitle_en', '')
            ->assertJsonPath('widget.appearance.welcome_message_en', 'Hello!')
            ->assertJsonPath('widget.prechat.consent_text_en', 'I accept the terms');
    }

    public function test_the_settings_page_saves_the_english_texts(): void
    {
        $this->actAsOwnerWithAddon();

        $this->post('/settings/webchat', $this->settingsForm([
            'title_en' => 'Chat with us',
            'subtitle_en' => 'We reply fast',
            'welcome_message_en' => 'Welcome',
        ], ['consent_text' => 'أوافق', 'consent_text_en' => 'I agree']))->assertRedirect()->assertSessionHasNoErrors();

        $settings = WebChatSettings::for($this->widget->fresh());
        $this->assertSame('Chat with us', $settings['appearance']['title_en']);
        $this->assertSame('We reply fast', $settings['appearance']['subtitle_en']);
        $this->assertSame('Welcome', $settings['appearance']['welcome_message_en']);
        $this->assertSame('I agree', $settings['prechat']['consent_text_en']);
    }

    /** صفحة إعدادات فُتحت قبل الحقول الإنجليزية تحفظ بدونها: لا تمحوها. */
    public function test_a_page_without_the_english_fields_keeps_them(): void
    {
        $this->actAsOwnerWithAddon();
        $this->post('/settings/webchat', $this->settingsForm(['title_en' => 'Chat with us'], ['consent_text' => 'أوافق', 'consent_text_en' => 'I agree']))
            ->assertSessionHasNoErrors();

        $this->post('/settings/webchat', $this->settingsForm([], ['consent_text' => 'أوافق']))->assertSessionHasNoErrors();

        $settings = WebChatSettings::for($this->widget->fresh());
        $this->assertSame('Chat with us', $settings['appearance']['title_en']);
        $this->assertSame('I agree', $settings['prechat']['consent_text_en']);
    }

    /**
     * المتصفّح يرسل Accept-Language دائماً، فكان ?lang= لا يُقرأ وخطأ الرقم
     * يعود بالإنجليزية لزائرٍ يقرأ بالعربية.
     */
    public function test_errors_come_back_in_the_widget_language(): void
    {
        $body = ['name' => 'سارة', 'phone' => '0112345678', 'elapsed_ms' => 4000];
        $browser = ['Accept-Language' => 'en-US,en;q=0.9'];

        $arabic = $this->postJson($this->url('sessions') . '?lang=ar', $body, $browser)->assertStatus(422);
        $this->assertSame('يبدو أنه رقم هاتف أرضي. أدخل رقم جوال.', $arabic->json('errors.phone.0'));

        $english = $this->postJson($this->url('sessions') . '?lang=en', $body, ['Accept-Language' => 'ar'])->assertStatus(422);
        $this->assertSame('That looks like a landline number. Enter a mobile number.', $english->json('errors.phone.0'));
    }

    /** أرقام لوحة المفاتيح العربية تُقبل في سؤال الجوال. */
    public function test_a_mobile_typed_with_arabic_digits_is_accepted(): void
    {
        [, $visitor] = $this->openSession(['phone' => '٠٥٠١٢٣٤٥٦٧']);

        $this->assertSame('+966501234567', $visitor->phone);
    }

    public function test_the_visitor_language_is_kept_on_the_visitor(): void
    {
        [, $visitor] = $this->openSession(['locale' => 'en']);

        $this->assertSame('en', $visitor->locale);
    }

    // ------------------------------------------------- أدوات

    private function actAsOwnerWithAddon(): void
    {
        Addon::factory()->create(['name' => 'Google Authenticator']);
        $this->actAsOwner();
    }

    private function settingsForm(array $appearance = [], array $prechat = []): array
    {
        return [
            'enabled' => true,
            'allowed_domains' => [],
            'appearance' => $appearance + [
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
