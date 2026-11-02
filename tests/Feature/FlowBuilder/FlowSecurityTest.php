<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Resources\FlowResource;
use Modules\FlowBuilder\Support\FlowSecrets;

/**
 * مسارات منشئ المسارات كانت خلف auth:user وحدها، وكل استعلام بالـ uuid بلا
 * منشأة: أيّ مستخدم يفتح flow منشأة أخرى ويعدّله ويحذفه، والموظف (agent)
 * يدخل المحرّر. والرفع بلا تحقّق يخزّن html يُخدَم من نطاق التطبيق، وكلمة مرور
 * SMTP تصل إلى المتصفح في props الصفحة.
 */
class FlowSecurityTest extends FlowBuilderTestCase
{
    private const PASSWORD = 'Sm7p-S3cret!';

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    /** مسار يخصّ منشأة أخرى بمالكها. */
    private function foreignFlow(): Flow
    {
        $stranger = User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($stranger);

        return $this->makeFlow([$this->startNode(), $this->textNode('2', 'أهلاً')], [['1', '2']], [
            'organization_id' => $other->id,
            'name' => 'foreign',
        ]);
    }

    private function emailFlow(): Flow
    {
        return $this->makeFlow([
            $this->startNode(),
            $this->actionNode('2', 'send_email', [
                'subject' => 'مرحبا',
                'body' => 'نص',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'bot@example.com',
                'smtp_password' => self::PASSWORD,
                'smtp_encryption' => 'tls',
            ]),
        ], [['1', '2']], ['status' => 'inactive']);
    }

    // ------------------------------------------------- منشأة أخرى

    public function test_another_organizations_flow_cannot_be_opened(): void
    {
        $this->asOwner()->get('/automation/flows/' . $this->foreignFlow()->uuid)->assertNotFound();
    }

    public function test_another_organizations_flow_cannot_be_updated(): void
    {
        $flow = $this->foreignFlow();

        $this->asOwner()
            ->putJson('/automation/flows/' . $flow->uuid, ['name' => 'stolen', 'publish' => 0])
            ->assertNotFound();

        $this->assertSame('foreign', $flow->fresh()->name);
    }

    public function test_another_organizations_flow_cannot_be_deleted(): void
    {
        $flow = $this->foreignFlow();

        $this->asOwner()->deleteJson('/automation/flows/' . $flow->uuid)->assertNotFound();

        $this->assertNotNull($flow->fresh());
    }

    public function test_another_organizations_flow_cannot_be_duplicated(): void
    {
        $flow = $this->foreignFlow();

        $this->asOwner()->get('/automation/flows/duplicate/' . $flow->uuid);

        $this->assertSame(1, Flow::where('organization_id', $flow->organization_id)->count());
        $this->assertSame(0, Flow::where('organization_id', $this->organization->id)->count());
    }

    public function test_another_organizations_flow_cannot_receive_media(): void
    {
        Storage::fake('local');
        $flow = $this->foreignFlow();

        $this->asOwner()
            ->post('/automation/flows/upload-media/' . $flow->uuid . '/2', [
                'file' => UploadedFile::fake()->image('photo.jpg'),
                'type' => 'image',
            ], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, FlowMedia::count());
    }

