<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Propaganistas\LaravelPhone\PhoneNumber;
use Exception;

/**
 * رقم الهاتف لا يتكرّر داخل المنشأة.
 *
 * والرسالة تُسمّي صاحب الرقم.
 *
 * من الإنتاج: عميلة تحاول إضافة رقم فيُقال لها «موجود مسبقاً»، فتبحث عنه
 * ولا تجده، فتفتح شكوى. والرقم كان موجوداً فعلاً باسم لاتيني بينما هي تبحث
 * بالاسم العربي — وعندها ٢٨ ألف جهة اتصال. والرسالة القديمة تعرف اسم الصفّ
 * الذي وجدته ثم ترميه.
 *
 * ويُميَّز الرقم غير الصالح عن المكرّر: كان كلاهما يقول «موجود مسبقاً»،
 * فمن كتب رقماً خاطئاً يبحث عن تكرار لا وجود له.
 */
class UniquePhone implements Rule
{
    private $organizationId;
    private $uuid;

    /** اسم صاحب الرقم الموجود، إن وُجد. */
    private ?string $existingName = null;

    /** هل فشل التحقّق لأن الرقم غير صالح لا لأنه مكرّر؟ */
    private bool $unparseable = false;

    public function __construct($organizationId, $uuid = null)
    {
        $this->organizationId = $organizationId;
        $this->uuid = $uuid;
    }

    public function passes($attribute, $value)
    {
        $this->existingName = null;
        $this->unparseable = false;

        try {
            // Join and strip the plus sign from the phone number
            if (!str_starts_with($value, '+')) {
                $value = '+' . $value;
            }

            $phone = new PhoneNumber($value);
            $formattedPhone = $phone->formatE164();
        } catch (Exception $e) {
            $this->unparseable = true;

            return false;
        }

        // Check if the phone number is unique for the given organization_id
        $query = DB::table('contacts')
            ->where('organization_id', $this->organizationId)
            ->where('phone', $formattedPhone)
            ->where('deleted_at', null);

        if ($this->uuid) {
            $query->where('uuid', '!=', $this->uuid);
        }

        $existing = $query->first(['first_name', 'last_name', 'phone']);

        if (!$existing) {
            return true;
        }

        $this->existingName = trim(($existing->first_name ?? '') . ' ' . ($existing->last_name ?? ''));

        return false;
    }

    public function message()
    {
        if ($this->unparseable) {
            return __('This phone number is not valid.');
        }

        if ($this->existingName !== null && $this->existingName !== '') {
            return __('This number is already saved under the contact ":name". Search for it by number in Contacts.', [
                'name' => $this->existingName,
            ]);
        }

        return __('This phone number already exists');
    }
}
