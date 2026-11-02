<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * واجهة حالة «متصل»: صفحة الأداء تتجدّد وحدها، والنبضة لا تتعطّل بالبثّ.
 *
 * منطق الدمج نفسه يُختبر بـ node (tests/js/agent-presence.mjs). وهنا الحراسة
 * على الوصل: أن الصفحة تسأل النقطة دورياً وتنظّف مؤقّتاتها، وأن النبضة تنطلق
 * قبل الاشتراك في قناة البثّ — فاشتراكٌ يرمي لا يُبقي الموظف «غير متصل».
 */
class AgentPresenceFrontendTest extends TestCase
{
    private const PAGE = 'resources/js/Pages/User/Performance.vue';
    private const LAYOUT = 'resources/js/Pages/User/Layout/App.vue';

    private function source(string $path): string
    {
        $this->assertFileExists(base_path($path));

        return file_get_contents(base_path($path));
    }

    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    public function test_the_merge_logic_holds(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/agent-presence.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    // ------------------------------------------------- صفحة الأداء

    public function test_the_page_polls_the_light_status_endpoint(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertStringContainsString("axios.get('/performance/status')", $page);
        $this->assertStringContainsString('setInterval(refreshStatus', $page);
        $this->assertStringContainsString('applyPresence(agents.value, data.agents)', $page);
    }

    /** لا تُعاد المقاييس كلّها (مسح محادثات ثلاثين يوماً) في كل دورة. */
    public function test_the_poll_does_not_reload_the_whole_page(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertDoesNotMatchRegularExpression('/setInterval\([^)]*router\./', $page);
        $this->assertStringNotContainsString('router.reload', $page);
    }

    public function test_the_page_renders_the_live_rows_not_the_initial_prop(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertStringContainsString('v-for="agent in agents"', $page);
        $this->assertStringNotContainsString('v-for="agent in metrics.agents"', $page, 'الصفوف تُرسم من الـ prop فلا تتجدّد الحالة.');
        // وزرّ Apply يستبدل الصفوف عند وصول مقاييس جديدة
        $this->assertStringContainsString('watch(() => props.metrics?.agents', $page);
    }

    public function test_the_page_cleans_up_after_itself(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertStringContainsString('clearInterval(statusTimer)', $page);
        $this->assertStringContainsString("document.removeEventListener('visibilitychange'", $page);
        $this->assertStringContainsString('onBeforeUnmount', $page);
    }

    /** تبويب مخفيّ لا يستعلم، وعودته إلى الظهور تُحدّث فوراً. */
    public function test_a_hidden_tab_does_not_poll_and_returning_refreshes(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertStringContainsString("document.visibilityState !== 'visible'", $page);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', onVisibilityChange)", $page);
    }

    /** طلب لم ينتهِ لا يتبعه آخر فوقه. */
    public function test_requests_do_not_pile_up(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertStringContainsString('statusInFlight', $page);
    }

    // ------------------------------------------------- النبضة

    public function test_the_heartbeat_starts_before_the_broadcast_subscription(): void
    {
        $layout = $this->source(self::LAYOUT);

        $mounted = strpos($layout, 'onMounted(() => {');
        $this->assertNotFalse($mounted);

        $heartbeat = strpos($layout, 'setupActivityHeartbeat()', $mounted);
        $subscribe = strpos($layout, 'getOrJoinChatChannel(', $mounted);

        $this->assertNotFalse($heartbeat, 'النبضة لا تُستدعى في onMounted.');
        $this->assertNotFalse($subscribe);
        $this->assertLessThan($subscribe, $heartbeat, 'النبضة بعد الاشتراك في البثّ: اشتراك يرمي يمنعها.');
    }

    public function test_a_failing_subscription_cannot_stop_the_heartbeat(): void
    {
        $layout = $this->source(self::LAYOUT);

        $mounted = strpos($layout, 'onMounted(() => {');
        $try = strpos($layout, 'try {', $mounted);
        $subscribe = strpos($layout, 'getOrJoinChatChannel(', $mounted);
        $catch = strpos($layout, '} catch (error) {', $subscribe);

        $this->assertNotFalse($try);
        $this->assertNotFalse($catch);
        $this->assertLessThan($subscribe, $try, 'الاشتراك خارج try.');
        $this->assertLessThan($catch, $subscribe);
    }
}
