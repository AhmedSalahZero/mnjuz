<?php

namespace Tests\Feature\MobileApi;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\WazSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تذاكر الدعم من التطبيق.
 *
 * ليست تذاكر المحادثات: تلك (`chat_tickets`) حالةُ محادثةِ عميلٍ وإسنادُها
 * لموظّف. وهذه (`tickets`) طلبٌ يفتحه صاحب الحساب لفريق دعمنا، ويُفتح معه
 * في واز أعمال حيث يعمل الفريق فعلاً.
 */
class MobileSupportTicketTest extends MobileApiTestCase
{
    private function category(string $name = 'عامّ'): TicketCategory
    {
        return TicketCategory::create(['name' => $name]);
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'reference' => 'SUP-' . random_int(100000, 999999),
            'user_id' => $this->owner->id,
            'category_id' => $this->category()->id,
            'subject' => 'الرسائل لا تصل',
            'message' => 'جرّبنا الإرسال ولم يصل شيء',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    // ------------------------------------------------- الجلب

    public function test_it_lists_my_tickets(): void
    {
        $ticket = $this->ticket();

        $this->getJson('/api/v1/support-tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.uuid', (string) $ticket->uuid)
            ->assertJsonPath('data.items.0.subject', 'الرسائل لا تصل')
            ->assertJsonPath('data.items.0.status', 'open');
    }

    /** تذاكر مستخدم آخر لا تظهر ولو كان في المنشأة نفسها. */
    public function test_tickets_of_another_user_are_hidden(): void
    {
        $other = $this->member('manager');
        $this->ticket(['user_id' => $other->id, 'subject' => 'تذكرة غيري']);

        $this->getJson('/api/v1/support-tickets')->assertOk()->assertJsonCount(0, 'data.items');
    }

    /** ومعها ما تحتاجه الشاشة: الفئات والحالات والأولويات. */
    public function test_the_list_carries_the_dropdown_options(): void
    {
        $this->category('فوترة');

        $data = $this->getJson('/api/v1/support-tickets')->assertOk()->json('data');

        $this->assertContains('فوترة', array_column($data['categories'], 'name'));
        $this->assertSame(['open', 'pending', 'resolved', 'closed'], $data['statuses']);
        $this->assertSame(['critical', 'high', 'medium', 'low'], $data['priorities']);
    }

    /** وراية واز: قائمة الدعم قد تكون غير متاحة الآن. */
    public function test_the_list_reports_whether_support_is_reachable(): void
    {
        $data = $this->getJson('/api/v1/support-tickets')->assertOk()->json('data');

        $this->assertArrayHasKey('waz_available', $data);
        $this->assertArrayHasKey('waz_tickets', $data);
        $this->assertFalse($data['waz_available'], 'واز غير مهيّأة في الاختبارات');
        $this->assertSame([], $data['waz_tickets']);
    }

    public function test_the_list_is_paginated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->ticket(['subject' => 'تذكرة ' . $i]);
        }

        $this->getJson('/api/v1/support-tickets?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    // ------------------------------------------------- تذكرة واحدة

    public function test_it_shows_one_ticket_with_its_comments(): void
    {
        $ticket = $this->ticket();
        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->owner->id,
            'message' => 'أي جديد؟',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/support-tickets/' . $ticket->uuid)->assertOk();

        $response->assertJsonPath('data.uuid', (string) $ticket->uuid)
            ->assertJsonPath('data.comments.0.message', 'أي جديد؟')
            ->assertJsonPath('data.comments.0.user.is_me', true);
    }

    public function test_opening_a_ticket_marks_its_comments_as_read(): void
    {
        $ticket = $this->ticket();
        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->owner->id,
            'message' => 'ردّ الدعم',
            'seen' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/support-tickets/' . $ticket->uuid)->assertOk();

        $this->assertSame(1, (int) DB::table('ticket_comments')->where('ticket_id', $ticket->id)->value('seen'));
    }

    /** تذكرة غيري: 404 لا 403 — كي لا يُستدلّ على وجودها. */
    public function test_another_users_ticket_is_a_404(): void
    {
        $other = $this->member('manager');
        $ticket = $this->ticket(['user_id' => $other->id]);

        $this->getJson('/api/v1/support-tickets/' . $ticket->uuid)->assertStatus(404);
    }

    public function test_an_unknown_uuid_is_a_404(): void
    {
        $this->getJson('/api/v1/support-tickets/' . Str::uuid())->assertStatus(404);
    }

    // ------------------------------------------------- الإنشاء

    public function test_it_creates_a_ticket(): void
    {
        $category = $this->category('فوترة');

        $response = $this->postJson('/api/v1/support-tickets', [
            'category' => $category->id,
            'subject' => 'فاتورة غير صحيحة',
            'message' => 'المبلغ المخصوم أكبر من المتوقّع',
        ])->assertOk();

        $response->assertJsonPath('data.subject', 'فاتورة غير صحيحة')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.category.name', 'فوترة');

        $row = Ticket::where('user_id', $this->owner->id)->first();

        $this->assertNotNull($row);
        $this->assertSame('المبلغ المخصوم أكبر من المتوقّع', $row->message);
        $this->assertStringStartsWith('SUP-', $row->reference);
    }

    /** ويُخبر التطبيق هل وصلت الدعم أم تأخّرت مزامنتها. */
    public function test_the_response_says_whether_support_received_it(): void
    {
        $category = $this->category();

        $this->postJson('/api/v1/support-tickets', [
            'category' => $category->id,
            'subject' => 'استفسار',
            'message' => 'سؤال عن الباقة',
        ])->assertOk()->assertJsonPath('data.synced_with_support', true);
    }

    /** تعذّر الوصول إلى واز لا يُلغي التذكرة — تُحفظ ويُنبَّه العميل. */
    public function test_a_failing_sync_still_saves_the_ticket(): void
    {
        $category = $this->category();

        $this->mock(WazSyncService::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('companyId')->andReturn(1);
            $mock->shouldReceive('ticketsFor')->andReturn([]);
            $mock->shouldReceive('syncTicket')->andThrow(
                new \App\Exceptions\WazBusinessException('لا اتصال')
            );
        });

        $this->postJson('/api/v1/support-tickets', [
            'category' => $category->id,
            'subject' => 'عطل',
            'message' => 'تفاصيل العطل',
        ])->assertOk()->assertJsonPath('data.synced_with_support', false);

        $this->assertSame(1, Ticket::where('user_id', $this->owner->id)->count());
    }

