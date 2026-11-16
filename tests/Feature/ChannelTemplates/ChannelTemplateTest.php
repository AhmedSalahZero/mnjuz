<?php

namespace Tests\Feature\ChannelTemplates;

use App\Jobs\ProcessAccountUpdateJob;
use App\Models\ChannelTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Campaigns\CampaignTestCase;

/**
 * القوالب المحفوظة لتيليجرام والبريد: جاهزة فور الحفظ بلا Meta ولا موافقة،
 * كل بحث فيها بالمنشأة والقناة، وقوالب واتساب لا تختلط بها.
 */
class ChannelTemplateTest extends CampaignTestCase
{
    protected function tearDown(): void
    {
        foreach (ChannelTemplate::withTrashed()->get() as $template) {
            $url = $template->media['url'] ?? null;

            if ($url) {
                Storage::disk('local')->delete(Str::after((string) parse_url($url, PHP_URL_PATH), '/media/'));
            }
        }

        parent::tearDown();
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    private function saved(string $channel, array $attributes = [], ?int $organizationId = null): ChannelTemplate
    {
        return ChannelTemplate::create(array_merge([
            'organization_id' => $organizationId ?? $this->organization->id,
            'channel' => $channel,
            'name' => 'عرض العيد',
            'subject' => $channel === 'email' ? 'عرض خاص' : null,
            'body' => 'أهلاً {first_name}',
        ], $attributes));
    }

    public function test_a_telegram_template_is_ready_without_any_approval_call(): void
    {
        Http::fake();

        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'عرض العيد',
            'body' => 'خصم 20% يا {first_name}',
        ])->assertRedirect('/templates/channel/telegram');

