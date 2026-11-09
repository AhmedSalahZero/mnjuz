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
    private const HEARTBEAT = 'resources/js/Composables/agentHeartbeat.js';
    private const ECHO = 'resources/js/echo.js';

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

    // ------------------------------------------------- الحالة اللحظية

    /** الصفحة تستمع لحدث التغيّر، وتستدرك ما فاتها عند الاشتراك وعودة الاتصال. */
    public function test_the_page_listens_for_realtime_presence(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertStringContainsString('subscribeAgentPresence(organizationId, page.props.broadcast, onPresenceChanged, refreshStatus)', $page);
        $this->assertStringContainsString('applyPresence(agents.value, [event])', $page);
        $this->assertStringContainsString('unsubscribePresence()', $page);
    }

    /** البثّ مساعد: اشتراك يرمي لا يكسر الصفحة، ويبقى الاستعلام. */
    public function test_a_failing_presence_subscription_keeps_the_poll(): void
    {
        $page = $this->source(self::PAGE);

        $interval = strpos($page, 'statusTimer = setInterval(refreshStatus');
        $try = strpos($page, 'try {', $interval);
        $subscribe = strpos($page, 'subscribeAgentPresence(organizationId', $interval);

        $this->assertNotFalse($interval);
        $this->assertNotFalse($try);
        $this->assertLessThan($subscribe, $try, 'الاشتراك خارج try.');
    }

    /** اسم القناة والحدث كما يبثّهما الخادم (AgentPresenceChanged). */
    public function test_the_client_channel_matches_the_server(): void
    {
        $echo = $this->source(self::ECHO);

        $this->assertStringContainsString('`agents.presence.ch${organizationId}`', $echo);
        $this->assertStringContainsString(".private(name)", $echo);
        $this->assertStringContainsString("listen('.AgentPresenceChanged'", $echo);
        $this->assertSame(
            'private-agents.presence.ch7',
            (new \App\Events\AgentPresenceChanged(7, 1, true, null, 0))->broadcastOn()[0]->name
        );
    }

    // ------------------------------------------------- نبضة التبويب

    /** مؤقّت واحد للتبويب لا للـ layout الذي يُركَّب مع كل تنقّل. */
    public function test_the_layout_delegates_to_the_tab_heartbeat(): void
    {
        $layout = $this->source(self::LAYOUT);

        $this->assertStringContainsString('startAgentHeartbeat(organization.value.id)', $layout);
        $this->assertStringNotContainsString("axios.post('/performance/heartbeat'", $layout);
        $this->assertStringNotContainsString('setInterval(sendHeartbeat', $layout);
    }

    /** إيقاع النبضة كما يتوقّعه الخادم في نافذته. */
    public function test_the_heartbeat_interval_matches_the_server(): void
    {
        $heartbeat = $this->source(self::HEARTBEAT);

        $this->assertStringContainsString(
            'export const HEARTBEAT_MS = ' . (\App\Services\AgentPresence::HEARTBEAT_SECONDS * 1000),
            $heartbeat
        );
        $this->assertLessThan(
            \App\Services\AgentPresence::ONLINE_WINDOW_SECONDS,
            \App\Services\AgentPresence::HEARTBEAT_SECONDS * 2,
            'نبضتان فائتتان يجب أن تبقيا داخل النافذة.'
        );
    }

    /** الإغلاق يُعلَن ولو كانت الصفحة تُغلق، بالمنظمة والتبويب. */
    public function test_closing_the_tab_announces_leaving(): void
    {
        $heartbeat = $this->source(self::HEARTBEAT);

        $this->assertStringContainsString("window.addEventListener('pagehide', onPageHide)", $heartbeat);
        $this->assertStringContainsString("const OFFLINE_URL = '/performance/offline'", $heartbeat);
        $this->assertStringContainsString('keepalive: true', $heartbeat);
        $this->assertStringContainsString("'X-XSRF-TOKEN'", $heartbeat);
        $this->assertStringContainsString('organization_id: state?.organizationId', $heartbeat);
    }

    /** الفشل لا يُبتلع صامتاً، وانتهاء الجلسة يوقف النبضة. */
    public function test_heartbeat_failures_are_visible_and_stop_on_auth_errors(): void
    {
        $heartbeat = $this->source(self::HEARTBEAT);

        $this->assertStringContainsString("console.warn('[Presence]", $heartbeat);
        $this->assertStringContainsString('status === 401 || status === 403 || status === 419', $heartbeat);
    }
}
