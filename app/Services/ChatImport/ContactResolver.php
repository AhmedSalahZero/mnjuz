<?php

namespace App\Services\ChatImport;

use App\Services\PhoneService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * أرقام الملف → جهات اتصال المنشأة، بالجملة.
 *
 * الفحص والكتابة يستعملانه كلاهما، فعدد «جهات الاتصال الجديدة» الذي يُقاس
 * به حدّ الباقة هو نفسه ما يُنشأ فعلاً.
 *
 * لا ContactService::findOrCreateByPhone لكل رقم: حين لا يجد الرقم بصيغته
 * يمسح جهات المنشأة كلّها بـ REPLACE — خمسون ألف رقم جديد تعني خمسين ألف
 * مسح. هنا مطابقة دقيقة بـ whereIn، ثم خريطة واحدة للأرقام المحفوظة بصيغة
 * قديمة (بلا «+» أو بفواصل) تُحمَّل مرّة.
 */
final class ContactResolver
{
    private const CHUNK = 1000;

    /** @var array<string, int>|null أرقام (بلا رموز) → معرّف، للأرقام المحفوظة بصيغة قديمة. */
    private ?array $legacy = null;

    public function __construct(private readonly int $organizationId)
    {
    }

    /**
     * جهات الاتصال الموجودة لأرقام E.164.
     *
     * @param array<int, string> $phones
     * @return array<string, int> رقم → معرّف
     */
    public function lookup(array $phones): array
    {
        $phones = array_values(array_unique($phones));
        $found = [];

        foreach (array_chunk($phones, self::CHUNK) as $chunk) {
            $rows = DB::table('contacts')
                ->where('organization_id', $this->organizationId)
                ->whereNull('deleted_at')
                ->whereIn('phone', $chunk)
                ->orderBy('id')
                ->get(['id', 'phone']);

            foreach ($rows as $row) {
                $found[$row->phone] ??= (int) $row->id;
            }
        }

        $missing = array_diff($phones, array_keys($found));
        if ($missing === []) {
            return $found;
        }

        $legacy = $this->legacyMap();
        foreach ($missing as $phone) {
            $digits = ltrim($phone, '+');
            if (isset($legacy[$digits])) {
                $found[$phone] = $legacy[$digits];
            }
        }

        return $found;
    }

    /**
     * جهات الاتصال لكل الأرقام، بإنشاء الناقص.
     *
     * @param array<string, ?string> $phones رقم E.164 → الاسم (لجهة الاتصال الجديدة فقط)
     * @return array<string, array{id: int, created: bool}>
     */
    public function resolveOrCreate(array $phones, int $userId): array
    {
        $existing = $this->lookup(array_keys($phones));
        $result = [];

        foreach ($existing as $phone => $id) {
            $result[$phone] = ['id' => $id, 'created' => false];
        }

        $missing = array_diff_key($phones, $existing);
        if ($missing === []) {
            return $result;
        }

        $now = now()->utc()->toDateTimeString();
        $rows = [];
        foreach ($missing as $phone => $name) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $this->organizationId,
                'first_name' => $name,
                'phone' => $phone,
                'formatted_phone' => mb_substr((string) PhoneService::formatForDisplay($phone), 0, 64),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // insertOrIgnore يتخطّى الرقم إن أنشأه webhook في اللحظة نفسها؛
        // القيم مضبوطة الطول سلفاً فلا يُخفي اقتطاعاً.
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('contacts')->insertOrIgnore($chunk);
        }

        foreach ($this->exact(array_keys($missing)) as $phone => $id) {
            $result[$phone] = ['id' => $id, 'created' => true];
        }

        $stillMissing = array_keys(array_diff_key($missing, $result));
        if ($stillMissing !== []) {
            foreach ($this->restoreTrashed($stillMissing) as $phone => $id) {
                $result[$phone] = ['id' => $id, 'created' => true];
            }
        }

        $unresolved = array_diff_key($missing, $result);
        if ($unresolved !== []) {
            throw new ChatImportException(__('The contact :phone could not be created.', ['phone' => array_key_first($unresolved)]));
        }

        return $result;
    }

    /**
     * جهة اتصال محذوفة بالرقم نفسه.
     *
     * الفهرس الفريد على (organization_id, phone) — حيث وُجد — يشمل المحذوف،
     * فلا يُنشأ للرقم صفّ ثانٍ وinsertOrIgnore يتخطّاه بصمت. نُعيدها كما يفعل
     * استيراد جهات الاتصال، ونعدّها ممّا أنشأه الاستيراد فيُعيد التراجعُ حذفها.
     *
     * @param array<int, string> $phones
     * @return array<string, int>
     */
    private function restoreTrashed(array $phones): array
    {
        $restored = [];

        foreach (array_chunk($phones, self::CHUNK) as $chunk) {
            $rows = DB::table('contacts')
                ->where('organization_id', $this->organizationId)
                ->whereNotNull('deleted_at')
                ->whereIn('phone', $chunk)
                ->orderByDesc('id')
                ->get(['id', 'phone']);

            foreach ($rows as $row) {
                if (isset($restored[$row->phone])) {
                    continue;
                }

                DB::table('contacts')->where('id', $row->id)->update([
                    'deleted_at' => null,
                    'updated_at' => now()->utc()->toDateTimeString(),
                ]);
                $restored[$row->phone] = (int) $row->id;
            }
        }

        return $restored;
    }

    /**
     * @param array<int, string> $phones
     * @return array<string, int>
     */
    private function exact(array $phones): array
    {
        $found = [];

        foreach (array_chunk($phones, self::CHUNK) as $chunk) {
            DB::table('contacts')
                ->where('organization_id', $this->organizationId)
                ->whereNull('deleted_at')
                ->whereIn('phone', $chunk)
                ->orderBy('id')
                ->get(['id', 'phone'])
                ->each(function ($row) use (&$found) {
                    $found[$row->phone] ??= (int) $row->id;
                });
        }

        return $found;
    }

    /** @return array<string, int> */
    private function legacyMap(): array
    {
        if ($this->legacy !== null) {
            return $this->legacy;
        }

        $this->legacy = [];

        DB::table('contacts')
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->whereNotNull('phone')
            ->where('phone', 'not regexp', '^\\+[0-9]+$')
            ->orderBy('id')
            ->select(['id', 'phone'])
            ->cursor()
            ->each(function ($row) {
                $digits = (string) preg_replace('/\D/', '', (string) $row->phone);
                if (str_starts_with($digits, '00')) {
                    $digits = substr($digits, 2);
                }
                if ($digits !== '') {
                    $this->legacy[$digits] ??= (int) $row->id;
                }
            });

        return $this->legacy;
    }
}
