<?php

namespace App\Console\Commands;

use App\Support\JsonText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يحوّل حقول metadata المخزّنة بالتهريب (ا) إلى نصّ عربي خام.
 *
 * الصيغتان متكافئتان في معيار JSON — أي مُحلِّل يعطي النصّ نفسه — لكن المهرَّبة
 * تشغل ثلاثة أضعاف. قِسنا على عيّنة 20 ألف صفّ: chats.metadata عبر كل المنشآت
 * 2155 ميغابايت اليوم، تنزل إلى ~972 بعد التحويل.
 *
 * ضمانة عدم الفقد: لا يُكتب أي صفّ إلا بعد إثبات أن فكّه ثم إعادة ترميزه
 * بالرايات الأصلية تُعيد بايتاته الأصلية حرفياً (انظر JsonText::reencodeLossless).
 * ما لا يجتاز البرهان يُترك دون مساس. والناتج الخام لا يكون أطول من الأصل أبداً
 * فلا يمكن أن يُقتطع.
 *
 * لا يمسّ updated_at: نكتب عبر query builder لا Eloquent، فترتيب المزامنة
 * عند العملاء لا يتأثر ولا تُعاد الرسائل القديمة إليهم.
 *
 * المرور بالمعرّف لا بالإزاحة، فالأمر قابل للإيقاف والاستئناف من --start-id.
 */
class UnescapeChatMetadata extends Command
{
    protected $signature = 'chat:unescape-metadata
                            {--table=chats : الجدول (chats أو chat_status_logs)}
                            {--chunk=500 : عدد الصفوف في الدفعة}
                            {--start-id=0 : ابدأ بعد هذا المعرّف (للاستئناف)}
                            {--limit=0 : أوقف بعد هذا العدد من الصفوف الممسوحة (0 = بلا حدّ)}
                            {--dry-run : احسب الأثر دون أي كتابة}';

    protected $description = 'Rewrite escaped \uXXXX metadata as raw UTF-8, skipping any row whose round-trip cannot be proven lossless';

    private const TABLES = ['chats', 'chat_status_logs'];

    public function handle(): int
    {
        $table = (string) $this->option('table');
        if (!in_array($table, self::TABLES, true)) {
            $this->error(sprintf('--table يقبل %s فقط.', implode(' أو ', self::TABLES)));

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $lastId = max(0, (int) $this->option('start-id'));
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$this->confirm(sprintf('سيُعاد ترميز %s.metadata فعلياً. هل أخذت نسخة احتياطية؟', $table), false)) {
            $this->warn('أُلغي. شغّله بـ --dry-run أولاً لمعاينة الأثر.');

            return self::FAILURE;
        }

        $scanned = 0;
        $converted = 0;
        $skipped = 0;
        $bytesBefore = 0;
        $bytesAfter = 0;
        $verifyFailures = 0;

        $this->info(sprintf('يمسح %s ابتداءً من المعرّف %d%s...', $table, $lastId, $dryRun ? ' [معاينة]' : ''));
        $bar = $this->output->createProgressBar();
        $bar->start();

        do {
            $rows = DB::table($table)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'metadata']);

            if ($rows->isEmpty()) {
                break;
            }

            $updates = [];
            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $scanned++;

                $old = (string) $row->metadata;
                $new = JsonText::reencodeLossless($old);

                if ($new === null) {
                    // إمّا لا جديد فيه، وإمّا تعذّر إثبات سلامة الدورة.
                    // نعدّ الثاني فقط: الأول ليس تخطّياً بل لا شيء ليُفعل.
                    if ($old !== '' && json_encode(json_decode($old)) !== $old) {
                        $skipped++;
                    }

                    continue;
                }

                $updates[$lastId] = $new;
                $bytesBefore += strlen($old);
                $bytesAfter += strlen($new);
            }

            if ($updates && !$dryRun) {
                DB::transaction(function () use ($table, $updates) {
                    foreach ($updates as $id => $new) {
                        DB::table($table)->where('id', $id)->update(['metadata' => $new]);
                    }
                });

                // نقرأ ما كُتب فعلاً ونتحقّق أنه يفكّ إلى القيمة نفسها. الاقتطاع
                // مستحيل نظرياً (الخام أقصر دائماً) لكن الضمانة أرخص من الثقة.
                $stored = DB::table($table)->whereIn('id', array_keys($updates))->pluck('metadata', 'id');
                foreach ($updates as $id => $new) {
                    if (($stored[$id] ?? null) !== $new) {
                        $verifyFailures++;
                        $this->newLine();
                        $this->error(sprintf('تحقّق ما بعد الكتابة فشل عند المعرّف %d — أوقفنا الأمر.', $id));

                        return self::FAILURE;
                    }
                }
            }

            $converted += count($updates);
            $bar->advance($rows->count());
        } while ($rows->count() === $chunk && ($limit === 0 || $scanned < $limit));

        $bar->finish();
        $this->newLine(2);

        $this->table(['البند', 'القيمة'], [
            ['صفوف مُسحت', number_format($scanned)],
            ['صفوف حُوّلت', number_format($converted)],
            ['صفوف تُخطّيت (تعذّر البرهان)', number_format($skipped)],
            ['فشل تحقّق بعد الكتابة', number_format($verifyFailures)],
            ['حجم المحوَّل قبل', sprintf('%.2f MB', $bytesBefore / 1048576)],
            ['حجم المحوَّل بعد', sprintf('%.2f MB', $bytesAfter / 1048576)],
            ['الموفَّر', sprintf('%.2f MB', ($bytesBefore - $bytesAfter) / 1048576)],
            ['آخر معرّف', number_format($lastId)],
        ]);

        if ($dryRun) {
            $this->warn('معاينة فقط — لم يُكتب شيء. أعد التشغيل بلا --dry-run للتنفيذ.');
        } else {
            $this->info(sprintf('للاستئناف بعد انقطاع: php artisan chat:unescape-metadata --table=%s --start-id=%d', $table, $lastId));
            $this->comment('المساحة على القرص لا تُستردّ إلا بـ OPTIMIZE TABLE، وهو يعيد بناء الجدول — شغّله خارج الذروة.');
        }

        return self::SUCCESS;
    }
}
