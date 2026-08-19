<?php

namespace Tests\Unit;

use App\Models\ConversationRating;
use App\Services\ConversationRatingService;
use App\Support\OrganizationRole;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * استبيان رضا العميل: صلاحية الرابط، واستهلاكه مرّة واحدة، وصلاحية الحذف.
 *
 * هذه الثلاثة هي ما يجعل الأرقام ذات معنى: رابط يُفتح مرّتين يُفسد المتوسّط،
 * ورابط بلا انتهاء يُبقي باباً مفتوحاً إلى الأبد، وحذفٌ بلا قيد يجعل التقارير
 * قابلة للتلميع من داخلها.
 */
class ConversationRatingTest extends TestCase
{
    private function rating(array $attributes = []): ConversationRating
    {
        $rating = new ConversationRating();
        $rating->setRawAttributes(array_merge([
            'id' => 1,
            'organization_id' => 211,
            'status' => ConversationRating::STATUS_PENDING,
            'expires_at' => Carbon::now()->addDays(3),
        ], $attributes));

        return $rating;
    }

    // ---------- صلاحية الرابط ----------

    public function test_a_fresh_link_is_open_for_submission(): void
    {
        $this->assertTrue($this->rating()->isOpenForSubmission());
    }

    /** الاستهلاك مرّة واحدة: هذا ما يمنع تكرار التقييم من نفس الرابط. */
    public function test_a_submitted_link_cannot_be_used_again(): void
    {
        $rating = $this->rating([
            'status' => ConversationRating::STATUS_SUBMITTED,
            'rating' => 5,
        ]);

        $this->assertTrue($rating->isSubmitted());
        $this->assertFalse($rating->isOpenForSubmission());
    }

    public function test_an_expired_link_cannot_be_used(): void
    {
        $rating = $this->rating(['expires_at' => Carbon::now()->subMinute()]);

        $this->assertTrue($rating->isExpired());
        $this->assertFalse($rating->isOpenForSubmission());
    }

    /** الحدّ الفاصل: ما زال صالحاً قبل لحظة الانتهاء بثانية. */
    public function test_a_link_is_still_valid_one_second_before_expiry(): void
    {
        $rating = $this->rating(['expires_at' => Carbon::now()->addSecond()]);

        $this->assertTrue($rating->isOpenForSubmission());
    }

    /** غياب تاريخ الانتهاء يعني «بلا انتهاء» لا «منتهٍ». */
    public function test_a_link_without_an_expiry_is_treated_as_open_not_expired(): void
    {
        $rating = $this->rating(['expires_at' => null]);

        $this->assertTrue($rating->isOpenForSubmission());
        $this->assertFalse($rating->isExpired());
    }

