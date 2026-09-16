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
            ->assertJsonPath('data.response_type', __('Text'));
    }

    // ------------------------------------------------- لغة الحقلين

    /**
     * `match_criteria` و`response_type` يظهران للمستخدم في التطبيق مباشرةً،
     * فكانا يصلان «exact match» و«text» بالإنجليزية وسط واجهة عربية.
     */
    public function test_the_two_displayed_fields_come_back_in_arabic(): void
    {
        $this->reply(['match_criteria' => 'contains']);

        $this->getJson('/api/v1/automation/basic', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.items.0.match_criteria', 'يحتوي')
            ->assertJsonPath('data.items.0.response_type', 'نص');
    }

    public function test_exact_match_comes_back_in_arabic(): void
    {
        $this->reply(['match_criteria' => 'exact match']);

        $this->getJson('/api/v1/automation/basic', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.items.0.match_criteria', 'تطابق تام');
    }

    /** وبالإنجليزية تسمية مقروءة كذلك، لا قيمة قاعدة البيانات الخام. */
    public function test_english_gets_a_readable_label_too(): void
    {
        $this->reply(['match_criteria' => 'contains']);

        $this->getJson('/api/v1/automation/basic', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.items.0.match_criteria', 'Contains')
            ->assertJsonPath('data.items.0.response_type', 'Text');
    }

    /** والعرض المفرد مثل القائمة. */
    public function test_a_single_reply_is_localised_too(): void
    {
        $reply = $this->reply(['match_criteria' => 'exact match']);

        $this->getJson('/api/v1/automation/basic/' . $reply->uuid, ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.match_criteria', 'تطابق تام')
            ->assertJsonPath('data.response_type', 'نص');
    }

    /** ردٌّ قديم نوعه صورة يبقى مفهوماً كذلك. */
    public function test_an_old_image_reply_shows_its_type_in_arabic(): void
    {
        $reply = $this->reply();
        $reply->metadata = json_encode(['type' => 'image', 'data' => []]);
        $reply->save();

        $this->getJson('/api/v1/automation/basic/' . $reply->uuid, ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.response_type', 'صورة')
            ->assertJsonPath('data.response', null);
    }

    // ------------------------------------------------- الحفظ يقبل ما أرسلناه

    /**
     * التطبيق يُعيد ما استلمه عند التعديل، فلو قبلنا المخزّن وحده لردّت
     * الترجمةُ حفظَه بخطأ 400 على قيمة نحن من أرسلناها إليه.
     */
    public function test_saving_accepts_the_arabic_label_it_received(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload([
            'match_criteria' => 'يحتوي',
            'response_type' => 'نص',
        ]), ['Accept-Language' => 'ar'])->assertOk();

        $this->assertSame('contains', AutoReply::where('organization_id', $this->organization->id)->value('match_criteria'));
    }

    public function test_saving_accepts_the_arabic_label_for_exact_match(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload([
            'match_criteria' => 'تطابق تام',
            'response_type' => 'نص',
        ]), ['Accept-Language' => 'ar'])->assertOk();

        $this->assertSame('exact match', AutoReply::where('organization_id', $this->organization->id)->value('match_criteria'));
    }

    /** والقيمة المخزّنة ما زالت مقبولة — عملاء قدامى لم يُحدَّثوا بعد. */
    public function test_saving_still_accepts_the_stored_value(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload([
            'match_criteria' => 'exact match',
            'response_type' => 'text',
        ]), ['Accept-Language' => 'ar'])->assertOk();

        $this->assertSame('exact match', AutoReply::where('organization_id', $this->organization->id)->value('match_criteria'));
    }

    /** وتسميتها الإنجليزية كذلك. */
    public function test_saving_accepts_the_english_label(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload([
            'match_criteria' => 'Contains',
            'response_type' => 'Text',
        ]), ['Accept-Language' => 'en'])->assertOk();

        $this->assertSame('contains', AutoReply::where('organization_id', $this->organization->id)->value('match_criteria'));
    }

    /** أمّا نوعٌ لا يدعمه التطبيق فيُردّ ولو أُرسل بالعربية. */
    public function test_an_arabic_label_for_an_unsupported_type_is_still_rejected(): void
    {
        $this->postJson('/api/v1/automation/basic', $this->payload([
            'response_type' => 'صورة',
        ]), ['Accept-Language' => 'ar'])->assertStatus(400);

        $this->assertSame(0, AutoReply::where('organization_id', $this->organization->id)->count());
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
