<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * الأنواع التي لا تُرسَم في المحادثة إطلاقاً.
 *
 * إخفاء محتوى التفاعل وحده لم يكن كافياً: غلاف الفقاعة يُرسَم في ChatThread لا
 * في ChatBubble، فبقيت فقاعة فارغة لكل تفاعل — وهي أظهر إزعاجاً من التفاعل.
 * الترشيح صار في القائمة نفسها.
 *
 * المشروع بلا إطار اختبار JS، فهذه حراسة بنيوية تقرأ ملفَي المكوّنين.
 */
class ChatThreadHiddenTypesTest extends TestCase
{
    private string $thread;
    private string $bubble;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2) . '/resources/js/Components/ChatComponents/';
        $this->thread = file_get_contents($root . 'ChatThread.vue');
        $this->bubble = file_get_contents($root . 'ChatBubble.vue');
    }

    /** @return list<string> */
    private function hiddenTypes(): array
    {
        $this->assertTrue(
            (bool) preg_match('/const HIDDEN_CHAT_TYPES = \[(.*?)\]/s', $this->thread, $m),
            'HIDDEN_CHAT_TYPES يجب أن تبقى موجودة'
        );
        preg_match_all("/'([a-z_]+)'/", $m[1], $types);

        return $types[1];
    }

    /** @return list<string> */
    private function silentTypes(): array
    {
        preg_match('/const SILENT_TYPES = \[(.*?)\]/s', $this->bubble, $m);
        preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $types);

        return $types[1];
    }

    public function test_reactions_are_hidden_from_the_conversation(): void
    {
        $this->assertContains('reaction', $this->hiddenTypes());
    }

    /**
     * أخطر انحدار هنا: بقاء الحلقة على messages يجعل الترشيح كوداً ميتاً
     * وتعود الفقاعات الفارغة بلا أي خطأ يشي بذلك.
     *
     * صارت الحلقة تقرأ renderItems بعد ضمّ الصور المرسَلة دفعةً في ألبوم
     * واحد، فالحراسة الآن على السلسلة كاملة: العرض ← التجميع ← المرشَّح.
     * وصلٌ ناقص في أيّ حلقة منها يُعيد العلّة نفسها.
     */
    public function test_the_list_renders_the_filtered_collection(): void
    {
        $this->assertMatchesRegularExpression(
            '/v-for="item in renderItems"/',
            $this->thread,
            'الحلقة يجب أن تقرأ من renderItems'
        );

        $this->assertMatchesRegularExpression(
            '/renderItems = computed\(\(\) => groupImageAlbums\(visibleMessages\.value\)\)/',
            $this->thread,
            'renderItems يجب أن تُشتقّ من visibleMessages لا من messages'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/v-for="[^"]*" in messages"/',
            $this->thread
        );
    }

    /** الترشيح مشتقّ من messages كي يشمل الوارد لحظياً كما يشمل المحمّل. */
    public function test_the_filtered_collection_derives_from_the_live_list(): void
    {
        $this->assertMatchesRegularExpression(
            '/const visibleMessages = computed\(\(\) =>\s*\n\s*messages\.value\.filter/',
            $this->thread,
            'visibleMessages يجب أن تُشتقّ من messages لا من نسخة ثابتة'
        );
    }

    /** التذاكر والملاحظات ليست رسائل، فلا يمسّها ترشيح أنواع الرسائل. */
    public function test_only_chat_entries_are_filtered(): void
    {
        $this->assertMatchesRegularExpression(
            "/if \(entry\?\.type !== 'chat'\) return true/",
            $this->thread,
            'ما ليس رسالة يمرّ دائماً'
        );
    }

    /** صفّ تالف يجب أن يبقى معروضاً لا أن يُسقط الصفحة أو يختفي صامتاً. */
    public function test_malformed_metadata_does_not_hide_a_message(): void
    {
        $this->assertMatchesRegularExpression(
            '/const chatMetadataType = \(entry\) => \{.*?try \{.*?\} catch \{\s*return null/s',
            $this->thread,
            'قراءة النوع يجب أن تبقى داخل try وتُرجع null عند الفشل'
        );
    }

    /**
     * لا يُخفى نوع له فرع عرض.
     *
     * القائمتان تصفان قراراً واحداً من وجهين: ما يُخفى من الشريط الزمني يجب
     * أن يكون صامتاً في الفقاعة أيضاً. اختلافهما يعني أن أحد الملفين عُدّل
     * وحده — وهو ما يُنتج فقاعةً فارغة أو محتوىً لا يظهر.
     */
    public function test_hidden_types_are_also_silent_in_the_bubble(): void
    {
        $silent = $this->silentTypes();

        foreach ($this->hiddenTypes() as $type) {
            $this->assertContains(
                $type,
                $silent,
                "«{$type}» مخفيّ من المحادثة ويجب أن يكون في SILENT_TYPES داخل ChatBubble"
            );
        }
    }

    /** ولا يُدرَج في المخفيّ نوعٌ له فرع عرض فعلي. */
    public function test_no_rendered_type_is_hidden(): void
    {
        preg_match('/const RENDERED_TYPES = \[(.*?)\]/s', $this->bubble, $m);
        preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $rendered);

        foreach ($this->hiddenTypes() as $type) {
            $this->assertNotContains(
                $type,
                $rendered[1],
                "«{$type}» له فرع عرض ولا يصحّ إخفاؤه"
            );
        }
    }
}
