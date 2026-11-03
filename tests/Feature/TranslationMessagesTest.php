<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * كل ترجمة صيغةٌ صالحة لـ vue-i18n: الفحص في tests/js/lang-messages.mjs.
 * ترجمة فيها «@» حرفية كانت ترمي عند العرض فلا تفتح نافذة تعديل «Update contact».
 */
class TranslationMessagesTest extends TestCase
{
    public function test_every_translation_compiles_in_vue_i18n(): void
    {
        exec('node --version 2>/dev/null', $output, $status);
        if ($status !== 0 || !is_dir(base_path('node_modules/@intlify/message-compiler'))) {
            $this->markTestSkipped('node أو @intlify/message-compiler غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/lang-messages.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $result, $status);

        $this->assertSame(0, $status, implode("\n", $result));
        $this->assertStringContainsString('OK', implode("\n", $result));
    }

    /** المثال يمرّر البريد متغيّراً، والنصّ القديم بـ«@» حرفية لم يعد في أي لغة. */
    public function test_the_update_contact_example_passes_the_address_as_a_parameter(): void
    {
        $node = file_get_contents(base_path('modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/UpdateContact-node.vue'));
        $key = 'Example: if the user types {email} and you select Email, their email is updated to {email}';

        $this->assertStringContainsString("\$t('{$key}', { email: 'john@example.com' })", $node);

        foreach (['ar', 'default_en', 'en', 'es', 'fr', 'sw', 'tr'] as $language) {
            $translations = json_decode(file_get_contents(base_path("lang/{$language}.json")), true);

            $this->assertArrayHasKey($key, $translations, "{$language}: المثال غير مترجم");
            $this->assertSame(2, substr_count($translations[$key], '{email}'), "{$language}: الترجمة أسقطت {email}");
            $this->assertStringNotContainsString('@', $translations[$key], "{$language}: «@» حرفية تكسر vue-i18n");
        }
    }
}