    public function test_a_missing_field_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/support-tickets', ['subject' => 'بلا رسالة']);

        $response->assertStatus(400);
        $this->assertArrayHasKey('message', $response->json('errors'));
        $this->assertArrayHasKey('category', $response->json('errors'));
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $this->postJson('/api/v1/support-tickets', [
            'category' => 999999,
            'subject' => 'عنوان',
            'message' => 'نصّ',
        ])->assertStatus(400);
    }

    /** والموظّف يفتح تذكرة كذلك: الدعم ليس حكراً على المالك. */
    public function test_an_agent_can_open_a_ticket(): void
    {
        $category = $this->category();
        $agent = $this->member('agent');
        $this->actAs($agent);

        $this->postJson('/api/v1/support-tickets', [
            'category' => $category->id,
            'subject' => 'سؤال',
            'message' => 'تفاصيل',
        ])->assertOk();

        $this->assertSame(1, Ticket::where('user_id', $agent->id)->count());
    }

    // ------------------------------------------------- التعليقات

    public function test_it_adds_a_comment(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/comment', [
            'message' => 'ما زالت المشكلة قائمة',
        ])->assertOk()->assertJsonPath('data.comments.0.message', 'ما زالت المشكلة قائمة');

        $this->assertSame(1, TicketComment::where('ticket_id', $ticket->id)->count());
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/comment', ['message' => ''])
            ->assertStatus(400);

        $this->assertSame(0, TicketComment::where('ticket_id', $ticket->id)->count());
    }

    /** ولا يعلّق أحد على تذكرة غيره. */
    public function test_commenting_on_another_users_ticket_is_a_404(): void
    {
        $other = $this->member('manager');
        $ticket = $this->ticket(['user_id' => $other->id]);

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/comment', ['message' => 'تطفّل'])
            ->assertStatus(404);

        $this->assertSame(0, TicketComment::where('ticket_id', $ticket->id)->count());
    }

    // ------------------------------------------------- الحالة والأولوية

    public function test_it_changes_the_status(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/status', ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertSame('closed', $ticket->fresh()->status);
    }

    public function test_it_changes_the_priority(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/priority', ['priority' => 'high'])
            ->assertOk()
            ->assertJsonPath('data.priority', 'high');

        $this->assertSame('high', $ticket->fresh()->priority);
    }

    /** قيمة خارج المسموح تُردّ — والعمود enum فتُسقطها القاعدة لولا التحقّق. */
    public function test_an_unknown_status_is_rejected(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/status', ['status' => 'مؤرشفة'])
            ->assertStatus(400);

        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_an_unknown_priority_is_rejected(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/priority', ['priority' => 'عاجل جداً'])
            ->assertStatus(400);

        $this->assertNull($ticket->fresh()->priority);
    }

    public function test_changing_another_users_ticket_is_a_404(): void
    {
        $other = $this->member('manager');
        $ticket = $this->ticket(['user_id' => $other->id, 'status' => 'open']);

        $this->postJson('/api/v1/support-tickets/' . $ticket->uuid . '/status', ['status' => 'closed'])
            ->assertStatus(404);

        $this->assertSame('open', $ticket->fresh()->status);
    }

    // ------------------------------------------------- الوصول

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/support-tickets')->assertStatus(401);
        $this->postJson('/api/v1/support-tickets', [])->assertStatus(401);
    }

    /** ولا تختلط بتذاكر المحادثات. */
    public function test_it_is_a_different_endpoint_from_chat_tickets(): void
    {
        $this->ticket();

        $chat = $this->getJson('/api/v1/tickets')->assertOk()->json('data');

        $this->assertArrayNotHasKey('categories', $chat, '/tickets هي تذاكر المحادثات');
    }
}
