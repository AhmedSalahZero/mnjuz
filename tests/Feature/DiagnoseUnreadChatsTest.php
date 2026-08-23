<?php

namespace Tests\Feature;

use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * أداة تشخيص شكوى «رسالة العميل لم تظهر».
 *
 * الشكوى واحدة والأعطال ثلاثة: لم تُحفظ، أو حُفظت وعُلّمت مقروءة، أو حُفظت
 * وبقيت غير مقروءة ولم تصل المتصفّح. لا يفرّق بينها إلا الأثر في القاعدة —
 * والأداة موجودة لتقول أيّها وقع بدل أن نخمّن.
 *
 * والأهمّ أن تكون للقراءة فقط: تُشغَّل على الإنتاج وقت شكوى عميل.
 */
class DiagnoseUnreadChatsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);
        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'أحمد',
            'phone' => '+201025894984',
            'created_by' => $this->owner->id,
        ]);
    }

    private function inbound(int $isRead, string $body = 'مرحباً', ?int $minutesAgo = 30): Chat
    {
        return Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(12),
            'type' => 'inbound',
            'status' => 'delivered',
            'is_read' => $isRead,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => $body]]),
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function outbound(?int $userId, int $minutesAgo = 30, int $secondsAfter = 2): Chat
    {
        return Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(12),
            'type' => 'outbound',
            'status' => 'sent',
            'user_id' => $userId,
            'is_read' => 1,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'ردّ']]),
            'created_at' => now()->subMinutes($minutesAgo)->addSeconds($secondsAfter),
        ]);
    }

    private function diagnose(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('chat:diagnose-unread', array_merge(
            ['contact' => $this->contact->phone],
            $options
        ));
    }

    // ------------------------------------------------ الحالة الأولى

    /** لا رسائل محفوظة ⇒ الخلل قبل الحفظ. */
    public function test_it_points_at_the_webhook_when_nothing_was_stored(): void
    {
        $this->diagnose()->expectsOutputToContain('لا رسائل في هذا المدى')->assertExitCode(0);
    }

    // ------------------------------------------------ الحالة الثانية

    /**
     * جوهر الشكوى: ردّ آليّ بعد ثوانٍ يعلّم رسائل العميل مقروءة، فتصل ولا
     * يظهر لها عدّاد. الأداة يجب أن تسمّي السبب لا أن تعرض «مقروءة» فقط.
     */
    public function test_it_names_the_auto_reply_as_the_cause(): void
    {
        $this->inbound(isRead: 1);
        $this->outbound(userId: null);

        $this->diagnose()
            ->expectsOutputToContain('الشكوى صحيحة، والسبب الردّ الآليّ')
            ->assertExitCode(0);
    }

    /** وردّ موظّف حقيقي ليس ردّاً آلياً. */
    public function test_a_human_reply_is_not_blamed_on_automation(): void
    {
        $this->inbound(isRead: 1);
        $this->outbound(userId: $this->owner->id);

        $this->diagnose()
            ->doesntExpectOutputToContain('الشكوى صحيحة، والسبب الردّ الآليّ')
            ->assertExitCode(0);
    }

    /** وردٌّ بعد ساعة ليس ردّاً آلياً مهما غاب اسم الموظّف. */
    public function test_a_late_reply_is_not_treated_as_automatic(): void
    {
        $this->inbound(isRead: 1, minutesAgo: 120);
        $this->outbound(userId: null, minutesAgo: 60);

        $this->diagnose()
            ->doesntExpectOutputToContain('الشكوى صحيحة، والسبب الردّ الآليّ')
            ->assertExitCode(0);
    }

    // ------------------------------------------------ الحالة الثالثة

    /** وصلت وبقيت غير مقروءة ⇒ الخلل في التوصيل إلى المتصفّح. */
    public function test_it_points_at_delivery_when_the_message_stayed_unread(): void
    {
        $this->inbound(isRead: 0);

        $this->diagnose()
            ->expectsOutputToContain('الخلل في التوصيل إلى المتصفّح')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------ السياق

    /** وجود ردود آليّة مضبوطة سياقٌ يغيّر تفسير كل ما بعده. */
    public function test_it_reports_whether_auto_replies_exist(): void
    {
        AutoReply::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'ترحيب',
            'trigger' => 'مرحبا',
            'match_criteria' => 'contains',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'أهلاً']]),
            'created_by' => $this->owner->id,
        ]);
        $this->inbound(isRead: 0);

        $this->diagnose()->expectsOutputToContain('تعليم الرسائل مقروءة وارد')->assertExitCode(0);
    }

    // ------------------------------------------------------ السلامة

    /** الأداة لا تكتب حرفاً — تُشغَّل على الإنتاج وقت الشكوى. */
    public function test_it_never_writes_to_the_database(): void
    {
        $message = $this->inbound(isRead: 0);
        $this->outbound(userId: null);

        $writes = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(update|insert|delete)\b/i', $query->sql)) {
                $writes++;
            }
        });

        $this->diagnose()->assertExitCode(0);
        $this->artisan('chat:diagnose-unread', ['contact' => $this->contact->phone])->run();

        $this->assertSame(0, $writes, 'أداة تشخيص تكتب في القاعدة.');
        $this->assertSame(0, (int) $message->fresh()->is_read, 'حالة القراءة تغيّرت أثناء التشخيص.');
    }

    /** ويمكن الوصول بالـuuid كما بالرقم. */
    public function test_it_accepts_a_uuid(): void
    {
        $this->inbound(isRead: 0);

        $this->artisan('chat:diagnose-unread', ['contact' => $this->contact->uuid])
            ->expectsOutputToContain('الخلاصة')
            ->assertExitCode(0);
    }

    public function test_an_unknown_contact_is_reported_clearly(): void
    {
        $this->artisan('chat:diagnose-unread', ['contact' => '+999999999999'])
            ->expectsOutputToContain('لم يُعثر على جهة اتصال')
            ->assertExitCode(1);
    }
}
