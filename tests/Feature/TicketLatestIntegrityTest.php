<?php

namespace Tests\Feature;

use App\Jobs\ProcessTicketAssignmentJob;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * تذكرة «أحدث» واحدة لكل جهة اتصال.
 *
 * العطل: أول رسالة من عميل جديد تُنشئ تذكرته في ProcessTicketAssignmentJob، وفي
 * اللحظة نفسها قد تُنشئ صفحةُ المحادثات تذكرة ثانية (ensureChatTicketsExist)
 * لأنها رأت العميل «بلا تذكرة». كانت تستعمل insertOrIgnore ظنّاً أن الجدول يمنع
 * التكرار، وهو لا يمنعه — فصار للعميل صفّان بـ is_latest = 1: يظهر مرتين في قائمة
 * المحادثات، ويعرض رأس المحادثة تذكرة أقدم (موظفاً وحالةً قديمين) بينما يعدّل
 * «Mark as closed» الأحدث فلا يتغيّر الزرّ.
 *
 * الإصلاح: قيد فريد في قاعدة البيانات، وترتيب في استعلام الرأس، وترتيب في
 * UPDATE الذي يحوّل العلم (القيد يُفحص صفّاً صفّاً).
 */
class TicketLatestIntegrityTest extends CycleTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_01_120000_enforce_single_latest_chat_ticket.php';

    // ------------------------------------------------- التجهيز

    private function ticket(Contact $contact, array $attributes = []): ChatTicket
    {
        return ChatTicket::withoutEvents(fn () => ChatTicket::create(array_merge([
            'contact_id' => $contact->id,
            'assigned_to' => null,
            'status' => 'open',
            'is_latest' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes)));
    }

    private function enableTickets(): void
    {
        $metadata = json_decode($this->organization->metadata, true);
        $metadata['tickets'] = ['active' => true, 'allow_agents_to_view_all_chats' => true, 'auto_assignment' => false];
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    /** @return list<int> معرّفات الصفوف التي تحمل is_latest لهذه الجهة. */
    private function latestIds(Contact $contact): array
    {
        return DB::table('chat_tickets')
            ->where('contact_id', $contact->id)
            ->where('is_latest', 1)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function agent(): User
    {
        $user = User::factory()->create(['role' => 'user']);
        Team::factory()->create(['user_id' => $user->id, 'organization_id' => $this->organization->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        return $user;
    }

    private function openChat(Contact $contact): array
    {
        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        $response = $this->get('/chats/' . $contact->uuid);
        $response->assertStatus(200);

        return $response->viewData('page')['props'];
    }

    // ------------------------------------------------- القيد

    public function test_the_constraint_exists_and_is_unique(): void
    {
        $this->assertTrue(Schema::hasColumn('chat_tickets', 'latest_contact_id'));

        $index = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'chat_tickets')
            ->where('index_name', 'uq_chat_tickets_single_latest')
            ->first();

        $this->assertNotNull($index, 'القيد الفريد غير موجود.');
        $this->assertSame(0, (int) $index->NON_UNIQUE);
    }

    public function test_a_second_latest_ticket_for_the_same_contact_is_rejected(): void
    {
        $contact = $this->contact();
        $this->ticket($contact, ['is_latest' => true]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->ticket($contact, ['is_latest' => true]);
    }

    /** التاريخ يتراكم بلا حدّ: القيد على «الأحدث» وحده. */
    public function test_any_number_of_older_tickets_is_allowed(): void
    {
        $contact = $this->contact();
        foreach (range(1, 4) as $_) {
            $this->ticket($contact, ['is_latest' => false]);
        }
        $latest = $this->ticket($contact, ['is_latest' => true]);

        $this->assertSame([$latest->id], $this->latestIds($contact));
        $this->assertSame(5, DB::table('chat_tickets')->where('contact_id', $contact->id)->count());
    }

    public function test_each_contact_can_have_its_own_latest_ticket(): void
    {
        $a = $this->contact(['phone' => '+966500000041']);
        $b = $this->contact(['phone' => '+966500000042']);

        $ta = $this->ticket($a, ['is_latest' => true]);
        $tb = $this->ticket($b, ['is_latest' => true]);

        $this->assertSame([$ta->id], $this->latestIds($a));
        $this->assertSame([$tb->id], $this->latestIds($b));
    }

    /** العمود المحسوب لا يظهر في JSON ولا في حمولة الصفحة. */
    public function test_the_generated_column_is_not_exposed(): void
    {
        $contact = $this->contact();
        $ticket = $this->ticket($contact, ['is_latest' => true]);

        $this->assertArrayNotHasKey('latest_contact_id', ChatTicket::find($ticket->id)->toArray());
    }

    /** insertOrIgnore يتخطّى الصفّ المخالف للقيد — وهو ما كانت ensureChatTicketsExist تظنّه. */
    public function test_insert_or_ignore_skips_a_contact_that_already_has_a_latest_ticket(): void
    {
        $contact = $this->contact();
        $existing = $this->ticket($contact, ['is_latest' => true]);

        $inserted = DB::table('chat_tickets')->insertOrIgnore([[
            'contact_id' => $contact->id,
            'assigned_to' => null,
            'status' => 'open',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->assertSame(0, $inserted, 'أُدرج صفّ ثانٍ رغم القيد.');
        $this->assertSame([$existing->id], $this->latestIds($contact));
    }

    /** وفي دفعة واحدة: يُتخطّى المتعارض وحده ويُدرج الباقي. */
    public function test_a_batch_skips_only_the_conflicting_row(): void
    {
        $taken = $this->contact(['phone' => '+966500000051']);
        $free = $this->contact(['phone' => '+966500000052']);
        $this->ticket($taken, ['is_latest' => true]);

        $row = fn (Contact $c) => [
            'contact_id' => $c->id, 'assigned_to' => null, 'status' => 'open',
            'is_latest' => true, 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('chat_tickets')->insertOrIgnore([$row($taken), $row($free)]);

        $this->assertCount(1, $this->latestIds($taken));
        $this->assertCount(1, $this->latestIds($free), 'الصفّ السليم لم يُدرج بسبب جاره المتعارض.');
    }

    // ------------------------------------------------- تحويل العلم

    public function test_creating_a_newer_ticket_makes_it_the_latest(): void
    {
        $contact = $this->contact();
        $old = ChatTicket::create(['contact_id' => $contact->id, 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        $new = ChatTicket::create(['contact_id' => $contact->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([$new->id], $this->latestIds($contact), 'الأحدث يجب أن يحمل العلم وحده.');
        $this->assertNotContains($old->id, $this->latestIds($contact));
    }

    /**
     * الاتجاه الذي يكسره القيد بلا ترتيب: ترقية صفّ أقدم بينما الأحدث يحمل العلم.
     * UPDATE بلا ORDER BY يعالج الأقدم أولاً فيرى صفّين بـ 1 ويسقط بـ Duplicate entry.
     */
    public function test_promoting_an_older_ticket_does_not_trip_the_constraint(): void
    {
        $contact = $this->contact();
        $older = $this->ticket($contact, ['is_latest' => false]);
        $newer = $this->ticket($contact, ['is_latest' => true]);

        ChatTicket::syncLatestFlag($contact->id, $older->id);

        $this->assertSame([$older->id], $this->latestIds($contact));
        $this->assertNotContains($newer->id, $this->latestIds($contact));
    }

    public function test_promoting_the_newer_ticket_works_too(): void
    {
        $contact = $this->contact();
        $older = $this->ticket($contact, ['is_latest' => true]);
        $newer = $this->ticket($contact, ['is_latest' => false]);

        ChatTicket::syncLatestFlag($contact->id, $newer->id);

        $this->assertSame([$newer->id], $this->latestIds($contact));
    }

    public function test_syncing_the_current_latest_changes_nothing(): void
    {
        $contact = $this->contact();
        $this->ticket($contact, ['is_latest' => false]);
        $latest = $this->ticket($contact, ['is_latest' => true]);

        ChatTicket::syncLatestFlag($contact->id, $latest->id);

        $this->assertSame([$latest->id], $this->latestIds($contact));
    }

    public function test_syncing_one_contact_leaves_others_alone(): void
    {
        $a = $this->contact(['phone' => '+966500000061']);
        $b = $this->contact(['phone' => '+966500000062']);
        $aOld = $this->ticket($a, ['is_latest' => false]);
        $aNew = $this->ticket($a, ['is_latest' => true]);
        $bOnly = $this->ticket($b, ['is_latest' => true]);

        ChatTicket::syncLatestFlag($a->id, $aOld->id);

        $this->assertSame([$aOld->id], $this->latestIds($a));
        $this->assertSame([$bOnly->id], $this->latestIds($b), 'تحويل علم عميل مسّ عميلاً آخر.');
    }

    /** مسار deleting: حذف الأحدث يُرقّي ما قبله (وهو الاتجاه الذي يكسره القيد بلا ترتيب). */
    public function test_deleting_the_latest_ticket_promotes_the_previous_one(): void
    {
        $contact = $this->contact();
        $older = ChatTicket::create(['contact_id' => $contact->id, 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        $newer = ChatTicket::create(['contact_id' => $contact->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$newer->id], $this->latestIds($contact));

        $newer->refresh()->delete();

        $this->assertSame([$older->id], $this->latestIds($contact), 'لم يُرقَّ الصفّ السابق بعد حذف الأحدث.');
    }

    public function test_deleting_an_old_ticket_keeps_the_latest(): void
    {
        $contact = $this->contact();
        $older = ChatTicket::create(['contact_id' => $contact->id, 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
        $newer = ChatTicket::create(['contact_id' => $contact->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        $older->refresh()->delete();

        $this->assertSame([$newer->id], $this->latestIds($contact));
    }

    // ------------------------------------------------- تنظيف ما تراكم

    /**
     * جدول بلا القيد: الجدول الحقيقي يمنع بذور التكرار التي نريد اختبار تنظيفها.
     *
     * عاديّ لا TEMPORARY: التنظيف يضمّ الجدول إلى نفسه، وMySQL يرفض ذلك على
     * الجداول المؤقتة (Can't reopen table). وإنشاؤه عبر اتصال منفصل لا عبر اتصال
     * الاختبار: CREATE TABLE يُنهي المعاملة الجارية ضمنياً، فيتسرّب ما أنشأه
     * الاختبار إلى ما بعده (انظر تعليق CycleTestCase::refreshTestDatabase).
     * البذور نفسها تُكتب على اتصال الاختبار فتُلغى مع معاملته.
     */
    private function cleanupTable(): string
    {
        config(['database.connections.ddl' => config('database.connections.' . config('database.default'))]);

        DB::connection('ddl')->statement('
            CREATE TABLE IF NOT EXISTS tmp_tickets_cleanup (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                contact_id INT NOT NULL,
                assigned_to INT NULL,
                status VARCHAR(128) NOT NULL DEFAULT "open",
                is_latest TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ');

        // بقايا تشغيلٍ سابق انقطع قبل التراجع.
        DB::table('tmp_tickets_cleanup')->delete();

        return 'tmp_tickets_cleanup';
    }

    private function seedTicket(string $table, int $contactId, array $attributes): int
    {
        return (int) DB::table($table)->insertGetId(array_merge([
            'contact_id' => $contactId,
            'assigned_to' => null,
            'status' => 'open',
            'is_latest' => 1,
            'created_at' => '2026-08-16 13:05:01',
            'updated_at' => '2026-08-16 13:05:01',
        ], $attributes));
    }

    private function migration(): object
    {
        $migrator = app('migrator');
        $resolve = new \ReflectionMethod($migrator, 'resolvePath');
        $resolve->setAccessible(true);

        return $resolve->invoke($migrator, base_path(self::MIGRATION));
    }

    /** @return list<int> */
    private function survivors(string $table, int $contactId): array
    {
        return DB::table($table)->where('contact_id', $contactId)->where('is_latest', 1)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** الإسناد الآلي (job) والصفحة (بلا موظف) يولدان في الثانية نفسها: يبقى من له موظف. */
    public function test_cleanup_keeps_the_assigned_ticket_when_they_tie(): void
    {
        $t = $this->cleanupTable();
        $assigned = $this->seedTicket($t, 1, ['assigned_to' => 14]);
        $this->seedTicket($t, 1, ['assigned_to' => null]);              // أحدث id، بلا موظف

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame([$assigned], $this->survivors($t, 1));
    }

    public function test_cleanup_keeps_the_assigned_one_whichever_id_it_has(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, ['assigned_to' => null]);
        $assigned = $this->seedTicket($t, 1, ['assigned_to' => 14]);    // أحدث id وله موظف

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame([$assigned], $this->survivors($t, 1));
    }

    public function test_cleanup_keeps_the_newest_id_when_both_are_unassigned(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, []);
        $newest = $this->seedTicket($t, 1, []);

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame([$newest], $this->survivors($t, 1));
    }

    public function test_cleanup_keeps_the_newest_id_when_both_are_assigned(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, ['assigned_to' => 5]);
        $newest = $this->seedTicket($t, 1, ['assigned_to' => 6]);

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame([$newest], $this->survivors($t, 1));
    }

    /**
     * حالة حقيقية (العميل 837136): الأقدم مغلقة ومسندة، والأحدث أُعيد فتحها لاحقاً
     * فصارت مفتوحة بلا موظف. الحالة الحيّة في الأحدث تحديثاً — لا في صاحبة الموظف.
     */
    public function test_cleanup_prefers_the_ticket_reopened_later_over_the_assigned_one(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, ['status' => 'closed', 'assigned_to' => 399, 'updated_at' => '2026-08-16 13:05:01']);
        $reopened = $this->seedTicket($t, 1, ['status' => 'open', 'assigned_to' => null, 'updated_at' => '2026-09-09 13:47:34']);

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame([$reopened], $this->survivors($t, 1));
    }

    /** الأحدث تحديثاً يغلب حتى لو كان أقدم id. */
    public function test_cleanup_prefers_the_most_recently_updated_even_with_an_older_id(): void
    {
        $t = $this->cleanupTable();
        $recentlyUpdated = $this->seedTicket($t, 1, ['updated_at' => '2026-10-01 10:00:00']);
        $this->seedTicket($t, 1, ['updated_at' => '2026-08-01 10:00:00']);

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame([$recentlyUpdated], $this->survivors($t, 1));
    }

    public function test_cleanup_falls_back_to_created_at_when_updated_at_is_null(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, ['created_at' => '2026-08-01 10:00:00', 'updated_at' => null]);
        $later = $this->seedTicket($t, 1, ['created_at' => '2026-09-01 10:00:00', 'updated_at' => null]);

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame([$later], $this->survivors($t, 1));
    }

    public function test_cleanup_leaves_exactly_one_of_three(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, []);
        $this->seedTicket($t, 1, ['assigned_to' => 3]);
        $this->seedTicket($t, 1, []);

        $demoted = $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame(2, $demoted);
        $this->assertCount(1, $this->survivors($t, 1));
    }

    public function test_cleanup_touches_only_contacts_with_duplicates(): void
    {
        $t = $this->cleanupTable();
        $soloLatest = $this->seedTicket($t, 1, []);
        $soloOld = $this->seedTicket($t, 2, ['is_latest' => 0]);
        $this->seedTicket($t, 3, []);
        $this->seedTicket($t, 3, []);

        $demoted = $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame(1, $demoted, 'مسّ صفوفاً لا تكرار فيها.');
        $this->assertSame([$soloLatest], $this->survivors($t, 1));
        $this->assertSame([], $this->survivors($t, 2), 'صفّ قديم عاد latest.');
        $this->assertSame(0, (int) DB::table($t)->where('id', $soloOld)->value('is_latest'));
    }

    /** لا حذف: الصفوف المُنزَّلة تبقى تاريخاً. */
    public function test_cleanup_never_deletes_a_row(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, []);
        $this->seedTicket($t, 1, []);
        $this->seedTicket($t, 1, []);

        $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame(3, DB::table($t)->count());
    }

    public function test_cleanup_is_idempotent(): void
    {
        $t = $this->cleanupTable();
        $this->seedTicket($t, 1, []);
        $this->seedTicket($t, 1, []);

        $this->migration()->demoteExtraLatestTickets($t);
        $second = $this->migration()->demoteExtraLatestTickets($t);

        $this->assertSame(0, $second);
    }

    public function test_cleanup_preserves_the_other_columns(): void
    {
        $t = $this->cleanupTable();
        $loser = $this->seedTicket($t, 1, ['status' => 'closed', 'assigned_to' => 7, 'updated_at' => '2026-01-01 00:00:00']);
        $this->seedTicket($t, 1, ['updated_at' => '2026-02-01 00:00:00']);

        $this->migration()->demoteExtraLatestTickets($t);

        $row = DB::table($t)->find($loser);
        $this->assertSame('closed', $row->status);
        $this->assertSame(7, (int) $row->assigned_to);
        $this->assertSame('2026-01-01 00:00:00', $row->updated_at);
    }

    // ------------------------------------------------- رأس المحادثة

    /** العطل: الرأس يرجع الأقدم (موظف وحالة قديمان) والزرّ يعدّل الأحدث. */
    public function test_the_header_shows_the_latest_ticket_not_the_first_row(): void
    {
        $contact = $this->contact();
        $stale = $this->agent();
        $current = $this->agent();
        $this->ticket($contact, ['assigned_to' => $stale->id, 'status' => 'open', 'is_latest' => false]);
        $latest = $this->ticket($contact, ['assigned_to' => $current->id, 'status' => 'closed', 'is_latest' => true]);

        $ticket = $this->openChat($contact)['ticket'];

        $this->assertSame($latest->id, $ticket['id']);
        $this->assertSame($current->id, (int) $ticket['assigned_to'], 'الرأس عرض موظفاً قديماً.');
        $this->assertSame('closed', $ticket['status']);
    }

    /** بيانات قديمة بلا علم أحدث على أي صفّ: يُعرض أعلاها id لا الأقدم. */
    public function test_legacy_rows_without_a_latest_flag_fall_back_to_the_newest_id(): void
    {
        $contact = $this->contact();
        $this->ticket($contact, ['status' => 'closed', 'is_latest' => false]);
        $newest = $this->ticket($contact, ['status' => 'open', 'is_latest' => false]);

        $ticket = $this->openChat($contact)['ticket'];

        $this->assertSame($newest->id, $ticket['id']);
    }

    public function test_a_contact_with_one_ticket_is_unaffected(): void
    {
        $contact = $this->contact();
        $only = $this->ticket($contact, ['status' => 'open', 'is_latest' => true]);

        $this->assertSame($only->id, $this->openChat($contact)['ticket']['id']);
    }

    /** الدورة التي كانت تنكسر: أغلق ثم أعد فتح الصفحة — الرأس يعرض «مغلقة». */
    public function test_mark_as_closed_is_what_the_header_shows_afterwards(): void
    {
        $this->enableTickets();
        $contact = $this->contact();
        $this->ticket($contact, ['status' => 'open', 'is_latest' => false]);   // صفّ قديم بلا علم
        $latest = $this->ticket($contact, ['status' => 'open', 'is_latest' => true]);
        $this->assertSame('open', $this->openChat($contact)['ticket']['status']);

        $this->put('/tickets/' . $contact->uuid . '/update', ['status' => 'closed'])->assertRedirect();

        $after = $this->openChat($contact)['ticket'];
        $this->assertSame($latest->id, $after['id']);
        $this->assertSame('closed', $after['status'], 'أُغلقت المحادثة والرأس ما زال يعرضها مفتوحة.');
    }

    /** الاستعلام الإضافي الوحيد: تذكرة واحدة، لا استعلام لكل صفّ. */
    public function test_opening_a_chat_adds_a_single_ticket_query(): void
    {
        $contact = $this->contact();
        foreach (range(1, 3) as $_) {
            $this->ticket($contact, ['is_latest' => false]);
        }
        $this->ticket($contact, ['is_latest' => true]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from `chat_tickets`') && str_contains($query->sql, 'order by')) {
                $queries[] = $query->sql;
            }
        });
        $this->get('/chats/' . $contact->uuid)->assertStatus(200);

        $this->assertCount(1, $queries, 'استعلام تذكرة الرأس تكرّر.');
        $this->assertStringContainsString('`is_latest` desc', $queries[0]);
        $this->assertStringContainsString('`id` desc', $queries[0]);
        $this->assertStringContainsString('limit 1', $queries[0]);
    }

    // ------------------------------------------------- قائمة المحادثات (ensureChatTicketsExist)

    /** عميل له رسائل وبلا تذكرة (قبل تفعيل التذاكر): تُنشأ له واحدة، ولا تتكرر مع كل تحميل. */
    public function test_loading_the_chat_list_repeatedly_creates_one_ticket(): void
    {
        $this->enableTickets();
        $contact = $this->contact();
        $this->chat($contact, ['type' => 'inbound', 'status' => 'delivered']);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        foreach (range(1, 3) as $_) {
            $this->get('/chats')->assertStatus(200);
        }

        $this->assertSame(1, DB::table('chat_tickets')->where('contact_id', $contact->id)->count());
        $this->assertCount(1, $this->latestIds($contact));
    }

    /**
     * السباق نفسه: التذكرة أُنشئت (من الـ job) قبل أن تُكمل الصفحة. ما لم يكن هناك
     * قيد، كانت الصفحة تُضيف ثانية بـ is_latest = 1 فيظهر العميل مرتين.
     */
    public function test_the_page_does_not_add_a_second_ticket_after_the_job_created_one(): void
    {
        $this->enableTickets();
        $contact = $this->contact();
        $this->chat($contact, ['type' => 'inbound', 'status' => 'delivered']);
        $fromJob = $this->ticket($contact, ['is_latest' => true, 'assigned_to' => $this->owner->id]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
        $this->get('/chats')->assertStatus(200);

        $this->assertSame([$fromJob->id], $this->latestIds($contact));
        $this->assertSame(1, DB::table('chat_tickets')->where('contact_id', $contact->id)->count());
    }

    /** وقائمة المحادثات لا تُكرّر العميل. */
    public function test_the_chat_list_shows_each_contact_once(): void
    {
        $this->enableTickets();
        $contact = $this->contact();
        $this->chat($contact, ['type' => 'inbound', 'status' => 'delivered']);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
        $this->get('/chats')->assertStatus(200);
        $response = $this->get('/chats');

        $rows = $response->viewData('page')['props']['rows']['data'] ?? [];
        $ids = array_map(fn ($r) => $r['id'], $rows);
        $this->assertSame(array_values(array_unique($ids)), $ids, 'ظهر عميل أكثر من مرة في القائمة.');
        $this->assertContains($contact->id, $ids);
    }

    // ------------------------------------------------- الـ job

    private function settings(): object
    {
        return json_decode(json_encode(['tickets' => ['active' => true, 'auto_assignment' => false]]));
    }

    private function createTicketVia(Contact $contact): ?ChatTicket
    {
        $job = new ProcessTicketAssignmentJob($contact->id, $this->organization->id, true);
        $method = new \ReflectionMethod($job, 'createTicket');
        $method->setAccessible(true);

        return $method->invoke($job, $this->settings());
    }

    public function test_the_job_creates_a_single_latest_ticket_for_a_new_contact(): void
    {
        $this->enableTickets();
        $contact = $this->contact();

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, true);

        $this->assertCount(1, $this->latestIds($contact));
        $this->assertSame(1, DB::table('chat_tickets')->where('contact_id', $contact->id)->count());
    }

    /**
     * التصادم الذي يمنعه القيد: كاتب آخر سبق الـ job إلى هذه الجهة. معالجة رسالة
     * وصلت فعلاً لا تسقط بسببه — نعتمد التذكرة الموجودة.
     */
    public function test_the_job_adopts_the_existing_ticket_when_the_constraint_rejects_its_own(): void
    {
        $contact = $this->contact();
        $existing = $this->ticket($contact, ['is_latest' => true, 'assigned_to' => $this->owner->id]);

        $result = $this->createTicketVia($contact);

        $this->assertSame($existing->id, $result->id);
        $this->assertSame([$existing->id], $this->latestIds($contact));
        $this->assertSame(1, DB::table('chat_tickets')->where('contact_id', $contact->id)->count());
    }

    /** وإعادة الفتح تعمل كما كانت: تذكرة مغلقة ⇒ مفتوحة، بلا صفّ جديد. */
    public function test_reopening_a_closed_ticket_still_works(): void
    {
        $this->enableTickets();
        $contact = $this->contact();
        $closed = $this->ticket($contact, ['status' => 'closed', 'is_latest' => true]);

        ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organization->id, false);

        $this->assertSame('open', DB::table('chat_tickets')->where('id', $closed->id)->value('status'));
        $this->assertSame(1, DB::table('chat_tickets')->where('contact_id', $contact->id)->count());
        $this->assertSame([$closed->id], $this->latestIds($contact));
    }

    // ------------------------------------------------- الترحيل

    public function test_the_migration_can_be_run_twice_without_failing(): void
    {
        $migration = $this->migration();

        // الأعمدة والفهرس موجودان فتُتخطّى إضافتهما؛ لا DDL هنا فلا تُنهى معاملة الاختبار.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('chat_tickets', 'latest_contact_id'));
    }
}
