<?php

namespace Tests\Feature\Carousel;

use Tests\TestCase;

/**
 * واجهة الـ carousel: منطقها في وحدتين مُختبرتين بـ node، والمكوّنات
 * تستعملهما فعلاً — وتبقى مشروطةً بالـ carousel فلا يتغيّر غيره.
 */
class CarouselFrontendTest extends TestCase
{
    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    private function runNode(string $script): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $path = base_path($script);
        $this->assertFileExists($path);

        exec('node ' . escapeshellarg($path) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    private function source(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    public function test_the_send_side_logic_holds(): void
    {
        $this->runNode('tests/js/carousel-template.mjs');
    }

    public function test_the_create_side_logic_holds(): void
    {
        $this->runNode('tests/js/carousel-template-draft.mjs');
    }

    /** تعديل قالب carousel مغلق من القائمة كما هو مغلق في الخادم. */
    public function test_the_template_table_hides_edit_for_carousels(): void
    {
        $source = $this->source('resources/js/Components/Tables/TemplateTable.vue');

        $this->assertMatchesRegularExpression('/:href="\'\/templates\/\' \+ item\.uuid"/', $source);
        $this->assertStringContainsString("&& !isCarouselMetadata(item.metadata)", $source);
    }

    /**
     * صفحة الإعدادات تمرّر submitEnabled=false وتعرض القوالب للاختيار
     * فقط: البطاقات لا تظهر فيها، والقالب العادي يُرسَل كما كان.
     */
    public function test_the_campaign_form_gates_the_carousel(): void
    {
        $source = $this->source('resources/js/Components/CampaignForm.vue');

        $this->assertStringContainsString('Boolean(carouselState.value) && props.submitEnabled', $source);
        $this->assertStringContainsString('<CarouselCardsParameters v-if="isCarousel"', $source);
        $this->assertStringContainsString('isCarousel.value' . "\n\t\t? { ...data, carousel: carouselRequestPayload(carouselState.value) }\n\t\t: data", $source);
        $this->assertStringNotContainsString('readAsDataURL', $this->source('resources/js/Components/CarouselCardsParameters.vue'));
    }

    /** صفحة الإنشاء: حالة الـ carousel خارج form، والتبديل للتسويق وحده. */
    public function test_the_create_page_keeps_the_standard_payload(): void
    {
        $source = $this->source('resources/js/Pages/User/Templates/Add.vue');

        $this->assertStringContainsString("form.value.category === 'MARKETING' && templateKind.value === 'carousel'", $source);
        $this->assertStringContainsString(": form.value\n", $source);
        $this->assertStringContainsString('v-if="form.category === \'MARKETING\'"', $source);
    }

    /** فقاعة المحادثة: البطاقات داخل فرع النصّ، لا فرعاً مستقلاً لنوع جديد. */
    public function test_the_chat_bubble_renders_cards_inside_the_text_branch(): void
    {
        $source = $this->source('resources/js/Components/ChatComponents/ChatBubble.vue');

        $text = strpos($source, "<div v-if=\"meta.type === 'text'\"");
        $strip = strpos($source, '<CarouselCardsStrip');
        $next = strpos($source, "<div v-if=\"meta.type === 'unsupported'\"");

        $this->assertNotFalse($strip);
        $this->assertTrue($text < $strip && $strip < $next, 'البطاقات داخل فرع النصّ');
    }
}
