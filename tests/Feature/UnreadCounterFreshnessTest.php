<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * طزاجة عدّاد غير المقروءة المُشارَك مع كل صفحة.
 *
 * عطلٌ وقع: الموظّف يضغط على محادثة فيها رسالة غير مقروءة، فتُفتح وتُقرأ —
 * ويظلّ العدد الكلّي بجانب «المحادثات» كما هو حتى إعادة التحميل أو الانتقال
 * إلى محادثة أخرى.
 *
 * السبب ترتيبٌ لا منطق: وسيط Inertia يستدعي share() قبل المتحكّم
 * (Middleware::handle يشارك في السطر ٨٤ ويستدعي $next في ٨٧)، وفتح المحادثة
 * يعلّم رسائلها مقروءة داخل المتحكّم. فالعدّ الفوري في share() يلتقط ما قبل
 * القراءة، ويكتبه المراقِب في الواجهة فوق النقصان المتفائل — فيبدو العدّاد
 * جامداً.
 *
 * الحلّ تأجيل العدّ إلى بناء الردّ. وهذا الاختبار يحرس التأجيل نفسه: قيمةٌ
 * محسوبة سلفاً تسقط فيه مهما كان الاستعلام صحيحاً.
 */
class UnreadCounterFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $organization;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'security',
            'name' => 'Google Authenticator',
            'logo' => 'ga.png',
            'status' => 1,
            'is_active' => 0,
        ]);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->user = User::factory()->create(['role' => 'user']);

        Team::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'role' => 'manager',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+966594809994',
            'created_by' => $owner->id,
        ]);
    }

    private function inbound(int $count, int $isRead = 0): void
    {
        foreach (range(1, $count) as $i) {
            Chat::create([
                'organization_id' => $this->organization->id,
                'contact_id' => $this->contact->id,
                'wam_id' => 'wamid.' . Str::random(12),
                'type' => 'inbound',
                'status' => 'delivered',
                'is_read' => $isRead,
                'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'رسالة ' . $i]]),
                'created_at' => now(),
            ]);
        }
    }

    private function markAllRead(): void
    {
        Chat::where('organization_id', $this->organization->id)
            ->where('type', 'inbound')
            ->update(['is_read' => 1]);
    }

    /** الحصّة المشتركة كما يبنيها الوسيط لمستخدم مسجّل داخل منظّمته. */
    private function shared(): array
    {
        $request = Request::create('/chats/' . $this->contact->uuid, 'GET');
        $request->setUserResolver(fn () => $this->user);
        $this->withSession(['current_organization' => $this->organization->id]);
        session(['current_organization' => $this->organization->id]);
        $request->setLaravelSession(session()->driver());

        return (new HandleInertiaRequests())->share($request);
    }

    // -------------------------------------------------------- التأجيل

    /**
     * القيمة إغلاق لا رقم. رقمٌ هنا يعني «عدد ما قبل المتحكّم» دائماً.
     */
    public function test_the_unread_count_is_deferred_not_precomputed(): void
    {
        $this->inbound(3);

        $this->assertInstanceOf(
            Closure::class,
            $this->shared()['unreadMessages'],
            'العدّاد محسوب سلفاً — سيحمل دائماً حالة ما قبل تعليم الرسائل مقروءة.'
        );
    }

    /**
     * جوهر العطل: القراءة تقع بعد share()، والردّ يجب أن يحمل ما بعدها.
     */
    public function test_it_reflects_reads_that_happen_after_share_was_called(): void
    {
        $this->inbound(3);

        $counter = $this->shared()['unreadMessages'];

        // ما يفعله المتحكّم بعد share(): يفتح المحادثة فيعلّمها مقروءة.
        $this->markAllRead();

        $this->assertSame(0, $counter(), 'العدّاد لم يرَ القراءة — سيبقى جامداً حتى الطلب التالي.');
    }

    /** وقبل أي قراءة يعطي العدد الصحيح — التأجيل ليس تعطيلاً. */
    public function test_it_counts_unread_messages_when_nothing_was_read(): void
    {
        $this->inbound(3);

        $this->assertSame(3, ($this->shared()['unreadMessages'])());
    }

    /** ونقصان جزئي يظهر جزئياً. */
    public function test_it_reflects_a_partial_read(): void
    {
        $this->inbound(3);
        $counter = $this->shared()['unreadMessages'];

        Chat::where('organization_id', $this->organization->id)->limit(2)->update(['is_read' => 1]);

        $this->assertSame(1, $counter());
    }

    // --------------------------------------------------------- النطاق

    /** الصادر والمقروء لا يُحتسبان. */
    public function test_only_unread_inbound_messages_count(): void
    {
        $this->inbound(2);
        $this->inbound(1, isRead: 1);

        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(12),
            'type' => 'outbound',
            'status' => 'sent',
            'is_read' => 0,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'ردّ']]),
            'created_at' => now(),
        ]);

        $this->assertSame(2, ($this->shared()['unreadMessages'])());
    }

    /** والمحذوف لا يُحتسب — يختفي من الواجهة فلا يصحّ أن يبقى في العدّاد. */
    public function test_deleted_messages_do_not_count(): void
    {
        $this->inbound(2);
        Chat::where('organization_id', $this->organization->id)->limit(1)->update(['deleted_at' => now()]);

        $this->assertSame(1, ($this->shared()['unreadMessages'])());
    }

    /** زائرٌ بلا منظّمة مختارة: صفر لا انفجار. */
    public function test_a_guest_gets_zero(): void
    {
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => null);
        $request->setLaravelSession(session()->driver());

        $counter = (new HandleInertiaRequests())->share($request)['unreadMessages'];

        $this->assertInstanceOf(Closure::class, $counter);
        $this->assertSame(0, $counter());
    }
}
