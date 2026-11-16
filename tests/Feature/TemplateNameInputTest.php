<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * حقل اسم القالب في صفحة الإنشاء: Meta لا تقبل إلا a-z و0-9 و«_»، والحقل
 * يحذف ما سواها وهو يُكتب. كان الحذف صامتاً فبدا الحقل معطّلاً لمن يكتب
 * بالعربية، والآن تشرح الصفحة القاعدة تحته.
 *
 * منطق التنظيف مُختبر بـ node (tests/js/template-name.mjs، يشغّله
 * JavascriptSuiteTest)، وهنا ربطه بالصفحة وترجمة الرسالة.
 */
class TemplateNameInputTest extends TestCase
{
    private const RULE = 'The template name may only contain lowercase letters, numbers and underscores.';

    public function test_the_create_page_cleans_the_name_and_explains_why(): void
    {
        $source = file_get_contents(resource_path('js/Pages/User/Templates/Add.vue'));

        $this->assertStringContainsString("import { cleanTemplateName } from '@/Composables/templateName.js'", $source);
        $this->assertStringContainsString('cleanTemplateName(event.target.value)', $source);
        $this->assertStringContainsString(':error="nameRejected ? $t(\'' . self::RULE . '\') : \'\'"', $source);
    }

    public function test_the_rule_is_translated_to_arabic(): void
    {
        $arabic = json_decode(file_get_contents(lang_path('ar.json')), true);

        $this->assertNotEmpty($arabic[self::RULE] ?? null);
    }
}
