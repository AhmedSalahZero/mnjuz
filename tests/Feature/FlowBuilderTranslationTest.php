<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * واجهة منشئ المسارات مترجمة.
 *
 * كانت الوحدة كلها نصوصاً إنجليزية مكتوبة في القوالب مباشرة — 5 ملفات فقط
 * من 82 تستعمل الترجمة — فيرى المستخدم العربي شاشة إنجليزية بالكامل.
 */
class FlowBuilderTranslationTest extends TestCase
{
    /** @return list<string> ملفات الواجهة، بلا مكوّنات ui العامّة. */
    private function pages(): array
    {
        $files = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('modules/FlowBuilder/Pages'))
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'vue' || str_contains($file->getPathname(), '/ui/')) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /** لا نصّ إنجليزي ظاهر خارج دالة الترجمة. */
    public function test_no_visible_text_escapes_translation(): void
    {
        $leftovers = [];

        foreach ($this->pages() as $path) {
            $source = file_get_contents($path);

            if (!str_contains($source, '<template>')) {
                continue;
            }

            $template = explode('<template>', $source, 2)[1];

            preg_match_all('/>\s*([A-Z][^<>{}]{8,400}?)\s*</s', $template, $matches);

            foreach ($matches[1] as $text) {
                $text = trim(preg_replace('/\s+/', ' ', $text));

                if ($text === '' || str_contains($text, '$t(')) {
                    continue;
                }

                $leftovers[] = basename($path) . ': ' . mb_substr($text, 0, 60);
            }
        }

        $this->assertSame([], $leftovers, "نصوص ظاهرة بلا ترجمة:\n" . implode("\n", $leftovers));
    }

    /** ومفاتيح الترجمة موجودة فعلاً في ملف العربية. */
    public function test_every_used_key_has_an_arabic_entry(): void
    {
        $arabic = json_decode(file_get_contents(base_path('lang/ar.json')), true);
        $this->assertIsArray($arabic);

        $missing = [];

        foreach ($this->pages() as $path) {
            preg_match_all("/\\\$t\('((?:[^'\\\\]|\\\\.)+)'\)/", file_get_contents($path), $matches);

            foreach ($matches[1] as $key) {
                $key = str_replace("\\'", "'", $key);

                if (!array_key_exists($key, $arabic)) {
                    $missing[] = basename($path) . ': ' . $key;
                }
            }
        }

        $this->assertSame([], array_unique($missing), "مفاتيح بلا مدخل عربي:\n" . implode("\n", array_unique($missing)));
    }

    /** والترجمة العربية ليست نسخة من الإنجليزية. */
    public function test_the_new_keys_are_actually_translated(): void
    {
        $arabic = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        $samples = [
            'Starting Step',
            'Timeout (minutes)',
            'First-Time Message Only',
            'Please fill all the required fields',
            'Reply Buttons (atleast 1 button)',
            'Send Email',
        ];

        foreach ($samples as $key) {
            $this->assertArrayHasKey($key, $arabic, $key);
            $this->assertNotSame($key, $arabic[$key], "«{$key}» ما زالت إنجليزية في ar.json");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $arabic[$key], $key);
        }
    }
}
