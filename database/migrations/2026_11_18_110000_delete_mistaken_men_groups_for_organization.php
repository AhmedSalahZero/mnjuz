<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /** «مجمع تجديد الصحة والجمال الطبي» (id 295 على الإنتاج). الـ uuid لا يتغيّر بين البيئات، والـ id يتغيّر. */
    private const ORGANIZATION_UUID = 'fc83d605-9b9b-4725-a703-701abbf4ea96';

    private const NAME_PATTERN = '/^Men \d+$/';

    private const CHUNK_SIZE = 500;

    /**
     * حذف نهائي لمجموعات «Men <رقم>» التي أُنشئت بالخطأ لمنشأة واحدة.
     *
     * يوم 2026-11-18 رُفع ملف عملاء من «رفع جماعي» في صفحة المجموعات لا جهات
     * الاتصال، فصار كل صف مجموعة فارغة باسم العميل: 2498 مجموعة بلا أي جهة
     * اتصال. طلبت المنشأة حذفها مع بقاء جهات الاتصال.
     *
     * جدول contacts لا يُلمس إطلاقاً: يُحذف الربط (contact_contact_group) ثم
     * المجموعة، فتبقى أي جهة اتصال رُبطت بها بلا مجموعة. والمجموعة التي تشير
     * إليها حملة تُستثنى، كي لا تبقى الحملة تشير إلى صف غير موجود.
     */
    public function up(): void
    {
        $organizationId = DB::table('organizations')->where('uuid', self::ORGANIZATION_UUID)->value('id');
        if ($organizationId === null) {
            return;
        }

        $candidates = DB::table('contact_groups')
            ->where('organization_id', $organizationId)
            ->where('name', 'like', 'Men %')
            ->get(['id', 'name'])
            // LIKE في MySQL لا يميّز حالة الأحرف؛ المطابقة الدقيقة هنا.
            ->filter(fn (object $group) => preg_match(self::NAME_PATTERN, $group->name) === 1)
            ->pluck('id');

        if ($candidates->isEmpty()) {
            return;
        }

        $usedByCampaigns = DB::table('campaigns')
            ->whereIn('contact_group_id', $candidates)
            ->distinct()
            ->pluck('contact_group_id')
            ->map(fn ($id) => (int) $id);

        $groupIds = $candidates->diff($usedByCampaigns)->values();

        $deletedGroups = 0;
        $deletedLinks = 0;

        foreach ($groupIds->chunk(self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk, &$deletedGroups, &$deletedLinks) {
                $deletedLinks += DB::table('contact_contact_group')->whereIn('contact_group_id', $chunk)->delete();
                $deletedGroups += DB::table('contact_groups')->whereIn('id', $chunk)->delete();
            });
        }

        Log::info('Deleted mistaken "Men" contact groups', [
            'organization_id' => $organizationId,
            'deleted_groups' => $deletedGroups,
            'deleted_links' => $deletedLinks,
            'skipped_used_by_campaigns' => $usedByCampaigns->values()->all(),
        ]);
    }

    /** حذف بيانات نهائي: لا استرجاع إلا من نسخة احتياطية. */
    public function down(): void
    {
    }
};