    public function test_an_agent_cannot_open_the_flow_builder(): void
    {
        $agent = User::factory()->create(['role' => 'user']);
        Team::factory()->create([
            'user_id' => $agent->id,
            'organization_id' => $this->organization->id,
            'role' => 'agent',
            'created_by' => $this->owner->id,
        ]);
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->actingAs($agent, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->get('/automation/flows/' . $flow->uuid)
            ->assertForbidden();
    }

    public function test_the_owner_still_opens_their_own_flow(): void
    {
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->asOwner()->get('/automation/flows/' . $flow->uuid)->assertOk();
    }

    // ------------------------------------------------- الرفع

    public function test_an_html_upload_is_rejected(): void
    {
        Storage::fake('local');
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->asOwner()
            ->post('/automation/flows/upload-media/' . $flow->uuid . '/2', [
                'file' => UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_svg_upload_is_rejected(): void
    {
        Storage::fake('local');
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->asOwner()
            ->post('/automation/flows/upload-media/' . $flow->uuid . '/2', [
                'file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
                'type' => 'image',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    /** الامتداد يكذب: صفحة html باسم pdf تُكتشف من محتواها. */
    public function test_html_disguised_as_a_pdf_is_rejected(): void
    {
        Storage::fake('local');
        $flow = $this->makeFlow([$this->startNode()], []);

        // ملف حقيقي لا fake(): الوهمي يُبلغ عن نوعه من امتداده، والحقيقي من محتواه.
        $path = tempnam(sys_get_temp_dir(), 'flow');
        file_put_contents($path, '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');

        $this->asOwner()
            ->post('/automation/flows/upload-media/' . $flow->uuid . '/2', [
                'file' => new UploadedFile($path, 'report.pdf', null, null, true),
                'type' => 'document',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        @unlink($path);

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_image_over_whatsapps_limit_is_rejected(): void
    {
        Storage::fake('local');
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->asOwner()
            ->post('/automation/flows/upload-media/' . $flow->uuid . '/2', [
                'file' => UploadedFile::fake()->image('big.jpg')->size(6 * 1024),
                'type' => 'image',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_a_valid_image_is_stored_under_its_own_extension(): void
    {
        Storage::fake('local');
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->asOwner()
            ->post('/automation/flows/upload-media/' . $flow->uuid . '/2', [
                'file' => UploadedFile::fake()->image('photo.jpg'),
                'type' => 'image',
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $files = Storage::disk('local')->allFiles('public');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.jpg', $files[0]);
        $this->assertSame($flow->id, FlowMedia::first()->flow_id);
    }

    // ------------------------------------------------- كلمة مرور SMTP

    public function test_the_smtp_password_never_reaches_the_editor_page(): void
    {
        $flow = $this->emailFlow();

        $this->asOwner()
            ->get('/automation/flows/' . $flow->uuid)
            ->assertOk()
            ->assertDontSee(self::PASSWORD)
            ->assertSee('has_smtp_password');
    }

    public function test_the_flow_list_carries_no_metadata(): void
    {
        // DateTimeHelper يقرأ صيغتي التاريخ والوقت لكل صفّ.
        Setting::create(['key' => 'date_format', 'value' => 'd/m/Y']);
        Setting::create(['key' => 'time_format', 'value' => 'H:i']);
        $flow = $this->emailFlow();

        $row = (new FlowResource($flow))->toArray(request());

        $this->assertArrayNotHasKey('metadata', $row);
        $this->asOwner()->get('/automation/flows')->assertOk()->assertDontSee(self::PASSWORD);
    }

    /** المحرّر يرسل الميتاداتا كما استلمها (بلا كلمة مرور)؛ المخزّنة تبقى. */
    public function test_saving_without_the_password_keeps_the_stored_one(): void
    {
        $flow = $this->emailFlow();
        $fromBrowser = FlowSecrets::redact($flow->metadata);

        $this->asOwner()
            ->putJson('/automation/flows/' . $flow->uuid, ['metadata' => $fromBrowser])
            ->assertOk();

        $stored = json_decode($flow->fresh()->metadata, true);
        $this->assertSame(self::PASSWORD, $stored['nodes'][1]['data']['config']['smtp_password']);
        // المنفّذ يقرأ إعداد الإجراء من نسخة العقدة داخل الحافة.
        $this->assertSame(self::PASSWORD, $stored['edges'][0]['targetNode']['data']['config']['smtp_password']);
        $this->assertArrayNotHasKey('has_smtp_password', $stored['nodes'][1]['data']['config']);
    }

    public function test_a_new_password_replaces_the_stored_one(): void
    {
        $flow = $this->emailFlow();
        $graph = json_decode(FlowSecrets::redact($flow->metadata), true);
        $graph['nodes'][1]['data']['config']['smtp_password'] = 'new-pass';
        $graph['edges'][0]['targetNode']['data']['config']['smtp_password'] = 'new-pass';

        $this->asOwner()
            ->putJson('/automation/flows/' . $flow->uuid, ['metadata' => json_encode($graph)])
            ->assertOk();

        $stored = json_decode($flow->fresh()->metadata, true);
        $this->assertSame('new-pass', $stored['nodes'][1]['data']['config']['smtp_password']);
    }

    /** الإخفاء لا يمسّ بقية الملف: {} يبقى كائناً والعربية لا تُهرَّب. */
    public function test_redaction_leaves_the_rest_of_the_graph_untouched(): void
    {
        $flow = $this->emailFlow();
        $redacted = FlowSecrets::redact($flow->metadata);

        $this->assertStringContainsString('"subject":"مرحبا"', $redacted);
        $this->assertSame(
            json_decode($flow->metadata, true)['viewport'],
            json_decode($redacted, true)['viewport']
        );

        // مسار بلا بريد يعود كما هو بايتاً ببايت.
        $plain = $this->makeFlow([$this->startNode(), $this->textNode('2', 'نص')], [['1', '2']]);
        $this->assertSame($plain->metadata, FlowSecrets::redact($plain->metadata));
        $this->assertSame($plain->metadata, FlowSecrets::restore($plain->metadata, $plain->metadata));
    }
}