        $template = ChannelTemplate::first();
        $this->assertSame('telegram', $template->channel);
        $this->assertSame($this->organization->id, (int) $template->organization_id);
        $this->assertNull($template->subject);
        Http::assertNothingSent();
    }

    public function test_a_telegram_template_with_an_attachment_keeps_its_text_within_the_caption_limit(): void
    {
        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'بصورة',
            'body' => str_repeat('أ', 1025),
            'media' => UploadedFile::fake()->image('offer.png'),
        ])->assertSessionHasErrors('body');

        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'بصورة',
            'body' => str_repeat('أ', 1024),
            'media' => UploadedFile::fake()->image('offer.png'),
        ])->assertSessionHasNoErrors();

        $media = ChannelTemplate::first()->media;
        $this->assertSame('image', $media['type']);
        $this->get(parse_url($media['url'], PHP_URL_PATH))->assertOk();
    }

    public function test_an_email_template_needs_a_subject_and_refuses_attachments(): void
    {
        $this->asOwner()->post('/templates/channel/email', ['name' => 'ترحيب', 'body' => 'أهلاً'])
            ->assertSessionHasErrors('subject');

        $this->asOwner()->post('/templates/channel/email', [
            'name' => 'ترحيب', 'subject' => 'أهلاً', 'body' => 'أهلاً',
            'media' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('media');

        $this->asOwner()->post('/templates/channel/email', ['name' => 'ترحيب', 'subject' => 'أهلاً بك', 'body' => 'نص'])
            ->assertSessionHasNoErrors();

        $this->assertSame('أهلاً بك', ChannelTemplate::where('channel', 'email')->value('subject'));
    }

    public function test_a_subject_with_a_line_break_is_refused(): void
    {
        $this->asOwner()->post('/templates/channel/email', ['name' => 'x', 'subject' => "سطر\nBcc: evil@example.com", 'body' => 'نص'])
            ->assertSessionHasErrors('subject');
    }

    public function test_names_are_unique_per_channel_inside_an_organization(): void
    {
        $this->saved('telegram');
        $stranger = User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($stranger);
        $this->saved('telegram', [], $other->id);

        // الاسم نفسه في قناة أخرى مقبول.
        $this->asOwner()->post('/templates/channel/email', ['name' => 'عرض العيد', 'subject' => 's', 'body' => 'b'])
            ->assertSessionHasNoErrors();

        // ومكرّر في القناة نفسها مرفوض.
        $this->asOwner()->post('/templates/channel/telegram', ['name' => 'عرض العيد', 'body' => 'b'])
            ->assertSessionHasErrors('name');
    }

    public function test_another_organizations_template_cannot_be_opened_updated_or_deleted(): void
    {
        $stranger = User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($stranger);
        $foreign = $this->saved('telegram', [], $other->id);

        $this->asOwner()->get('/templates/channel/telegram/' . $foreign->uuid)->assertNotFound();
        $this->asOwner()->post('/templates/channel/telegram/' . $foreign->uuid, ['name' => 'x', 'body' => 'y'])->assertNotFound();
        $this->asOwner()->delete('/templates/channel/telegram/' . $foreign->uuid)->assertNotFound();

        $this->assertSame('أهلاً {first_name}', $foreign->fresh()->body);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    /** وقالب البريد لا يُفتح من مسار تيليجرام. */
    public function test_a_template_is_only_reachable_on_its_own_channel(): void
    {
        $email = $this->saved('email');

        $this->asOwner()->get('/templates/channel/telegram/' . $email->uuid)->assertNotFound();
        $this->asOwner()->get('/templates/channel/email/' . $email->uuid)->assertOk();
    }

    public function test_an_unknown_channel_has_no_template_pages(): void
    {
        foreach (['messenger', 'whatsapp', 'sms'] as $channel) {
            $status = $this->asOwner()->get('/templates/channel/' . $channel)->getStatusCode();
            $this->assertSame(404, $status, $channel);
        }
    }

    /** كقوالب واتساب اليوم: الموظّف يدير القوالب. */
    public function test_an_agent_can_manage_saved_templates(): void
    {
        $agent = User::factory()->create(['role' => 'user']);
        Team::factory()->create(['user_id' => $agent->id, 'organization_id' => $this->organization->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        $this->actingAs($agent, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->post('/templates/channel/telegram', ['name' => 'من الموظف', 'body' => 'نص'])
            ->assertRedirect('/templates/channel/telegram');

        $this->assertSame(1, ChannelTemplate::count());
    }

    public function test_editing_keeps_or_removes_the_attachment(): void
    {
        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'بصورة', 'body' => 'نص', 'media' => UploadedFile::fake()->image('offer.png'),
        ]);
        $template = ChannelTemplate::first();
        // الملف يبقى بعد إزالته من القالب (حملات مجدولة نسخت رابطه): نحذفه نحن.
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete(
            Str::after((string) parse_url($template->media['url'], PHP_URL_PATH), '/media/')
        ));

        $this->asOwner()->post('/templates/channel/telegram/' . $template->uuid, ['name' => 'بصورة', 'body' => 'نص معدّل'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($template->fresh()->media);
        $this->assertSame('نص معدّل', $template->fresh()->body);

        $this->asOwner()->post('/templates/channel/telegram/' . $template->uuid, ['name' => 'بصورة', 'body' => 'نص', 'remove_media' => 1])
            ->assertSessionHasNoErrors();
        $this->assertNull($template->fresh()->media);
    }

    /** فصل واتساب يحذف قوالب Meta وحدها. */
    public function test_whatsapp_disconnect_keeps_saved_templates(): void
    {
        $saved = $this->saved('telegram');
        $meta = $this->template();

        (new ProcessAccountUpdateJob(['field' => 'account_update', 'value' => ['event' => 'PARTNER_REMOVED']], $this->organization->id))->handle();

        $this->assertNotNull(\App\Models\Template::withTrashed()->find($meta->id)->deleted_at);
        $this->assertNull($saved->fresh()->deleted_at);
    }

    /** وقوائم قوالب واتساب (الحملة، الجوال) لا ترى القوالب المحفوظة. */
    public function test_whatsapp_template_lists_never_include_saved_templates(): void
    {
        $this->saved('telegram', ['name' => 'saved_only_here']);
        $this->template('eid_offer');

        $response = $this->asOwner()->get('/campaigns/create')->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(['eid_offer'], collect($props['templates'])->pluck('name')->all());
        $this->assertSame(['saved_only_here'], collect($props['channelTemplates']['telegram'])->pluck('name')->all());
    }
}
