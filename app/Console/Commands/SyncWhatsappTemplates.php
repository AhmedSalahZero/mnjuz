<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\TemplateSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * مزامنة قوالب واتساب من Meta.
 *
 * ليست تحسيناً احتياطياً — تُعالج عطباً قائماً. والتفريق هنا مهمّ:
 *
 *   • **صورة القالب** عند Meta **لا تنتهي**. تبقى ما بقي القالب.
 *   • **الرابط** الذي نخزّنه إذنُ وصولٍ موقَّع ينتهي بعد نحو ثلاثين يوماً
 *     (`oe=` في آخره)، فيردّ CDN واتساب 403 والصورة سليمة في مكانها.
 *
 * أثبتناه على الإنتاج (قالب 639): رابطنا ميّت ويردّ 403، وسألنا Meta
 * فأعطت رابطاً جديداً يردّ 200 — ومعرّف الملف في الرابطين واحد. نفس
 * الصورة، توقيع جديد.
 *
 * وقياس ٢٣ سبتمبر ٢٠٢٦: **١٣٥ من ٢٤٤ قالباً** روابطها ميّتة — أي أن معاينة
 * أكثر من نصف القوالب مكسورة، ولم يلاحظه أحد لأن الشكوى وصلت باسم «الصورة
 * القديمة».
 *
 * فهذه المهمّة **تُجدّد الإذن لا الصورة**.
 *
 * ولماذا أمر جديد لا WhatsappService::syncTemplates؟ لأن تلك تقرأ
 * session() و auth() — تعمل من المتصفّح ولا تعمل من المجدول إطلاقاً.
 */
class SyncWhatsappTemplates extends Command
{
    protected $signature = 'templates:sync
                            {--organization= : قصر المزامنة على منشأة بعينها}
                            {--max-seconds= : حدّ زمني، يتوقّف بعده ويترك الباقي للدورة التالية}';

    protected $description = 'Refresh WhatsApp template metadata from Meta (keeps header image URLs alive)';

    public function handle(TemplateSyncService $sync): int
    {
        $query = Organization::query()->whereNull('deleted_at');

        if ($organizationId = $this->option('organization')) {
            $query->where('id', (int) $organizationId);
        }

        $organizations = $query->orderBy('id')->get(['id', 'name', 'metadata']);

        $totals = ['orgs' => 0, 'updated' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0, 'templates_failed' => 0];

        // الحدّ الزمني للمواضع التي لا تحتمل انتظاراً مفتوحاً — كالترحيل
        // الذي يعمل داخل deploy.sh والعمّال متوقّفون. ما لم يُزامَن تُكمله
        // الدورة الليلية، فالتوقّف هنا تأجيل لا فقدان.
        // صفر قيمة مشروعة تعني «لا وقت متبقٍّ» — يتوقّف قبل أول منشأة.
        // والفحص في رأس الحلقة لا في ذيلها، فلا تبدأ منشأة لن يتّسع وقتها.
        $maxSeconds = $this->option('max-seconds') !== null
            ? max(0, (int) $this->option('max-seconds'))
            : null;
        $startedAt = microtime(true);

        foreach ($organizations as $index => $organization) {
            if ($maxSeconds !== null && (microtime(true) - $startedAt) >= $maxSeconds) {
                $totals['remaining'] = $organizations->count() - $index;
                $this->warn("توقّفنا عند الحدّ الزمني ({$maxSeconds}s) — بقي {$totals['remaining']} منشأة للدورة التالية.");
                break;
            }

            // المنشأة غير المربوطة بواتساب لا قوالب لها — نتخطّاها بلا نداء.
            if (!$this->hasWhatsapp($organization)) {
                $totals['skipped']++;

                continue;
            }

            // الحصر هنا هو آخر خطّ: منشأة واحدة تُعثّر — بخطأ قاعدة بيانات
            // أو غيره — لا يجوز أن توقف مزامنة التسع والتسعين الباقية.
            // وقع فعلاً: قالب باسم طويل رمى 1406 فخرج الاستثناء إلى الترحيل
            // وأوقف كل شيء.
            try {
                $result = $sync->refreshOrganization((int) $organization->id);
            } catch (\Throwable $e) {
                $totals['failed']++;

                $this->warn("#{$organization->id} {$organization->name}: {$e->getMessage()}");

                Log::error('Template sync threw for organization', [
                    'organization_id' => $organization->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $totals['orgs']++;
            $totals['templates_failed'] += $result['failed'] ?? 0;

            if ($result['error']) {
                $totals['failed']++;

                // منشأة واحدة فاشلة لا توقف البقيّة: توكن منتهٍ عند عميل
                // لا يجوز أن يمنع تجديد روابط بقيّة العملاء.
                $this->warn("#{$organization->id} {$organization->name}: {$result['error']}");

                Log::warning('Template sync failed for organization', [
                    'organization_id' => $organization->id,
                    'error' => $result['error'],
                ]);

                continue;
            }

            $totals['updated'] += $result['updated'];
            $totals['created'] += $result['created'];

            if ($this->output->isVerbose()) {
                $this->line("#{$organization->id} {$organization->name}: "
                    . "updated={$result['updated']} created={$result['created']}");
            }
        }

        $this->info(sprintf(
            'Synced %d organizations — %d templates updated, %d created, %d skipped, %d failed, %d deferred.',
            $totals['orgs'],
            $totals['updated'],
            $totals['created'],
            $totals['skipped'],
            $totals['failed'],
            $totals['remaining']
        ));

        // القوالب المتخطّاة تُذكر صراحةً: الصمت عنها يجعل المزامنة تبدو
        // ناجحة تماماً بينما قالب أو أكثر لم يصل.
        if ($totals['templates_failed'] > 0) {
            $this->warn("تُخطّي {$totals['templates_failed']} قالباً لتعذّر حفظه — التفاصيل في السجلّ.");
        }

        return self::SUCCESS;
    }

    private function hasWhatsapp(Organization $organization): bool
    {
        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsapp = $metadata['whatsapp'] ?? null;

        return is_array($whatsapp)
            && !empty($whatsapp['access_token'])
            && !empty($whatsapp['waba_id']);
    }
}
