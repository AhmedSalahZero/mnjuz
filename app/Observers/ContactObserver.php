<?php

namespace App\Observers;

use App\Models\Contact;
use App\Services\PhoneService;

class ContactObserver
{
    public function saving(Contact $contact): void
    {
        if (!$contact->isDirty('phone')) {
            return;
        }

        $phone = $contact->phone;
        if ($phone === null || $phone === '') {
            $contact->formatted_phone = null;

            return;
        }

        $contact->formatted_phone = PhoneService::formatForDisplay($phone);
    }
}
