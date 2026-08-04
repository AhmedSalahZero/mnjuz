<?php

namespace App\Console\Commands;

use App\Services\CampaignRetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يعلّم رسائل الحملات التي أبلغ واتساب عن فشلها عبر webhook كـ failed في
 * campaign_logs، حتى يراها زر «إعادة إرسال الحملات الفاشلة».
 *
 * قبل هذا الإصلاح كان بلاغ الفشل يُحدّث chats.status وحده ويبقى campaign_logs
 * على "success"، فلا يجد الزر شيئاً يعيد إرساله.
 *
 * لا يرسل أي رسالة ولا يجدول إعادة محاولة — يصحّح الحالة فقط، والقرار يبقى
 * بيد العميل عبر الزر. الفشل النهائي (رقم غير مسجّل، مستلم رافض) يُستثنى.
 */
class BackfillCampaignWebhookFailures extends Command
{
    protected $signature = 'campaigns:backfill-webhook-failures
                            {--days=30 : كم يوماً للخلف نعالج}
                            {--chunk=1000 : حجم الدفعة}
                            {--dry-run : اعرض ما سيحدث دون تعديل}';

    protected $description = 'Mark campaign logs whose WhatsApp webhook reported failure as failed so they can be resent';

    public function handle(CampaignRetryService $retryService): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subDays($days);

        $this->info(sprintf(
            'Scanning campaign messages failed via webhook since %s%s...',
            $since->toDateTimeString(),
            $dryRun ? ' [DRY RUN]' : ''
        ));

        $candidates = DB::table('campaign_logs as cl')
            ->join('chats as ch', 'ch.id', '=', 'cl.chat_id')
            ->join('campaigns as c', 'c.id', '=', 'cl.campaign_id')
            ->whereNull('c.deleted_at')
            ->where('cl.status', 'success')
            ->where('ch.status', 'failed')
            ->where('ch.created_at', '>=', $since)
            ->select('cl.id', 'cl.chat_id')
            ->orderBy('cl.id');

        $total = (clone $candidates)->count();
        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} candidate(s).");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $marked = 0;
        $skippedPermanent = 0;
        $skippedUnknown = 0;

        // chunkById لا chunk: الأخيرة تستخدم OFFSET، ومع الـ join يصبح المرور
        // على عشرات الآلاف من الصفوف تربيعياً وبطيئاً جداً.
        $candidates->chunkById($chunk, function ($rows) use (&$marked, &$skippedPermanent, &$skippedUnknown, $retryService, $dryRun, $bar) {
            $chatIds = $rows->pluck('chat_id')->all();
            $errorsByChat = $this->latestFailureErrors($chatIds);

            $retryableLogIds = [];

            foreach ($rows as $row) {
                if (!array_key_exists($row->chat_id, $errorsByChat)) {
                    // لا يوجد سجل حالة فاشلة — لا نعرف السبب فلا نخاطر بإعادة الإرسال.
                    $skippedUnknown++;
                    continue;
                }

                if (!$retryService->isRetryableFailure($errorsByChat[$row->chat_id])) {
                    $skippedPermanent++;
                    continue;
                }

                $retryableLogIds[] = $row->id;
            }

            if ($retryableLogIds && !$dryRun) {
                DB::table('campaign_logs')
                    ->whereIn('id', $retryableLogIds)
                    ->update(['status' => 'failed', 'updated_at' => now()]);
            }

            $marked += count($retryableLogIds);
            $bar->advance($rows->count());
        }, 'cl.id', 'id');

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Outcome', 'Count'],
            [
                [$dryRun ? 'Would be marked failed' : 'Marked failed (resendable)', $marked],
                ['Skipped — permanent failure', $skippedPermanent],
                ['Skipped — no failure detail', $skippedUnknown],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * أكواد الخطأ لكل محادثة من آخر بلاغ فشل مسجّل لها.
     *
     * @param  array<int, int>  $chatIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function latestFailureErrors(array $chatIds): array
    {
        if (!$chatIds) {
            return [];
        }

        $errorsByChat = [];

        $rows = DB::table('chat_status_logs')
            ->whereIn('chat_id', $chatIds)
            ->where('metadata', 'like', '%"status":"failed"%')
            ->orderBy('id')
            ->get(['chat_id', 'metadata']);

        foreach ($rows as $row) {
            $decoded = json_decode($row->metadata, true);
            if (($decoded['status'] ?? null) !== 'failed') {
                continue;
            }

            // الأحدث يفوز — نمرّ تصاعدياً بالمعرّف.
            $errorsByChat[$row->chat_id] = $decoded['errors'] ?? [];
        }

        return $errorsByChat;
    }
}
