<?php

namespace Tests\Feature\ConversationCycle;

use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * ميزانية صندوق الوارد: ما يُحسب حين ينتقل الموظّف بين المحادثات.
 *
 * كان فتح محادثة — طلبٌ جزئي يطلب سبعة props — يحسب الصفحة كلها ويرميها:
 * عدّ كل رسائل المنشأة (مئات الآلاف عند العملاء الكبار)، ومسح كل جهات
 * اتصالها بحثاً عن تذاكر ناقصة، والقوالب والحقول والإعدادات، ونحو عشرين
 * استعلاماً للـ shared props. على بيانات عميلٍ بنصف مليون رسالة: 65 استعلاماً
 * و239ms صارت 26 و54ms. هذه الاختبارات تمنع عودتها بصمت.
 */
class InboxQueryBudgetTest extends CycleTestCase
{
    private const SWITCH_PROPS = 'contact,chatThread,hasMoreMessages,nextPage,ticket,unreadMessages,flash';

    /** حدّ أعلى بهامش فوق المقيس (26 على بيانات الإنتاج المحلية). */
    private const SWITCH_QUERY_BUDGET = 32;

    private function enableTickets(): void
    {
        $metadata = json_decode($this->organization->metadata, true);
        $metadata['tickets'] = ['active' => true, 'allow_agents_to_view_all_chats' => true, 'auto_assignment' => false];
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    private function message(Contact $contact, array $attributes = [], bool $logged = true): Chat
    {
        $chat = $this->chat($contact, $attributes);

        if ($logged) {
            ChatLog::insert(['contact_id' => $contact->id, 'entity_type' => 'chat', 'entity_id' => $chat->id, 'created_at' => now()]);
        }

        return $chat;
    }

    private function asOwner(): void
    {
        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    /** @return array{0: TestResponse, 1: array<int, string>} */
    private function capture(callable $request): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $request();

        return [$response, $queries];
    }

    private function inertiaHeaders(?string $only = null): array
    {
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Requested-With' => 'XMLHttpRequest'];

        if ($only !== null) {
            $headers += ['X-Inertia-Partial-Component' => 'User/Chat/Index', 'X-Inertia-Partial-Data' => $only];
        }

        return $headers;
    }

    /** عدّ حدّ الباقة بعينه (لا عدّاد غير المقروء — ذاك مفهرس ويبقى). */
    private function countsAllMessages(array $queries): bool
    {
        return collect($queries)->contains(fn (string $sql) => $sql === 'select count(*) as aggregate from `chats` where `organization_id` = ? and `deleted_at` is null');
    }

    // ───────────────────── فتح محادثة ─────────────────────

    public function test_switching_conversations_computes_only_what_the_screen_asked_for(): void
    {
        $this->enableTickets();
        $contact = $this->contact();
        $this->message($contact, ['type' => 'inbound', 'status' => 'delivered']);
        ChatTicket::create(['contact_id' => $contact->id, 'status' => 'open', 'is_latest' => true]);
        $this->asOwner();

        [$response, $queries] = $this->capture(fn () => $this->get('/chats/' . $contact->uuid, $this->inertiaHeaders(self::SWITCH_PROPS)));

        $response->assertOk();
        $expected = explode(',', self::SWITCH_PROPS);
        $returned = array_keys($response->json('props'));
        sort($expected);
        sort($returned);
        $this->assertSame($expected, $returned);
        $this->assertNotEmpty($response->json('props.chatThread'));

        $this->assertLessThanOrEqual(self::SWITCH_QUERY_BUDGET, count($queries), "opening a conversation ran " . count($queries) . " queries:\n" . implode("\n", $queries));
        $this->assertFalse($this->countsAllMessages($queries), 'opening a conversation counted every message of the organization');
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'from `templates`')), 'templates were loaded for a conversation switch');
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'not exists (select 1 from `chat_tickets`')), 'the ticket backfill scanned every contact');
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'from `languages`')), 'shared props were computed for a partial reload');
    }

    /** التحميل الكامل يبقى كما كان: كل prop موجود ومحسوب. */
    public function test_a_full_load_still_carries_every_prop(): void
    {
        $contact = $this->contact();
        $this->message($contact, ['type' => 'inbound', 'status' => 'delivered']);
        $this->asOwner();

        $props = $this->get('/chats/' . $contact->uuid)->assertOk()->viewData('page')['props'];

        foreach (['templates', 'fields', 'locationSettings', 'simpleForm', 'timezone', 'isChatLimitReached', 'contactCategoriesEnabled', 'connectedChannels', 'settings', 'addon', 'organization', 'organizations', 'config', 'languages', 'isRtl', 'tfa', 'broadcast', 'settingsModuleWorkingHours', 'settingsModuleWebChat'] as $key) {
            $this->assertArrayHasKey($key, $props, $key);
            $this->assertNotInstanceOf(\Closure::class, $props[$key], "{$key} was sent unresolved");
        }
        $this->assertSame((string) $this->organization->uuid, $props['organization']['uuid']);
        $this->assertFalse($props['isChatLimitReached']);
        $this->assertArrayHasKey('features', $props['organization']['plan']);
    }

    // ───────────────────── حدّ الرسائل ─────────────────────

    public function test_an_unlimited_plan_never_counts_messages(): void
    {
        $this->asOwner();

        [, $queries] = $this->capture(fn () => $this->get('/chats')->assertOk());

        $this->assertFalse($this->countsAllMessages($queries));
        $this->assertFalse(SubscriptionService::isSubscriptionFeatureLimitReached($this->organization->id, 'message_limit'));
    }

    /** باقة محدودة: العدّ مرّة في الدقيقة، والحدّ يُحترم. */
    public function test_a_limited_plan_counts_messages_at_most_once_a_minute(): void
    {
        Subscription::where('organization_id', $this->organization->id)->first()->plan->update(['metadata' => json_encode(['message_limit' => 2])]);
        $contact = $this->contact();
        $this->message($contact);

        [, $first] = $this->capture(fn () => SubscriptionService::isSubscriptionFeatureLimitReached($this->organization->id, 'message_limit'));
        $this->message($contact);
        [$reached, $second] = $this->capture(fn () => SubscriptionService::isSubscriptionFeatureLimitReached($this->organization->id, 'message_limit'));

        $this->assertTrue($this->countsAllMessages($first));
        $this->assertFalse($this->countsAllMessages($second), 'counted again within the minute');
        $this->assertFalse($reached, 'within the minute the stored count (1) is used');

        Cache::forget(SubscriptionService::messageUsageKey($this->organization->id));
        $this->assertTrue(SubscriptionService::isSubscriptionFeatureLimitReached($this->organization->id, 'message_limit'));
    }

    // ───────────────────── نبض الحضور ─────────────────────

    public function test_the_heartbeat_does_not_build_the_shared_page_data(): void
    {
        $this->asOwner();

        [$response, $queries] = $this->capture(fn () => $this->postJson('/performance/heartbeat', ['visible' => true]));

        $response->assertOk();
        foreach (['from `languages`', 'from `settings` where `key` in', 'select * from `addons` where `name` = ? limit 1'] as $shared) {
            $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, $shared)), "heartbeat ran a shared-props query ({$shared})");
        }
        $this->assertLessThanOrEqual(15, count($queries), implode("\n", $queries));
    }

    // ───────────────────── آخر رسالة في القائمة ─────────────────────

    /**
     * last_chat_id يُرجع ما كان lastChat() (ofMany) يُرجعه بالضبط: الأحدث،
     * والأكبر معرّفاً عند تساوي الوقت، متخطّياً المحذوف والتفاعل وما لا سجلّ له.
     */
    public function test_the_list_preview_is_the_same_message_the_relation_returns(): void
    {
        $at = now()->subHour()->startOfSecond();
        $cases = [];

        $tie = $this->contact(['phone' => '+966500000101']);
        $this->message($tie, ['created_at' => $at]);
        $this->message($tie, ['created_at' => $at]);
        $cases[] = $tie;

        $reaction = $this->contact(['phone' => '+966500000102']);
        $this->message($reaction, ['created_at' => $at->copy()->subMinute()]);
        $this->message($reaction, ['created_at' => $at, 'metadata' => json_encode(['type' => 'reaction', 'reaction' => ['emoji' => '👍']])]);
        $cases[] = $reaction;

        $deleted = $this->contact(['phone' => '+966500000103']);
        $this->message($deleted, ['created_at' => $at->copy()->subMinute()]);
        $this->message($deleted, ['created_at' => $at, 'deleted_at' => now()]);
        $cases[] = $deleted;

        $unlogged = $this->contact(['phone' => '+966500000104']);
        $this->message($unlogged, ['created_at' => $at->copy()->subMinute()]);
        $this->message($unlogged, ['created_at' => $at], logged: false);
        $cases[] = $unlogged;

        $onlyReaction = $this->contact(['phone' => '+966500000105']);
        $this->message($onlyReaction, ['created_at' => $at, 'metadata' => json_encode(['type' => 'reaction', 'reaction' => ['emoji' => '❤️']])]);
        $cases[] = $onlyReaction;

        $listed = (new Contact)->contactsWithChatsOptimized($this->organization->id, perPage: 50)->getCollection()->keyBy('id');

        foreach ($cases as $i => $contact) {
            $expected = Contact::with('lastChat')->find($contact->id)->lastChat;
            $actual = $listed[$contact->id]->lastChat;

            $this->assertTrue($listed[$contact->id]->relationLoaded('lastChat'), "case {$i}: relation not set");
            $this->assertSame($expected?->id, $actual?->id, "case {$i}");
        }

        $this->assertNull($listed[$onlyReaction->id]->lastChat);
        $this->assertArrayNotHasKey('last_chat_id', $listed[$tie->id]->toArray());
    }

    // ───────────────────── استعلام القائمة وعدّها ─────────────────────

    /**
     * القائمة تسمّي فهارسها (FORCE INDEX): بلا ذلك اختار المُحسِّن على الإنتاج
     * تقاطع فهرسين يقرأ كل جهات المنصّة. والاسم الخاطئ خطأ SQL يُسقط الصفحة،
     * فكل فهرس مسمّى يجب أن يبقى في المخطّط بعد الترحيلات.
     */
    public function test_the_list_names_indexes_that_exist(): void
    {
        $contact = $this->contact(['latest_chat_created_at' => now()]);
        $this->message($contact);

        [, $queries] = $this->capture(fn () => (new Contact)->contactsWithChatsOptimized($this->organization->id, perPage: 50));

        $rows = collect($queries)->first(fn ($sql) => str_starts_with($sql, 'select `contacts`.`id`, `contacts`.`uuid`'));
        $this->assertNotNull($rows);
        $this->assertStringContainsString('force index (' . Contact::CHAT_LIST_INDEXES . ')', $rows);

        $existing = collect(DB::select('SHOW INDEX FROM contacts'))->pluck('Key_name')->unique();
        foreach (array_map('trim', explode(',', Contact::CHAT_LIST_INDEXES)) as $index) {
            $this->assertContains($index, $existing, "index {$index} is named by the chat list but missing from the schema");
        }

        // PRIMARY يسمح بالبدء من chat_tickets (بلا organization_id): فلتر «مفتوحة»
        // مرّ على 442 ألف تذكرة المنصّة كلها في نسخة الإنتاج المحلية.
        $this->assertStringNotContainsString('PRIMARY', Contact::CHAT_LIST_INDEXES);

        // آخر رسالة لا تُحسب في استعلام الصفوف: subquery في الـ select يُحسب
        // قبل الترتيب لكل صفّ حين يرتّب MySQL نتيجة ربط — 31 ثانية لـ 42 ألف
        // جهة. تُحسب بعد الترقيم لصفوف الصفحة وحدها.
        $this->assertStringNotContainsString('last_chat', $rows);
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'AS last_chat_id') && str_contains($sql, '`contacts`.`id` in (')));
    }

    /** «مغلقة» تبقى كما هي اليوم بلا تقييد: تطابق قليلاً، والبدء من جهات المنشأة يمرّ عليها كلّها. */
    public function test_the_closed_filter_is_left_to_the_optimizer(): void
    {
        $this->enableTickets();
        $contact = $this->contact(['latest_chat_created_at' => now()]);
        ChatTicket::create(['contact_id' => $contact->id, 'status' => 'closed', 'is_latest' => true]);

        [, $queries] = $this->capture(fn () => (new Contact)->contactsWithChatsOptimized($this->organization->id, ticketingActive: true, ticketState: 'closed', perPage: 50));
        $rows = collect($queries)->first(fn ($sql) => str_starts_with($sql, 'select `contacts`.`id`, `contacts`.`uuid`'));

        $this->assertStringNotContainsString('force index', $rows);
    }

    /**
     * العدّ بلا ربط التذاكر حين لا يغيّره: لكل جهة اتصال تذكرة «أحدث» واحدة
     * على الأكثر، فجهة بلا تذكرة أو بتذاكر قديمة تُعدّ مرّة. والفلاتر وقيد
     * الموظّف يبقيان على الربط لأنهما يغيّران العدد.
     */
    public function test_the_list_total_counts_every_contact_once_whatever_its_tickets(): void
    {
        $this->enableTickets();
        $agent = \App\Models\User::factory()->create(['role' => 'user']);
        \App\Models\Team::factory()->create(['user_id' => $agent->id, 'organization_id' => $this->organization->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        $make = function (string $phone, array $tickets) {
            $contact = $this->contact(['phone' => $phone, 'latest_chat_created_at' => now()]);
            // مباشرة لا عبر النموذج: created() يجعل كل تذكرة جديدة «الأحدث»،
            // والمطلوب هنا تذاكر قديمة بجانب الأحدث كما في الإنتاج.
            foreach ($tickets as $ticket) {
                DB::table('chat_tickets')->insert($ticket + ['contact_id' => $contact->id, 'assigned_to' => null, 'created_at' => now()]);
            }

            return $contact;
        };
        $make('+966500000201', []);
        $make('+966500000202', [['status' => 'open', 'is_latest' => true, 'assigned_to' => $agent->id]]);
        $make('+966500000203', [['status' => 'closed', 'is_latest' => false], ['status' => 'closed', 'is_latest' => false], ['status' => 'open', 'is_latest' => true]]);
        $make('+966500000204', [['status' => 'closed', 'is_latest' => true]]);

        $total = function (?string $state, string $role = 'owner', bool $viewAll = true) {
            $queries = [];
            DB::listen(function ($query) use (&$queries) {
                $queries[] = $query->sql;
            });
            $page = (new Contact)->contactsWithChatsOptimized(
                $this->organization->id,
                ticketingActive: true,
                ticketState: $state,
                role: $role,
                allowAgentsViewAllChats: $viewAll,
                perPage: 50,
            );
            $count = collect($queries)->first(fn ($sql) => str_starts_with($sql, 'select count(*) as aggregate from `contacts`'));
            $this->assertSame($page->total(), $page->getCollection()->count(), "state {$state}: total matches the rows");

            return [$page->total(), str_contains($count, 'join `chat_tickets`')];
        };

        $this->assertSame([4, false], $total(null), 'all: every contact once, counted without the join');
        $this->assertSame([2, true], $total('open'));
        $this->assertSame([1, true], $total('closed'));
        $this->assertSame([2, true], $total('unassigned'), 'unassigned: latest ticket with nobody on it');

        $this->actingAs($agent);
        $this->assertSame([1, true], $total(null, 'agent', false), 'an agent restricted to their own chats counts through the join');
    }

    /** والصفحة الكاملة تعرض المعاينة كما كانت (ContactListResource). */
    public function test_the_chat_list_still_shows_the_last_message(): void
    {
        $contact = $this->contact();
        $this->message($contact, ['created_at' => now()->subMinute(), 'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'الأقدم']])]);
        $this->message($contact, ['metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'الأحدث']])]);
        $this->asOwner();

        $rows = $this->get('/chats')->assertOk()->viewData('page')['props']['rows']['data'];
        $row = collect($rows)->firstWhere('id', $contact->id);

        $this->assertSame('الأحدث', json_decode($row['last_chat']['metadata'], true)['text']['body']);
    }
}
