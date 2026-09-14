<?php

namespace Tests\Feature\MobileApi;

use App\Models\AutoReply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * الأتمتة الأساسية (الردود الجاهزة) من التطبيق.
 */
class MobileAutomationTest extends MobileApiTestCase
{
    private function reply(array $overrides = []): AutoReply
    {
        return AutoReply::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'ترحيب',
            'trigger' => 'مرحبا',
            'match_criteria' => 'contains',
            'metadata' => json_encode(['type' => 'text', 'data' => ['text' => 'أهلاً بك']]),
            'created_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ترحيب',
            'trigger' => 'مرحبا',
            'match_criteria' => 'contains',
            'response_type' => 'text',
            'response' => 'أهلاً بك',
        ], $overrides);
    }

    // ------------------------------------------------- القراءة

    public function test_it_lists_replies(): void
    {
        $this->reply(['name' => 'الأول']);
        $this->reply(['name' => 'الثاني']);

        $this->getJson('/api/v1/automation/basic')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_it_searches_by_name_and_trigger(): void
    {
        $this->reply(['name' => 'الفواتير', 'trigger' => 'فاتورة']);
        $this->reply(['name' => 'الترحيب', 'trigger' => 'مرحبا']);

        $this->getJson('/api/v1/automation/basic?search=فاتورة')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'الفواتير');
    }

    /** ردود منشأة أخرى لا تظهر. */
    public function test_replies_of_another_organization_are_hidden(): void
    {
        $this->reply(['organization_id' => $this->organization->id + 999, 'name' => 'غريب']);

        $this->getJson('/api/v1/automation/basic')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_it_shows_one_reply(): void
    {
        $reply = $this->reply();

        $this->getJson('/api/v1/automation/basic/' . $reply->uuid)
            ->assertOk()
            ->assertJsonPath('data.name', 'ترحيب')
            ->assertJsonPath('data.response', 'أهلاً بك')
            ->assertJsonPath('data.response_type', 'text');
    }

    public function test_an_unknown_uuid_is_a_404(): void
    {
        $this->getJson('/api/v1/automation/basic/' . Str::uuid())->assertStatus(404);
    }

    // ------------------------------------------------- الكتابة

    public function test_it_creates_a_reply(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.name', 'ترحيب')
            ->assertJsonPath('data.response', 'أهلاً بك');

        $row = AutoReply::where('organization_id', $this->organization->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($this->owner->id, (int) $row->created_by);
        $this->assertSame('أهلاً بك', json_decode($row->metadata, true)['data']['text']);
    }

    public function test_it_updates_a_reply(): void
    {
        $reply = $this->reply();

        $this->putJson('/api/v1/automation/basic/' . $reply->uuid, $this->payload([
            'name' => 'ترحيب محدَّث',
            'response' => 'أهلاً وسهلاً',
        ]))->assertOk()->assertJsonPath('data.name', 'ترحيب محدَّث');

        $this->assertSame('أهلاً وسهلاً', json_decode($reply->fresh()->metadata, true)['data']['text']);
    }

    public function test_it_deletes_a_reply(): void
    {
        $reply = $this->reply();

        $this->deleteJson('/api/v1/automation/basic/' . $reply->uuid)->assertOk();

        // حذف ناعم: يختفي عن القائمة ويبقى صفّه
        $this->assertNotNull($reply->fresh()->deleted_at);
        $this->getJson('/api/v1/automation/basic')->assertJsonCount(0, 'data.items');
    }

    /** ولا يُعدَّل ردّ منشأة أخرى ولو عُرف معرّفه. */
    public function test_a_reply_of_another_organization_cannot_be_updated(): void
    {
        $stranger = $this->reply(['organization_id' => $this->organization->id + 999]);

        $this->putJson('/api/v1/automation/basic/' . $stranger->uuid, $this->payload())
            ->assertStatus(404);
    }

    // ------------------------------------------------- التحقّق

    public function test_a_missing_trigger_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/automation/basic', $this->payload(['trigger' => '']));

        $response->assertStatus(400);
        $this->assertArrayHasKey('trigger', $response->json('errors'));
    }

    public function test_an_unknown_match_criteria_is_rejected(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload(['match_criteria' => 'regex']))
            ->assertStatus(400);
    }

    /** الصورة والصوت يحتاجان رفع ملف، وليسا في هذه النسخة. */
    public function test_an_unsupported_response_type_is_rejected(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload(['response_type' => 'image']))
            ->assertStatus(400);
    }

    // ------------------------------------------------- الصلاحية

    public function test_an_agent_cannot_create_a_reply(): void
    {
        $this->actAs($this->member('agent'));

        $this->postJson('/api/v1/automation/basic', $this->payload())->assertStatus(403);
    }

    /** لكنه يقرأها: الردود الجاهزة تُعرض له في المحادثة. */
    public function test_an_agent_can_list_replies(): void
    {
        $this->reply();
        $this->actAs($this->member('agent'));

        $this->getJson('/api/v1/automation/basic')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_a_manager_can_create_a_reply(): void
    {
        $this->actAs($this->member('manager'));

        $this->postJson('/api/v1/automation/basic', $this->payload())->assertOk();
    }
}
