<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * من ضغط «إعادة الإشتراك» في مسار «ايقاف الاعلانات» قبل وجود إجراء إعادة
 * الاشتراك بقي منسحباً. الترحيل يُعيد من كان آخر ما اختاره العودة، ويترك
 * غيره منسحباً.
 */
class RestoreMarketingForResubscribedContactsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_18_120000_restore_marketing_for_contacts_who_resubscribed.php';
    private const ORGANIZATION_UUID = 'fc83d605-9b9b-4725-a703-701abbf4ea96';

    private User $user;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
        DB::table('organizations')->where('id', $this->organization->id)->update(['uuid' => self::ORGANIZATION_UUID]);
    }

    /** ما وقع على الإنتاج: انسحاب، ثم «إعادة الإشتراك» و«أبغى أستقبل عروضكم». */
    public function test_a_contact_whose_last_choice_was_to_resubscribe_is_restored(): void
    {
        $contact = $this->optedOutContact();
        $this->text($contact, 'إيقاف تلقي العروض');
        $this->button($contact, 'تأكيد إلغاء الإشتراك');
        $this->button($contact, 'إعادة الإشتراك');
        $this->button($contact, 'أبغى أستقبل عروضكم');

        $this->runMigration();

        $this->assertNull($contact->fresh()->marketing_opted_out_at);
    }

    public function test_a_contact_who_opted_out_again_after_resubscribing_stays_out(): void
    {
        $contact = $this->optedOutContact();
        $this->button($contact, 'إعادة الإشتراك');
        $this->button($contact, 'تأكيد إلغاء الإشتراك');

        $this->runMigration();

        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    /** إرسال كلمة البدء بعد العودة نيّةُ انسحاب: يبقى منسحباً. */
    public function test_sending_the_stop_keyword_after_resubscribing_keeps_the_opt_out(): void
    {
        $contact = $this->optedOutContact();
        $this->button($contact, 'إعادة الإشتراك');
        $this->text($contact, 'ايقاف تلقي العروض');

        $this->runMigration();

        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    public function test_a_contact_with_no_reply_after_opting_out_stays_out(): void
    {
        $contact = $this->optedOutContact();
        $this->text($contact, 'شكراً');

        $this->runMigration();

        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    /** ضغطة قبل الانسحاب لا تُحسب: الانسحاب بعدها هو القرار. */
    public function test_a_resubscribe_press_before_the_opt_out_does_not_count(): void
    {
        $contact = $this->optedOutContact(minutesAgo: 10);
        $this->button($contact, 'إعادة الإشتراك', minutesAgo: 20);

        $this->runMigration();

        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    public function test_other_organizations_are_untouched(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->user->id]);
        $contact = $this->optedOutContact(organization: $other);
        $this->button($contact, 'إعادة الإشتراك');

        $this->runMigration();

        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    public function test_it_does_nothing_without_the_organization(): void
    {
        DB::table('organizations')->where('id', $this->organization->id)->update(['uuid' => (string) Str::uuid()]);
        $contact = $this->optedOutContact();
        $this->button($contact, 'إعادة الإشتراك');

        $this->runMigration();

        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    public function test_running_it_twice_is_harmless(): void
    {
        $restored = $this->optedOutContact();
        $this->button($restored, 'إعادة الإشتراك');
        $kept = $this->optedOutContact();

        $this->runMigration();
        $this->runMigration();

        $this->assertNull($restored->fresh()->marketing_opted_out_at);
        $this->assertNotNull($kept->fresh()->marketing_opted_out_at);
    }

    // ------------------------------------------------- أدوات

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    private function optedOutContact(int $minutesAgo = 60, ?Organization $organization = null): Contact
    {
        $contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'first_name' => 'عميل',
            'phone' => '+9665' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'created_by' => $this->user->id,
        ]);
        $contact->forceFill(['marketing_opted_out_at' => now()->subMinutes($minutesAgo)])->save();

        return $contact->fresh();
    }

    private function button(Contact $contact, string $title, int $minutesAgo = 5): void
    {
        $this->inbound($contact, ['type' => 'interactive', 'interactive' => [
            'type' => 'button_reply',
            'button_reply' => ['id' => 'button1', 'title' => $title],
        ]], $minutesAgo);
    }

    private function text(Contact $contact, string $body, int $minutesAgo = 5): void
    {
        $this->inbound($contact, ['type' => 'text', 'text' => ['body' => $body]], $minutesAgo);
    }

    private function inbound(Contact $contact, array $metadata, int $minutesAgo): void
    {
        $chat = Chat::create([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'status' => 'delivered',
        ]);
        DB::table('chats')->where('id', $chat->id)->update(['created_at' => now()->subMinutes($minutesAgo)]);
    }
}
