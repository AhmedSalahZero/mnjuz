<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactPlaceholderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * replace() = valuesFor() ثم replaceWith(). قالب البريد المصمَّم يجلب القيم
 * مرّة ويطبّقها على كل نصوصه، فيجب أن يطابق الناتج replace() حرفاً بحرف.
 */
class ContactPlaceholderValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_values_once_then_many_texts_match_replace(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $owner->id, 'name' => 'منجز']);
        $contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'first_name' => 'نورة سالم',
            'email' => null,
            'phone' => '+966500000001',
            'metadata' => json_encode(['عدد الطلبات' => 3, 'VIP' => true]),
            'created_by' => $owner->id,
        ]);

        $texts = [
            'أهلاً {first_name} من {organization_name}',
            'https://shop.example/?n={url:first_name}',
            'بريدك: {email} — طلباتك {عدد_الطلبات} — {vip} — {unknown}',
            'بلا متغيّرات',
        ];

        DB::enableQueryLog();
        $values = ContactPlaceholderService::valuesFor($organization->id, $contact->uuid);
        $queries = count(DB::getQueryLog());

        foreach ($texts as $text) {
            $this->assertSame(
                ContactPlaceholderService::replace($organization->id, $contact->uuid, $text),
                ContactPlaceholderService::replaceWith($values, $text),
                $text
            );
        }

        $this->assertSame('https://shop.example/?n=' . rawurlencode('نورة سالم'), ContactPlaceholderService::replaceWith($values, $texts[1]));

        // replaceWith لا يستعلم.
        DB::flushQueryLog();
        ContactPlaceholderService::replaceWith($values, $texts[0]);
        $this->assertCount(0, DB::getQueryLog());
        $this->assertGreaterThan(0, $queries);
    }

    public function test_an_unknown_contact_has_no_values_and_replace_keeps_the_text(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $owner->id]);

        $this->assertNull(ContactPlaceholderService::valuesFor($organization->id, (string) Str::uuid()));
        $this->assertSame('أهلاً {first_name}', ContactPlaceholderService::replace($organization->id, (string) Str::uuid(), 'أهلاً {first_name}'));
    }
}
