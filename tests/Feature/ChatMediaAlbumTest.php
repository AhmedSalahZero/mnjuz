<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الوسائط المرسَلة دفعةً واحدة تُعرض شبكةً واحدة كما في واتساب.
 *
 * السحب والإفلات يُرسل كل صورة رسالةً مستقلّة — وواجهة واتساب السحابية لا
 * تعرف «الألبوم» أصلاً — فكانت عشر صور تُنتج عشر فقاعات متراصّة تبتلع
 * المحادثة. الضمّ عرضٌ لا تخزين: كل صورة تبقى رسالةً قائمةً بذاتها عند
 * العميل وفي قاعدة البيانات.
 *
 * المنطق في وحدة مستقلّة كي يُختبر بلا متصفّح؛ هذه الاختبارات تُشغّلها
 * وتحرس وصلها بالمحادثة.
 */
class ChatMediaAlbumTest extends TestCase
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

        $script = base_path('tests/js/media-albums.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    public function test_the_logic_stays_in_its_own_testable_module(): void
    {
        $this->assertFileExists(base_path('resources/js/Composables/mediaAlbums.js'));
        $this->assertFileExists(base_path('resources/js/Components/ChatComponents/ChatMediaAlbum.vue'));
    }

    /** الوصل: المحادثة تمرّ بالتجميع لا بالقائمة الخام. */
    public function test_the_thread_renders_grouped_items(): void
    {
        $thread = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatThread.vue'));

        $this->assertStringContainsString('groupMediaAlbums', $thread, 'المحادثة لا تستدعي التجميع');
        $this->assertStringContainsString('renderItems', $thread);
        $this->assertStringContainsString('<ChatMediaAlbum', $thread, 'مكوّن الألبوم غير مستعمل');
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
            '/groupMediaAlbums\(visibleMessages\.value\)/',
            $thread,
            'التجميع يجب أن يعمل على المرشَّح لا على الخام'
        );
    }

    /** الشبكة تفتح المعاينة المكبّرة كما تفعل الفقاعة المفردة. */
    public function test_the_album_opens_the_lightbox(): void
    {
        $album = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatMediaAlbum.vue'));

        $this->assertStringContainsString('ImageLightbox', $album);
        $this->assertStringContainsString('openLightbox', $album);
        $this->assertStringContainsString('markBroken', $album, 'الصورة المكسورة يجب أن تُستبدل لا أن تبقى فارغة');
    }

    /** منطق المعرض في وحدة مستقلّة تُختبر بلا متصفّح. */
    public function test_the_gallery_rules_hold(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/album-gallery.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    /**
     * الصور الزائدة عن أربع يجب أن تكون قابلة للفتح.
     *
     * الشبكة تعرض أربع بلاطات و«N+»، وكانت البلاطة الرابعة تفتح صورتها
     * وحدها — فمن أرسل عشر صور رآها العميل أربعاً ولا سبيل إلى الباقي.
     * شكا العملاء من ذلك، والمعرض يفتح المجموعة كاملة.
     */
    public function test_every_file_in_the_album_is_reachable(): void
    {
        $this->assertFileExists(base_path('resources/js/Composables/albumGallery.js'));

        $album = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatMediaAlbum.vue'));

        $this->assertStringContainsString('galleryItems', $album, 'الألبوم لا يبني قائمة المعرض');
        $this->assertMatchesRegularExpression(
            '/:items="gallery"/',
            $album,
            'المعاينة تستقبل صورةً واحدة لا المجموعة'
        );
        $this->assertStringNotContainsString(
            'openLightbox(tileSource(message))',
            $album,
            'ما زالت البلاطة تفتح صورتها وحدها فلا يُبلغ ما بعد الرابعة'
        );

        $lightbox = file_get_contents(base_path('resources/js/Components/ChatComponents/ImageLightbox.vue'));

        $this->assertStringContainsString('stepIndex', $lightbox, 'المعاينة لا تتنقّل بين الصور');
        $this->assertStringContainsString('hasMany', $lightbox, 'الأسهم يجب أن تظهر للمجموعة وحدها');
        $this->assertStringContainsString('ArrowRight', $lightbox, 'لوحة المفاتيح جزء من التنقّل');
    }

    /** المعاينة المفردة (فقاعة صورة واحدة) تبقى تعمل بـ src كما كانت. */
    public function test_the_single_image_bubble_still_works(): void
    {
        $lightbox = file_get_contents(base_path('resources/js/Components/ChatComponents/ImageLightbox.vue'));

        $this->assertMatchesRegularExpression(
            '/src:\s*\{ type: String/',
            $lightbox,
            'خاصية src يجب أن تبقى كي لا تنكسر الفقاعة المفردة'
        );

        $bubble = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatBubble.vue'));

        $this->assertStringContainsString(':src="lightboxSrc"', $bubble, 'الفقاعة المفردة ما زالت تمرّر src');
    }

    /** واتساب يضمّ الصور والفيديو معاً؛ المستند لا يدخل ألبوماً. */
    public function test_videos_join_the_album_and_documents_do_not(): void
    {
        $module = file_get_contents(base_path('resources/js/Composables/mediaAlbums.js'));

        $this->assertMatchesRegularExpression(
            "/MEDIA_ALBUM_TYPES = \['image', 'video'\]/",
            $module,
            'الألبوم يجب أن يضمّ الصور والفيديو وحدهما'
        );

        $album = file_get_contents(base_path('resources/js/Components/ChatComponents/ChatMediaAlbum.vue'));

        $this->assertStringContainsString('tileType', $album, 'البلاطة لا تفرّق بين صورة وفيديو');
        $this->assertStringContainsString('<video', $album, 'الفيديو يحتاج مشغّلاً لا وسم صورة');
    }

    /**
     * ترتيب الوصول هو ترتيب الاختيار.
     *
     * كانت كل وظيفة تُلقى وحدها في الطابور، فالملف البطيء (مستند كبير أو
     * فيديو يُعاد ترميزه) يهبط بين الصور فيقطع ضمّها — والسلسلة تُصلح ذلك
     * من جذره.
     */
    public function test_a_dropped_batch_is_sent_as_an_ordered_chain(): void
    {
        $service = file_get_contents(base_path('app/Services/ChatService.php'));

        $this->assertStringContainsString(
            'Bus::chain($chain)->onQueue(\'high\')->dispatch()',
            $service,
            'الدفعة يجب أن تُرسَل سلسلةً مرتَّبة'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/SendMediaJob::dispatch\([^;]*\)->onQueue\(\'high\'\);\s*\n\s*\$queued\+\+/s',
            $service,
            'ما زالت الدفعة تُلقى وظائف متوازية'
        );
    }

    /**
     * ملفٌ ترفضه واتساب لا يبتلع بقيّة الدفعة: سلسلة الوظائف تتوقّف عند أول
     * استثناء، فلولا هذا الحارس لضاعت الملفات التالية بلا أثر.
     */
    public function test_one_rejected_file_does_not_swallow_the_rest_of_the_batch(): void
    {
        $job = file_get_contents(base_path('app/Jobs/SendMediaJob.php'));

        $this->assertStringContainsString('continueBatchOnFailure', $job);
        $this->assertMatchesRegularExpression(
            '/if \(\$this->continueBatchOnFailure\) \{.*?Log::error.*?return;/s',
            $job,
            'الرفض داخل الدفعة يُسجَّل ولا يُرمى'
        );

        $service = file_get_contents(base_path('app/Services/ChatService.php'));

        $this->assertMatchesRegularExpression(
            '/new SendMediaJob\(.*?true\s*\);/s',
            $service,
            'وظائف الدفعة يجب أن تُنشأ بعلم أنها ضمن دفعة'
        );
    }

    public function test_the_album_labels_are_translated(): void
    {
        $translations = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        foreach (['{count} files', 'Sent By', 'Content not available'] as $key) {
            $this->assertArrayHasKey($key, $translations, "المفتاح «{$key}» بلا ترجمة");
            $this->assertNotSame($key, $translations[$key], "المفتاح «{$key}» غير مترجَم فعلياً");
        }
    }
}
