<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نافذة إعداد مساعد الذكاء الاصطناعي (modules/IntelliReply/Pages/User/Index.vue).
 *
 * سلوكها في المتصفّح مُختبَر يدوياً؛ هنا ما يحرسه نصّ الصفحة نفسه:
 * قائمة الصوت كانت تُقصّ، وخطأ الحفظ كان سيضيع، ومفتاح «نموذج المحادثة»
 * كان يُظنّ ردّاً آلياً.
 */
class AiSettingsPageTest extends TestCase
{
    private const CHAT_FORM_HINT = 'This adds an AI suggestion button for agents in the chat. It does not reply to customers by itself. To let the assistant reply automatically, turn on Enable automatic AI assistance for new conversations under AI Assistant Setup.';

    private function source(): string
    {
        return file_get_contents(base_path('modules/IntelliReply/Pages/User/Index.vue'));
    }

    /** لوحة Modal عليها overflow-hidden ولا مكان تحت الحقل إلا لخيارين. */
    public function test_the_voice_list_opens_upward_inside_the_modal(): void
    {
        $this->assertMatchesRegularExpression('/<FormSelect v-model="form2\.voice"[^>]*:dropdownUp="true"/', $this->source());
    }

    /** الحفظ يجرّب المفتاح مع OpenAI؛ رفضه يبقي النافذة مفتوحة وخطؤها ظاهر. */
    public function test_a_rejected_save_keeps_the_modal_and_its_errors(): void
    {
        $this->assertMatchesRegularExpression(
            "/form2\\.post\\('\\/automation\\/ai\\/setup', \\{.*?preserveState: 'errors'/s",
            $this->source()
        );
    }

    public function test_the_chat_form_switch_says_it_is_not_an_auto_reply(): void
    {
        $this->assertStringContainsString("\$t('" . self::CHAT_FORM_HINT . "')", $this->source());

        $arabic = json_decode(file_get_contents(lang_path('ar.json')), true);
        $this->assertNotEmpty($arabic[self::CHAT_FORM_HINT] ?? null);
    }
}
