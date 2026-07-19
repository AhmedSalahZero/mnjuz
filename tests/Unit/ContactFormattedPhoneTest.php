<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Observers\ContactObserver;
use App\Services\PhoneService;
use PHPUnit\Framework\TestCase;

class ContactFormattedPhoneTest extends TestCase
{
    public function test_observer_sets_formatted_phone_when_phone_changes(): void
    {
        $contact = new Contact(['phone' => '+201234567890']);
        $observer = new ContactObserver();
        $observer->saving($contact);

        $this->assertNotNull($contact->formatted_phone);
        $this->assertSame(
            PhoneService::formatForDisplay('+201234567890'),
            $contact->formatted_phone
        );
    }

    public function test_observer_skips_when_phone_unchanged(): void
    {
        $contact = new Contact([
            'phone' => '+201234567890',
            'formatted_phone' => '+20 12 34567890',
        ]);
        $contact->syncOriginal();

        $observer = new ContactObserver();
        $observer->saving($contact);

        $this->assertSame('+20 12 34567890', $contact->formatted_phone);
    }

    public function test_accessor_prefers_stored_formatted_phone(): void
    {
        $contact = new Contact([
            'phone' => '+201234567890',
            'formatted_phone' => '+20 stored',
        ]);

        $this->assertSame('+20 stored', $contact->formatted_phone_number);
    }
}
