<?php

namespace Tests\Feature\WebChat;

use App\Http\Resources\ContactResource;
use App\Jobs\SendMediaJob;
use App\Models\Addon;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Setting;
use App\Services\ChatService;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatSettings;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * الـ inbox وصفحة الإعدادات: الموظّف يرسل ملفات للزائر ويرى بياناته،
 * والتاجر يأخذ الكود ويضبط الـ widget.
 */
class InboxAndSettingsTest extends WebChatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Setting::updateOrCreate(['key' => 'storage_system'], ['value' => 'local']);
        Addon::factory()->create(['name' => 'Google Authenticator']);
    }

    // ───────────────────── ملفات الموظّف ─────────────────────

    /** الواجهة ترسل files[] دائماً؛ كان الملف يضيع على كل قناة غير واتساب. */
    public function test_an_agent_sends_several_files_in_order(): void
    {
        $contact = $this->visitorContact();
        $this->actAsOwner();

        $request = Request::create('/chats', 'POST', [
            'uuid' => $contact->uuid,
            'message' => 'هذه الصور المطلوبة',
            'types' => ['image', 'image', 'document'],
            'tempMessageIds' => ['t-1', 't-2', 't-3'],
        ], [], ['files' => [
            UploadedFile::fake()->image('a.png', 10, 10),
            UploadedFile::fake()->image('b.png', 10, 10),
            UploadedFile::fake()->create('c.pdf', 3, 'application/pdf'),
        ]]);

        $response = (new ChatService($this->organization->id))->sendMessage($request);

        $this->assertSame(['success' => true, 'queued' => 3, 'failed' => []], $response->getData(true));

        $chats = Chat::with('media')->where('contact_id', $contact->id)->where('type', 'outbound')->orderBy('id')->get();
        $this->assertSame(['image', 'image', 'document'], $chats->map(fn ($c) => json_decode($c->metadata, true)['type'])->all());
        $this->assertSame('هذه الصور المطلوبة', json_decode($chats[0]->metadata, true)['image']['caption'], 'التعليق للأول وحده.');
        $this->assertArrayNotHasKey('caption', json_decode($chats[1]->metadata, true)['image'] ?? []);

        // الرابط مسار حقيقي لا /media/1 (put يُرجع true لا المسار).
        $this->assertMatchesRegularExpression('#/media/public/[^/]+\.png$#', $chats[0]->media->path);
        Storage::disk('local')->assertExists(substr(parse_url($chats[0]->media->path, PHP_URL_PATH), strlen('/media/')));
    }

    public function test_a_chunked_upload_to_a_visitor_is_sent_on_its_channel(): void
    {
        // fake ثانية تُلغي الأولى: نُعيد معها ما يزيّفه الأساس.
        Bus::fake([SendMediaJob::class, \App\Jobs\ProcessAutoReplyJob::class]);
        $contact = $this->visitorContact();
        $this->actAsOwner();

        $this->post('/chats/upload/chunk', [
            'upload_id' => 'up-1',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->image('big.png', 40, 40),
            'contact_uuid' => $contact->uuid,
            'file_name' => 'big.png',
            'file_type' => 'image',
            'caption' => 'الصورة كاملة',
            'temp_message_id' => 't-9',
        ])->assertOk()->assertJson(['success' => true, 'completed' => true]);

        Bus::assertNotDispatched(SendMediaJob::class);
        $chat = Chat::with('media')->where('contact_id', $contact->id)->where('type', 'outbound')->first();
        $this->assertSame('الصورة كاملة', json_decode($chat->metadata, true)['image']['caption']);
        $this->assertNotNull($chat->media);
    }

    // ───────────────────── بيانات الزائر للموظّف ─────────────────────

    public function test_the_agent_sees_where_the_visitor_came_from(): void
    {
        $contact = $this->visitorContact();
        $contact->webchatVisitor->forceFill([
            'last_page_url' => 'https://shop.example.com/cart',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
            'country' => 'SA',
        ])->save();

        $payload = ChatService::contactPayloadForChatView($contact->fresh());

        $this->assertSame('https://shop.example.com/cart', $payload['webchat_visitor']['last_page_url']);
        $this->assertSame('Safari · iOS', $payload['webchat_visitor']['browser']);
        $this->assertSame('+966 50 123 4567', $payload['formatted_phone_number']);
        $this->assertFalse($payload['channel_capabilities']['templates']);
        $this->assertArrayNotHasKey('token_hash', $payload['webchat_visitor']);
    }

    public function test_the_mobile_contact_resource_carries_the_channel(): void
    {
        $contact = $this->visitorContact()->load('webchatVisitor');

        $data = (new ContactResource($contact))->toArray(request());

        $this->assertSame(Channel::WEBCHAT, $data['channel']);
        $this->assertFalse($data['channel_capabilities']['location']);
        $this->assertSame('+966501234567', $data['webchat_visitor']['phone']);
    }

    // ───────────────────── صفحة الإعدادات ─────────────────────

    public function test_the_settings_page_gives_the_install_code(): void
    {
        $this->actAsOwner();

        $props = $this->inertiaProps('/settings/webchat');

        $key = $this->widget->external_id;
        $this->assertSame($key, $props['widget']['key']);
        $this->assertStringContainsString('data-mnjz-key="' . $key . '"', $props['widget']['snippet']);
        $this->assertStringContainsString('/widget/v1/loader.js', $props['widget']['snippet']);
        $this->assertTrue($props['settingsModuleWebChat']);
    }

    public function test_the_settings_page_needs_the_addon_and_is_closed_to_agents(): void
    {
        $this->actAsOwner();
        $this->plan->update(['metadata' => json_encode(['addons' => ['Web Chat' => false]])]);
        \Illuminate\Support\Facades\Cache::flush();

        $this->get('/settings/webchat')->assertForbidden();

        $this->plan->update(['metadata' => json_encode(['addons' => ['Web Chat' => true]])]);
        \Illuminate\Support\Facades\Cache::flush();
        \App\Models\Team::where('user_id', $this->organization->created_by)->update(['role' => 'agent']);

        $this->get('/settings/webchat')->assertForbidden();
    }

    public function test_saving_the_settings(): void
    {
        $this->actAsOwner();

        $this->post('/settings/webchat', [
            'enabled' => true,
            'allowed_domains' => ['https://www.Shop.example.com/path', '*.example.org', 'not a domain'],
            'appearance' => ['color' => '#123ABC', 'position' => 'left', 'offset_x' => 10, 'offset_y' => 30, 'title' => 'تحدّث معنا', 'subtitle' => '', 'welcome_message' => 'أهلاً بك', 'language' => 'ar'],
            'prechat' => ['default_country' => 'ae', 'email' => 'optional', 'name_label' => '', 'phone_label' => 'جوالك', 'consent_text' => ''],
            'behavior' => ['show_agent_names' => false, 'sound' => true, 'uploads' => false],
        ])->assertRedirect();

        $settings = WebChatSettings::for($this->widget->fresh());
        $this->assertSame(['shop.example.com', '*.example.org'], $settings['allowed_domains']);
        $this->assertSame('#123abc', $settings['appearance']['color']);
        $this->assertSame('left', $settings['appearance']['position']);
        $this->assertSame('AE', $settings['prechat']['default_country']);
        $this->assertSame('جوالك', $settings['prechat']['phone_label']);
        $this->assertFalse($settings['behavior']['uploads']);
    }

    public function test_an_uploaded_logo_is_served_from_our_media_link(): void
    {
        $this->actAsOwner();

        $this->post('/settings/webchat/logo', ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertRedirect();

        $logo = WebChatSettings::publicView($this->widget->fresh())['appearance']['logo_url'];
        $this->assertMatchesRegularExpression('#/media/public/webchat-logos/\d+/[a-z0-9]+\.png$#', (string) $logo);
        $this->assertArrayNotHasKey('logo_path', WebChatSettings::publicView($this->widget->fresh())['appearance']);

        $this->post('/settings/webchat/logo', ['logo' => UploadedFile::fake()->createWithContent('x.svg', '<svg onload="alert(1)"/>')])
            ->assertSessionHasErrors('logo');
    }

    public function test_a_new_key_retires_the_old_one(): void
    {
        $this->actAsOwner();
        $old = $this->widget->external_id;

        $this->post('/settings/webchat/rotate-key')->assertRedirect();

        $new = $this->widget->fresh()->external_id;
        $this->assertNotSame($old, $new);
        $this->getJson('/api/webchat/v1/w/' . $old . '/config')->assertNotFound();
        $this->getJson('/api/webchat/v1/w/' . $new . '/config')->assertOk();
    }

    public function test_the_widget_cannot_be_deleted_from_the_channels_page(): void
    {
        $this->actAsOwner();

        $this->delete('/settings/channels/' . $this->widget->uuid)->assertRedirect();

        $this->assertNotNull(ChannelAccount::find($this->widget->id));
    }

    // ───────────────────── أدوات ─────────────────────

    private function visitorContact(): Contact
    {
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);

        return Contact::find($visitor->fresh()->contact_id);
    }

    /** @return array<string, mixed> */
    private function inertiaProps(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'الصفحة لا تحمل بيانات Inertia: ' . $url);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }
}
