<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الصور المرسَلة دفعةً واحدة تُعرض شبكةً واحدة كما في واتساب.
 *
 * السحب والإفلات يُرسل كل صورة رسالةً مستقلّة — وواجهة واتساب السحابية لا
 * تعرف «الألبوم» أصلاً — فكانت عشر صور تُنتج عشر فقاعات متراصّة تبتلع
 * المحادثة. الضمّ عرضٌ لا تخزين: كل صورة تبقى رسالةً قائمةً بذاتها عند
 * العميل وفي قاعدة البيانات.
 *
 * المنطق في وحدة مستقلّة كي يُختبر بلا متصفّح؛ هذه الاختبارات تُشغّلها
 * وتحرس وصلها بالمحادثة.
 */
class ChatImageAlbumTest extends TestCase
{
    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    public function test_the_grouping_rules_hold(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/image-albums.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    public function test_the_logic_stays_in_its_own_testable_module(): void
    {
        $this->assertFileExists(base_path('resources/js/Composables/imageAlbums.js'));
        $this->assertFileExists(base_path('resources/js/Components/ChatComponents/ChatImageAlbum.vue'));
    }

    /** الوصل: المحادثة تمرّ بالتجميع لا بالقائمة الخام. */
    public function test_the_thread_renders_grouped_items(): void
    {
        $thread = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatThread.vue'));

        $this->assertStringContainsString('groupImageAlbums', $thread, 'المحادثة لا تستدعي التجميع');
        $this->assertStringContainsString('renderItems', $thread);
        $this->assertStringContainsString('<ChatImageAlbum', $thread, 'مكوّن الألبوم غير مستعمل');
        $this->assertStringNotContainsString(
            'v-for="(chat, index) in visibleMessages"',
            $thread,
            'ما زالت المحادثة ترسم القائمة الخام فلا ضمّ'
        );
    }

    /**
     * الترشيح يسبق الضمّ: لو ضُمّت الصور قبل استبعاد التفاعلات لظهرت فقاعة
     * فارغة داخل الشبكة.
     */
    public function test_hidden_types_are_filtered_before_grouping(): void
    {
        $thread = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatThread.vue'));

        $this->assertMatchesRegularExpression(
            '/groupImageAlbums\(visibleMessages\.value\)/',
            $thread,
            'التجميع يجب أن يعمل على المرشَّح لا على الخام'
        );
    }

    /** الشبكة تفتح المعاينة المكبّرة كما تفعل الفقاعة المفردة. */
    public function test_the_album_opens_the_lightbox(): void
    {
        $album = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatImageAlbum.vue'));

        $this->assertStringContainsString('ImageLightbox', $album);
        $this->assertStringContainsString('openLightbox', $album);
        $this->assertStringContainsString('markBroken', $album, 'الصورة المكسورة يجب أن تُستبدل لا أن تبقى فارغة');
    }

    public function test_the_album_labels_are_translated(): void
    {
        $translations = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        foreach (['{count} photos', 'Sent By', 'Content not available'] as $key) {
            $this->assertArrayHasKey($key, $translations, "المفتاح «{$key}» بلا ترجمة");
            $this->assertNotSame($key, $translations[$key], "المفتاح «{$key}» غير مترجَم فعلياً");
        }
    }
}
