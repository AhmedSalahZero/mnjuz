<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * سلسلة العرض في ChatBubble.vue — حراسة بنيوية لا تجميلية.
 *
 * المكوّن لا يملك سلسلة واحدة: text و unsupported و button تبدأ كلٌّ بـv-if
 * مستقلّ، والسلسلة الأخيرة تبدأ من interactive. لذلك v-else مجرّد في آخرها كان
 * سيصدُق على النصّ أيضاً فيرسمه مرّتين — وهذا هو الفخّ الذي تحرسه هذه
 * الاختبارات، لأن المشروع بلا إطار اختبار JS.
 *
 * الاحتياطي يعتمد على قائمة RENDERED_TYPES بدلاً من v-else، فسلامة الصفحة
 * مشروطة بأن تبقى القائمة مطابقةً للفروع الموجودة فعلاً.
 */
class ChatBubbleRenderChainTest extends TestCase
{
    private string $source;

    /** @var list<array{kind: string, type: ?string, fallback: bool}> */
    private array $branches;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 2) . '/resources/js/Components/ChatComponents/ChatBubble.vue';
        $this->assertFileExists($path);
        $this->source = file_get_contents($path);

        // الفروع المعلّقة داخل <!-- --> ليست فروعاً: المكوّن يحوي نسخاً قديمة
        // معطّلة (منها فرع template)، وعدّها فروعاً حيّة يُنتج إنذاراً كاذباً.
        $this->branches = $this->parseBranches(
            preg_replace('/<!--.*?-->/s', '', $this->source)
        );
    }

    /**
     * فروع سلسلة الرسائل فقط: الأسطر التي تفرّع على نوع الرسالة أو على
     * الاحتياطي. نتجاهل ما يفرّع على شيء آخر (الطابع الزمني، أزرار القالب).
     *
     * النمط يقبل الصياغتين — JSON.parse(content.metadata).type والحساب
     * المشترك meta.type — لأن ما يُحرَس هو وجود فرعٍ لكل نوع، لا الطريقة
     * التي تُقرأ بها الميتاداتا. وقد تغيّرت هذه الطريقة حين صار التحليل
     * مرّةً واحدة للفقاعة بدل خمس وأربعين.
     *
     * @return list<array{kind: string, type: ?string, fallback: bool}>
     */
    private function parseBranches(string $source): array
    {
        $branches = [];

        foreach (explode("\n", $source) as $line) {
            if (!preg_match('/<div v-(if|else-if|else)[=">]/', $line, $kind)) {
                continue;
            }

            $isTypeBranch = (bool) preg_match("/(?:metadata\)|\bmeta)\.type === '([a-z_]+)'/", $line, $type);
            $isFallback = str_contains($line, 'isUnrenderableType');

            if (!$isTypeBranch && !$isFallback) {
                continue;
            }

            $branches[] = [
                'kind' => $kind[1],
                'type' => $isTypeBranch ? $type[1] : null,
                'fallback' => $isFallback,
            ];
        }

        return $branches;
    }

    /** @return list<string> */
    private function declaredRenderedTypes(): array
    {
        $this->assertTrue(
            (bool) preg_match('/const RENDERED_TYPES = \[(.*?)\]/s', $this->source, $m),
            'RENDERED_TYPES يجب أن تبقى موجودة — الاحتياطي يعتمد عليها'
        );

        preg_match_all("/'([a-z_]+)'/", $m[1], $types);

        return $types[1];
    }

    // ------------------------------------------------------- الفخّ الأصلي

    /**
     * أخطر انحدار ممكن هنا: نوعٌ يبدأ سلسلةً مستقلّة بـv-if وليس في
     * RENDERED_TYPES. الاحتياطي في آخر السلسلة الأخيرة سيصدُق عليه أيضاً،
     * فتُرسم رسالته مرّتين: مرّة بمحتواها ومرّة بعبارة «لا يمكن عرضها».
     */
    public function test_every_standalone_chain_head_is_listed_as_rendered(): void
    {
        $declared = $this->declaredRenderedTypes();

        $heads = array_values(array_filter(
            $this->branches,
            static fn ($branch) => $branch['kind'] === 'if' && $branch['type'] !== null
        ));

        $this->assertNotEmpty($heads, 'يجب أن تبقى سلسلة واحدة على الأقل تبدأ بـv-if');

        foreach ($heads as $head) {
            $this->assertContains(
                $head['type'],
                $declared,
                "النوع «{$head['type']}» يبدأ سلسلة مستقلّة وليس في RENDERED_TYPES — سيُرسم مرّتين"
            );
        }
    }

    /**
     * وكذلك العكس: فرعٌ داخل السلسلة غير مُدرَج في القائمة يعني أن الاحتياطي
     * لن يصدُق عليه (لأن فرعه سبقه) لكن القائمة تكذب على القارئ التالي.
     */
    public function test_every_branch_type_is_listed_as_rendered(): void
    {
        $declared = $this->declaredRenderedTypes();

        foreach ($this->branches as $branch) {
            if ($branch['type'] === null) {
                continue;
            }

            $this->assertContains(
                $branch['type'],
                $declared,
                "النوع «{$branch['type']}» له فرع عرض وليس في RENDERED_TYPES"
            );
        }
    }

    /** ولا نوع في القائمة بلا فرع: القائمة تصف الواقع لا تتمنّاه. */
    public function test_every_listed_type_actually_has_a_branch(): void
    {
        $withBranches = array_values(array_unique(array_filter(
            array_column($this->branches, 'type')
        )));

        foreach ($this->declaredRenderedTypes() as $type) {
            $this->assertContains(
                $type,
                $withBranches,
                "النوع «{$type}» مُدرَج في RENDERED_TYPES بلا فرع عرض — سيظهر فقاعةً فارغة"
            );
        }
    }

    // ------------------------------------------------- ترتيب الاحتياطي

    /**
     * التفاعل بالإيموجي متروك بلا عرض بقرار صريح لا بجهل. لولا استثناؤه في
     * SILENT_TYPES لالتقطه الاحتياطي وأظهر «لا يمكن عرض هذا النوع» على 9,577
     * صفّاً — وهو أسوأ من صمته.
     */
    public function test_deliberately_silent_types_are_excluded_from_the_fallback(): void
    {
        $this->assertMatchesRegularExpression(
            "/const SILENT_TYPES = \[[^\]]*'reaction'/",
            $this->source,
            'reaction يجب أن تبقى في SILENT_TYPES ما دامت بلا معالجة'
        );

        $this->assertMatchesRegularExpression(
            '/!RENDERED_TYPES\.includes\(type\) && !SILENT_TYPES\.includes\(type\)/',
            $this->source,
            'الاحتياطي يجب أن يستثني المتروك عمداً كما يستثني المعروض'
        );
    }

    /** ولا يُدرَج المتروك عمداً في قائمة المعروض: القائمتان لا تتقاطعان. */
    public function test_silent_types_are_not_also_listed_as_rendered(): void
    {
        $this->assertNotContains('reaction', $this->declaredRenderedTypes());
        $this->assertNotContains('reaction', array_column($this->branches, 'type'));
    }

    public function test_the_fallback_is_the_last_branch_in_its_chain(): void
    {
        $fallbackIndexes = array_keys(array_column($this->branches, 'fallback'), true);

        $this->assertCount(1, $fallbackIndexes, 'احتياطي واحد فقط لا أكثر');
        $this->assertSame(
            count($this->branches) - 1,
            $fallbackIndexes[0],
            'الاحتياطي يجب أن يكون آخر فرع — أي فرع بعده لن يُبلَغ أبداً'
        );
    }

    /** الاحتياطي جزء من السلسلة لا سلسلة جديدة، وإلا صدَق على كل رسالة. */
    public function test_the_fallback_continues_the_chain_instead_of_starting_one(): void
    {
        $fallback = end($this->branches);

        $this->assertSame('else-if', $fallback['kind']);
    }

    /**
     * v-else مجرّد في سلسلة الرسائل هو بالضبط الخطأ الذي كان الاحتياطي
     * موجوداً لتفاديه.
     */
    public function test_no_bare_else_is_used_in_the_message_type_chain(): void
    {
        foreach ($this->branches as $branch) {
            $this->assertNotSame('else', $branch['kind'], 'v-else مجرّد يرسم النصّ مرّتين');
        }
    }

    // -------------------------------------- الأنواع الأربعة الصامتة سابقاً

    /**
     * @dataProvider previouslySilentTypes
     */
    public function test_previously_silent_types_now_have_a_branch(string $type): void
    {
        $this->assertContains(
            $type,
            array_column($this->branches, 'type'),
            "«{$type}" . '» كان يظهر فقاعةً فارغة ويجب أن يبقى له فرع'
        );
    }

    public static function previouslySilentTypes(): array
    {
        return [
            'تغيّر رقم' => ['system'],
            'تعديل رسالة' => ['edit'],
            'حذف رسالة' => ['revoke'],
        ];
    }

    /**
     * الأنواع التي كانت تُعرض قبل التغيير. كود إنتاجي: فقدان أيٍّ منها يعني
     * فقاعات فارغة بأثر رجعي في ملايين الرسائل.
     *
     * @dataProvider previouslyRenderedTypes
     */
    public function test_previously_rendered_types_kept_their_branch(string $type): void
    {
        $this->assertContains($type, array_column($this->branches, 'type'));
    }

    public static function previouslyRenderedTypes(): array
    {
        return [
            ['text'], ['unsupported'], ['button'], ['interactive'],
            ['image'], ['document'], ['location'], ['sticker'],
            ['contacts'], ['audio'], ['video'],
        ];
    }

    /**
     * الصفوف القديمة محفوظة خاماً والجديدة مقلَّصة، والعرض يقرأ نفس المفاتيح
     * في الحالتين — فلا حاجة لترحيل بيانات. هذا يثبت أن القراءة تمرّ بالمسار
     * المتداخل الآمن لا بمفاتيح الغلاف الخام.
     */
    public function test_readers_use_optional_chaining_so_both_shapes_work(): void
    {
        foreach (['editedBody'] as $reader) {
            $this->assertMatchesRegularExpression(
                '/const ' . $reader . ' = .*\?\./s',
                $this->source,
                "{$reader} يجب أن تقرأ بالتسلسل الاختياري كي تحتمل الصفّين القديم والجديد"
            );
        }
    }

    public function test_metadata_parsing_never_throws_on_malformed_json(): void
    {
        $this->assertMatchesRegularExpression(
            '/const parseMetadata = \(metadata\) => \{\s*try \{/',
            $this->source,
            'قراءة الـmetadata يجب أن تبقى داخل try — صفّ تالف يُسقط المحادثة كلّها'
        );
    }
}
