<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Organization;

class ContactPlaceholderService
{
    /**
     * Replace `{field}` and `{url:field}` tokens using contact and organization data (same rules as canned replies).
     */
    public static function replace(int $organizationId, string $contactUuid, string $message): string
    {
        $organization = Organization::where('id', $organizationId)->first();
        $contact = Contact::with('contactGroups')->where('uuid', $contactUuid)->first();
        if (!$organization || !$contact) {
            return $message;
        }

        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
        $full_address = ($address['street'] ?? null) . ', ' .
            ($address['city'] ?? null) . ', ' .
            ($address['state'] ?? null) . ', ' .
            ($address['zip'] ?? null) . ', ' .
            ($address['country'] ?? null);

        $data = [
            'first_name' => $contact->first_name ?? null,
            'last_name' => $contact->last_name ?? null,
            'full_name' => $contact->full_name ?? null,
            'email' => $contact->email ?? null,
            'phone' => $contact->phone ?? null,
            'organization_name' => $organization->name,
            'full_address' => $full_address,
            'street' => $address['street'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip_code' => $address['zip'] ?? null,
            'country' => $address['country'] ?? null,
        ];

        $transformedMetadata = [];
        if ($metadata) {
            foreach ($metadata as $key => $value) {
                $transformedKey = mb_strtolower(str_replace(' ', '_', trim((string) $key)));
                $transformedMetadata[$transformedKey] = $value;
            }
        }

        $mergedData = array_merge($data, $transformedMetadata);

        // المُعدِّل u ضروري: بدونه \w حروفٌ لاتينية فقط، فحقلٌ مخصّص باسم عربي
        // مثل «عدد الطلبات» لا يُطابَق أبداً — يكتبه المستخدم {عدد_الطلبات}
        // فيصل العميل الرمز كما هو، بلا خطأ يشير إلى السبب.
        $message = preg_replace_callback('/\{url:([\w\x{0600}-\x{06FF}]+)\}/u', function ($matches) use ($mergedData) {
            $key = $matches[1];
            if (isset($mergedData[$key])) {
                return rawurlencode((string) $mergedData[$key]);
            }

            return $matches[0];
        }, $message);

        return preg_replace_callback('/\{([\w\x{0600}-\x{06FF}]+)\}/u', function ($matches) use ($mergedData) {
            $key = $matches[1];
            if (isset($mergedData[$key])) {
                return (string) $mergedData[$key];
            }

            return $matches[0];
        }, $message);
    }
}
