<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الصندوق المتوسّط رأسياً لا يُقصّ حين يطول.
 *
 * العطل: الصفحة تضع صندوقها داخل حاوية بارتفاع ثابت (`md:h-[90vh]`) وتوسّطه
 * رأسياً. فمتى زاد طول الصندوق عن الحاوية — وهو ما يحدث مع كل فترة دوام
 * يضيفها المستخدم — فاض من أعلى وأسفل معاً، والفائض من أعلى **لا يُبلَغ
 * بالتمرير**: حاوية التمرير لا تمرّر إلى ما قبل بداية محتواها.
 *
 * فيرى المستخدم أعلى الصندوق (مربّع نصّ الردّ) وقد اختفى بلا سبيل إليه.
 *
 * الحلّ الحدّ الأدنى بدل الثابت: التوسيط يبقى للمحتوى القصير، والحاوية تطول
 * مع المحتوى الطويل فلا يُقصّ منه شيء.
 */
class SettingsPageOverflowTest extends TestCase
{
    private const PAGE = 'resources/js/Pages/User/Settings/WorkingHours.vue';

    public function test_the_repeater_page_does_not_clip_its_box(): void
    {
        $page = file_get_contents(base_path(self::PAGE));

        $this->assertStringNotContainsString(
            'class="md:h-[90vh]"',
            $page,
            'ارتفاع ثابت مع توسيط رأسي يقصّ أعلى الصندوق حين يطول بالفترات المضافة'
        );

        $this->assertStringContainsString('md:min-h-[90vh]', $page);
    }

    /**
     * التوسيط الأفقي يبقى، والرأسي يسقط.
     *
     * الصندوق المتوسّط رأسياً ينزل نصفه تحت حافة عمود التمرير متى طال، فلا
     * يُبلَغ زرّا «إضافة» و«حفظ» في أسفله. البداية من الأعلى تجعل الزيادة
     * في اتجاه واحد يمرّره العمود.
     */
    public function test_the_box_grows_downwards_only(): void
    {
        $page = file_get_contents(base_path(self::PAGE));

        $this->assertStringContainsString('flex justify-center items-start', $page);
        $this->assertStringNotContainsString('flex justify-center items-center', $page);
    }

    /**
     * عمود المحتوى في الإعدادات حاوية تمرير فعلية.
     *
     * كان فيه overflow-y-auto وh-full، لكن آباءه بلا ارتفاع محدّد فيؤول
     * h-full إلى auto — فينمو بدل أن يمرّر، ويقع ما زاد خارج صفّ التطبيق
     * المثبّت على h-screen بلا سبيل إليه.
     */
    public function test_the_settings_column_can_actually_scroll(): void
    {
        $layout = file_get_contents(base_path('resources/js/Pages/User/Settings/Layout.vue'));

        $this->assertStringContainsString('md:h-screen md:flex md:flex-col', $layout, 'الحاوية بلا ارتفاع محدّد');
        $this->assertStringContainsString('md:flex-1 md:min-h-0', $layout, 'بلا min-h-0 لا ينكمش العمود فلا يمرّر');
        $this->assertStringContainsString('md:overflow-y-auto', $layout);
    }

    /** والمكرِّر ما زال يضيف ويحذف. */
    public function test_the_repeater_still_works(): void
    {
        $page = file_get_contents(base_path(self::PAGE));

        $this->assertStringContainsString('form.slots.push(', $page);
        $this->assertStringContainsString('form.slots.splice(index, 1)', $page);
        $this->assertStringContainsString('v-for="(row, index) in form.slots"', $page);
    }
}
