<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessageJob;
use App\Models\Contact;
use App\Models\ConversationRating;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * اسم العميل: من أين يأتي، ومتى يبقى فارغاً.
 *
 * ظهر في جدول التقييمات عميلٌ بلا اسم رغم مراسلته لنا مراراً، واسمه ظاهر في
 * واتساب. السبب أن التقاط الاسم كان مشروطاً بـ`first_name === null` وحده،
 * وجهات الاتصال تُنشأ من مسارات شتّى: بعضها يترك الحقل NULL وبعضها يكتب سلسلة
 * فارغة. فمن وقع اسمه فارغاً بقي بلا اسم أبداً — لا شيء يصلحه لاحقاً.
 *
 * والفراغ كان يتنكّر في زيّ الاسم أيضاً: full_name تُرجع ' ' لعميلٍ بلا اسمين،
 * وهي قيمة تمرّ كل فحوص الفراغ فتُعرض «اسماً» من مسافة واحدة.
 */
class ContactNameCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);
    }

    private function contact(?string $first, ?string $last = null): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => '+2010' . random_int(10000000, 99999999),
            'created_by' => $this->owner->id,
        ]);
    }

    /** تشغيل التقاط الاسم كما يفعل الوارد، باسم ملفّ واتساب المُعطى. */
    private function applyProfileName(Contact $contact, ?string $profileName): Contact
    {
        $job = new ProcessIncomingMessageJob(
            [],
            $profileName === null ? [] : ['profile' => ['name' => $profileName]],
            $this->organization->id
        );

        $method = new ReflectionMethod(ProcessIncomingMessageJob::class, 'updateContactNameIfNull');
        $method->setAccessible(true);
        $method->invoke($job, $contact);

        return $contact->fresh();
    }

    // ------------------------------------------------ التقاط الاسم

    /** الحالة التي كانت مكسورة: اسم فارغ لا معدوم. */
    public function test_a_blank_name_is_filled_from_the_whatsapp_profile(): void
    {
        $contact = $this->contact('');

        $this->assertSame('أحمد صلاح', $this->applyProfileName($contact, 'أحمد صلاح')->first_name);
    }

    /** والمسافات وحدها فراغ كذلك. */
    public function test_a_whitespace_only_name_is_filled(): void
    {
        $contact = $this->contact('   ');

        $this->assertSame('أحمد', $this->applyProfileName($contact, 'أحمد')->first_name);
    }

    public function test_a_null_name_is_filled(): void
    {
        $contact = $this->contact(null);

        $this->assertSame('أحمد', $this->applyProfileName($contact, 'أحمد')->first_name);
    }

    /** اسمٌ موجود لا يُدهَس: العميل قد يكون سُمّي يدوياً بما يعرفه الفريق. */
    public function test_an_existing_name_is_never_overwritten(): void
    {
        $contact = $this->contact('شركة منجز');

        $this->assertSame('شركة منجز', $this->applyProfileName($contact, 'اسم آخر')->first_name);
    }

    /** عدد جُمل الكتابة على جدول العملاء أثناء تنفيذ ما بداخل الإغلاق. */
    private function writesToContacts(callable $run): int
    {
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update `contacts`')) {
                $writes++;
            }
        });

        $run();

        return $writes;
    }

    /**
     * اسم ملفّ فارغ لا يُكتب.
     *
     * النتيجة واحدة كتبنا أو لم نكتب — فراغٌ مكان فراغ — فالمقياس هو الكتابة
     * نفسها: بلا الحارس تُنفَّذ جملة UPDATE مع كل رسالة واردة من كل عميل بلا
     * اسم، بلا أن تغيّر شيئاً.
     */
    public function test_a_blank_profile_name_writes_nothing(): void
    {
        $contact = $this->contact('');

        $writes = $this->writesToContacts(fn () => $this->applyProfileName($contact, '   '));

        $this->assertSame(0, $writes, 'كتابة بلا تغيير مع كل رسالة واردة.');
        $this->assertSame('', (string) $contact->fresh()->first_name);
    }

    public function test_a_missing_profile_writes_nothing(): void
    {
        $contact = $this->contact(null);

        $this->assertSame(0, $this->writesToContacts(fn () => $this->applyProfileName($contact, null)));
    }

    /** واسمٌ موجود لا يُعاد كتابته أصلاً. */
    public function test_an_existing_name_writes_nothing(): void
    {
        $contact = $this->contact('شركة منجز');

        $this->assertSame(0, $this->writesToContacts(fn () => $this->applyProfileName($contact, 'اسم آخر')));
    }

    /** أمّا الاسم الحقيقي فيُكتب مرّة واحدة. */
    public function test_a_real_profile_name_is_written_once(): void
    {
        $contact = $this->contact('');

        $this->assertSame(1, $this->writesToContacts(fn () => $this->applyProfileName($contact, 'أحمد صلاح')));
    }

    // -------------------------------------------------- الاسم الكامل

    /** مسافةٌ ليست اسماً: الغياب يجب أن يكون غياباً صريحاً. */
    public function test_a_nameless_contact_has_an_empty_full_name(): void
    {
        $this->assertSame('', $this->contact('', '')->full_name);
        $this->assertSame('', $this->contact(null, null)->full_name);
    }

    public function test_a_first_name_alone_has_no_trailing_space(): void
    {
        $this->assertSame('أحمد', $this->contact('أحمد', null)->full_name);
    }

    public function test_both_names_are_joined(): void
    {
        $this->assertSame('أحمد صلاح', $this->contact('أحمد', 'صلاح')->full_name);
    }

    // ------------------------------------------- عرض جدول التقييمات

    /**
     * اللقطة تُحفظ لحظة الطلب لتبقى بعد حذف العميل؛ لكن من كان بلا اسم وقتها
     * قد يُسمّى بعدها، فعرض «—» إلى الأبد إخفاءٌ لمعلومة نملكها.
     */
    public function test_a_rating_row_falls_back_to_the_current_contact_name(): void
    {
        $contact = $this->contact('');

        $rating = ConversationRating::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'contact_name' => null,
            'contact_phone' => $contact->phone,
            'token' => Str::random(48),
            'status' => ConversationRating::STATUS_SUBMITTED,
            'rating' => 5,
            'submitted_at' => now(),
        ]);

        $contact->update(['first_name' => 'أحمد صلاح']);

        $displayed = $rating->contact_name ?: (trim((string) optional($rating->fresh()->contact)->full_name) ?: null);

        $this->assertSame('أحمد صلاح', $displayed);
    }

    /** واللقطة تسبق: تغيير اسم العميل لاحقاً لا يُعيد كتابة التاريخ. */
    public function test_a_recorded_name_wins_over_the_current_one(): void
    {
        $contact = $this->contact('الاسم الجديد');

        $rating = ConversationRating::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'contact_name' => 'الاسم وقت التقييم',
            'contact_phone' => $contact->phone,
            'token' => Str::random(48),
            'status' => ConversationRating::STATUS_SUBMITTED,
            'rating' => 4,
            'submitted_at' => now(),
        ]);

        $displayed = $rating->contact_name ?: (trim((string) optional($rating->contact)->full_name) ?: null);

        $this->assertSame('الاسم وقت التقييم', $displayed);
    }
}