    /** المُستهلَك لا يُعدّ منتهياً — الرسالتان مختلفتان أمام العميل. */
    public function test_a_submitted_link_is_reported_as_submitted_not_expired(): void
    {
        $rating = $this->rating([
            'status' => ConversationRating::STATUS_SUBMITTED,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->assertFalse($rating->isExpired());
        $this->assertTrue($rating->isSubmitted());
    }

    // ---------- ثوابت السياسة ----------

    public function test_the_link_stays_valid_for_seven_days(): void
    {
        $this->assertSame(7, ConversationRating::LINK_VALID_DAYS);
    }

    // ---------- متى نسأل مرّة أخرى ----------

    private function ask(?Carbon $lastAsked, ?Carbon $lastReopen, int $cooldown = 30): bool
    {
        return ConversationRatingService::decideAsk($lastAsked, $lastReopen, $cooldown);
    }

    public function test_a_customer_who_was_never_asked_is_asked(): void
    {
        $this->assertTrue($this->ask(null, null));
    }

    /** داخل مدّة التهدئة لا نسأل، ولو فُتحت محادثة جديدة. */
    public function test_a_recent_rating_blocks_a_new_request_even_after_a_reopen(): void
    {
        $askedAt = Carbon::now()->subDays(3);
        $reopened = Carbon::now()->subDay();

        $this->assertFalse($this->ask($askedAt, $reopened, 30));
    }

    /** بعد التهدئة ومع محادثة جديدة: نسأل — وهذا جوهر «لكل محادثة». */
    public function test_a_reopened_conversation_after_the_cooldown_is_asked_again(): void
    {
        $askedAt = Carbon::now()->subDays(60);
        $reopened = Carbon::now()->subDays(2);

        $this->assertTrue($this->ask($askedAt, $reopened, 30));
    }

    /** بعد التهدئة بلا محادثة جديدة: لا نسأل — مرور الوقت وحده ليس سبباً. */
    public function test_time_alone_does_not_earn_a_second_request(): void
    {
        $this->assertFalse($this->ask(Carbon::now()->subYear(), null, 30));
    }

    /** إعادة فتح أقدم من آخر تقييم ليست محادثة جديدة. */
    public function test_a_reopen_older_than_the_last_rating_is_not_a_new_conversation(): void
    {
        $askedAt = Carbon::now()->subDays(40);
        $reopened = Carbon::now()->subDays(50);

        $this->assertFalse($this->ask($askedAt, $reopened, 30));
    }

    /** تهدئة صفر: المحادثة الجديدة وحدها تكفي. */
    public function test_a_zero_cooldown_relies_on_the_reopen_alone(): void
    {
        $askedAt = Carbon::now()->subMinutes(10);
        $reopened = Carbon::now()->subMinutes(5);

        $this->assertTrue($this->ask($askedAt, $reopened, 0));
        // ولا تزال الإغلاقات المتكرّرة بلا إعادة فتح ممنوعة
        $this->assertFalse($this->ask($askedAt, null, 0));
    }

    /** الحدّ الفاصل: تقييم أقدم بلحظة من مدّة التهدئة يسمح بطلب جديد. */
    public function test_the_cooldown_boundary_opens_the_door(): void
    {
        $justOutside = Carbon::now()->subDays(30)->subMinute();

        $this->assertTrue($this->ask($justOutside, Carbon::now()->subHour(), 30));
        $this->assertFalse($this->ask(Carbon::now()->subDays(29), Carbon::now()->subHour(), 30));
    }

    // ---------- ضبط مدّة التهدئة ----------

    public function test_the_cooldown_falls_back_to_thirty_days_when_unset(): void
    {
        $this->assertSame(30, ConversationRatingService::normalizeCooldown(null));
        $this->assertSame(30, ConversationRatingService::normalizeCooldown(''));
    }

    /** قيمة المستخدم تُقيَّد لا تُرفض: السالب صفر، والمبالغ فيه يُقصّ عند السقف. */
    public function test_the_cooldown_is_clamped_to_a_sane_range(): void
    {
        $this->assertSame(0, ConversationRatingService::normalizeCooldown(-5));
        $this->assertSame(7, ConversationRatingService::normalizeCooldown('7'));
        $this->assertSame(365, ConversationRatingService::normalizeCooldown(9999));
    }

    // ---------- صلاحية الحذف ----------

    /** المالك وحده يحذف — والمدير قد يكون هو من وقع عليه التقييم السيّئ. */
    public function test_only_the_owner_role_may_delete(): void
    {
        $this->assertTrue(OrganizationRole::isOwnerOnly(OrganizationRole::OWNER));
        $this->assertFalse(OrganizationRole::isOwnerOnly(OrganizationRole::MANAGER));
        $this->assertFalse(OrganizationRole::isOwnerOnly(OrganizationRole::AGENT));
        $this->assertFalse(OrganizationRole::isOwnerOnly(null));
    }

    /** المدير يرى الصفحة وإن لم يحذف. */
    public function test_managers_may_view_the_page(): void
    {
        $this->assertTrue(OrganizationRole::isPrivileged(OrganizationRole::MANAGER));
        $this->assertFalse(OrganizationRole::isPrivileged(OrganizationRole::AGENT));
    }
}
