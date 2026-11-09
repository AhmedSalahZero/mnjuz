<?php

namespace Tests\Feature\WebChat;

use App\Models\Addon;

/**
 * ما يوصل الـ widget إلى موقع التاجر: رابط الواجهة ببصمة البناء، وقناة
 * الزائر، ومعاينة صفحة الإعدادات.
 */
class WidgetDeliveryTest extends WebChatTestCase
{
    public function test_config_points_to_the_built_bundle_with_its_fingerprint(): void
    {
        if (!is_file(public_path('widget/v1/app.js'))) {
            $this->markTestSkipped('شغّل npm run build:widget أولاً.');
        }

        $bundle = $this->getJson($this->url('config'))->assertOk()->json('bundle');

        $this->assertMatchesRegularExpression('#/widget/v1/app\.js\?v=[0-9a-f]{12}$#', (string) $bundle);
    }

    public function test_the_session_tells_the_visitor_its_channel_when_realtime_is_live(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $response = $this->postJson($this->url('sessions'), [
            'name' => 'سارة', 'phone' => '+966 50 123 4567', 'elapsed_ms' => 4000,
        ])->assertCreated();

        $this->assertSame(
            'private-webchat.ch' . $this->organization->id . '.' . $response->json('visitor.id'),
            $response->json('channel')
        );

        $session = $this->getJson($this->url('session'), ['X-Webchat-Token' => $response->json('token')])->assertOk();
        $this->assertSame($response->json('channel'), $session->json('channel'));

        config(['broadcasting.default' => 'log']);
        $this->getJson($this->url('session'), ['X-Webchat-Token' => $response->json('token')])->assertJsonPath('channel', null);
    }

    public function test_a_disabled_widget_works_only_in_our_own_preview(): void
    {
        $this->setSettings(['enabled' => false]);
        $own = rtrim((string) config('app.url'), '/');

        $this->getJson($this->url('config'))->assertNotFound();
        $this->getJson($this->url('config') . '?preview=1', ['Origin' => 'https://shop.example.com'])->assertNotFound();

        $preview = $this->getJson($this->url('config') . '?preview=1', ['Origin' => $own])->assertOk();
        $this->assertStringContainsString('no-store', (string) $preview->headers->get('Cache-Control'));

        // والنداءات التالية من المعاينة بالترويسة.
        $this->postJson($this->url('sessions'), ['name' => 'تاجر', 'phone' => '+966 50 123 4567', 'elapsed_ms' => 4000], [
            'Origin' => $own,
            'X-Webchat-Preview' => '1',
        ])->assertCreated();
    }

    public function test_the_settings_preview_page_embeds_the_widget(): void
    {
        Addon::factory()->create(['name' => 'Google Authenticator']);
        $this->actAsOwner();

        $html = $this->get('/settings/webchat/preview')->assertOk()->getContent();

        $this->assertStringContainsString('data-mnjz-key="' . $this->widget->external_id . '"', $html);
        $this->assertStringContainsString('data-mnjz-preview="1"', $html);
        $this->assertStringContainsString('/widget/v1/loader.js', $html);
    }

    /**
     * APP_URL بلا scheme (كان محلياً `localhost`): خرج رابط الـ loader نسبياً
     * فطلبه المتصفّح تحت /settings/webchat/ ورجع 404، فظهرت المعاينة فارغة.
     * الروابط تُبنى من المضيف الذي يخدم الطلب، وكذلك التعرّف على صفحتنا.
     */
    public function test_links_are_absolute_even_when_app_url_has_no_scheme(): void
    {
        config(['app.url' => 'localhost']);
        $root = 'http://127.0.0.1:8000';

        Addon::factory()->create(['name' => 'Google Authenticator']);
        $this->actAsOwner();

        $html = $this->get($root . '/settings/webchat/preview')->assertOk()->getContent();
        // مطلقٌ بمضيف الطلب (https خارج local بـ forceScheme في AppServiceProvider).
        $absolute = '#https?://127\.0\.0\.1:8000/widget/v1/';
        $this->assertMatchesRegularExpression($absolute . 'loader\.js"#', $html);

        // widget معطَّل: معاينة صفحتنا تعمل، وموقع غريب لا.
        $this->setSettings(['enabled' => false]);
        $config = $this->getJson($root . $this->url('config') . '?preview=1', ['Origin' => $root])->assertOk();
        $this->getJson($root . $this->url('config') . '?preview=1', ['Origin' => 'https://shop.example.com'])->assertNotFound();

        if (is_file(public_path('widget/v1/app.js'))) {
            $this->assertMatchesRegularExpression($absolute . 'app\.js\?v=[0-9a-f]{12}$#', (string) $config->json('bundle'));
        }

        // مواقع محدّدة: صفحتنا مسموحة دائماً، وغيرها بحسب القائمة.
        $this->setSettings(['allowed_domains' => ['mysite.test']]);
        $this->getJson($root . $this->url('config'), ['Origin' => $root])->assertOk();
        $this->getJson($root . $this->url('config'), ['Origin' => 'http://mysite.test'])->assertOk();
        $this->getJson($root . $this->url('config'), ['Origin' => 'https://shop.example.com'])->assertForbidden();
    }
}
