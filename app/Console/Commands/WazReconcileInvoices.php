<?php

namespace App\Console\Commands;

use App\Exceptions\WazBusinessException;
use App\Models\BillingInvoice;
use App\Models\Organization;
use App\Services\WazBusinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * تقرير مطابقة فواتير واز — قراءة محضة.
 *
 * 23 فاتورة محلّية لمنشآت مربوطة بواز بقيت بلا معرّف، بينما عند واز فواتير
 * لا نعرف معرّفها. السبب أن رقم الفاتورة يُولَّد بـrandom_int لكل محاولة، فلو
 * أُنشئت الفاتورة عندهم وضاع الردّ (502 مثلاً) لم يبقَ ما يربط الطرفين —
 * وإعادة المحاولة تُنشئ فاتورة ثانية بدل أن تجد الأولى.
 *
 * هذا الأمر لا يُنشئ ولا يعدّل ولا يحذف شيئاً — لا محلّياً ولا عند واز. يقرأ
 * الطرفين ويُخرج ثلاث قوائم ليُتّخذ القرار على أساسها.
 */
class WazReconcileInvoices extends Command
{
    protected $signature = 'waz:reconcile-invoices
                            {--organization= : حصر التقرير بمنشأة واحدة}
                            {--tolerance=0.05 : فارق مقبول في المبلغ عند المطابقة}
                            {--json= : حفظ التقرير ملفّ JSON}';

    protected $description = 'تقرير مطابقة بين الفواتير المحلّية غير المُزامَنة وفواتير واز (قراءة فقط)';

    /** @var array<int, array<int, array<string, mixed>>> فواتير واز لكل شركة، تُقرأ مرّة */
    private array $remoteCache = [];

    /** @var array<int, true> معرّفات فواتير واز التي حُجزت لمطابقة */
    private array $claimed = [];

    public function handle(WazBusinessService $waz): int
    {
        $tolerance = (float) $this->option('tolerance');

        $this->line('');
        $this->info('تقرير مطابقة فواتير واز — قراءة فقط، لا يُنشئ ولا يعدّل شيئاً');
        $this->line(str_repeat('─', 78));

        $invoices = $this->pendingInvoices();
        if ($invoices->isEmpty()) {
            $this->info('لا توجد فواتير عالقة لمنشآت مربوطة بواز.');

            return self::SUCCESS;
        }

        // المعرّفات التي نعرفها سلفاً لا تُطابَق ثانيةً.
        foreach ($this->knownRemoteIds() as $id) {
            $this->claimed[$id] = true;
        }

        $matched = [];
        $ambiguous = [];
        $missing = [];
        $unreachable = [];

        foreach ($invoices as $invoice) {
            $companyId = (int) $invoice->waz_company_id;

            try {
                $remote = $this->remoteInvoices($waz, $companyId);
            } catch (WazBusinessException $e) {
                $unreachable[] = ['invoice' => $invoice, 'error' => $e->getMessage()];
                continue;
            }

            // الفاتورة المحلّية قد تُنتج سطرين عند واز: رسوم تأسيس واشتراك.
            foreach ($this->expectedLines($invoice) as $line) {
                $candidates = $this->candidatesFor($remote, $line['gross'], $tolerance);

                if ($candidates->isEmpty()) {
                    $missing[] = $line + ['invoice' => $invoice];
                    continue;
                }

                if ($candidates->count() === 1) {
                    $remoteInvoice = $candidates->first();
                    $this->claimed[(int) $remoteInvoice['id']] = true;
                    $matched[] = $line + ['invoice' => $invoice, 'remote' => $remoteInvoice];
                    continue;
                }

                $ambiguous[] = $line + ['invoice' => $invoice, 'remote' => $candidates->all()];
            }
        }

        $orphans = $this->orphanRemoteInvoices();

        $this->renderMatched($matched);
        $this->renderAmbiguous($ambiguous);
        $this->renderMissing($missing);
        $this->renderOrphans($orphans);
        $this->renderUnreachable($unreachable);

        $this->renderSummary($matched, $ambiguous, $missing, $orphans, $unreachable);

        if ($path = $this->option('json')) {
            $this->writeJson($path, $matched, $ambiguous, $missing, $orphans);
        }

        return self::SUCCESS;
    }

    /** الفواتير المحلّية العالقة لمنشآت مربوطة بواز. */
    private function pendingInvoices(): Collection
    {
        return BillingInvoice::query()
            ->select('billing_invoices.*', 'organizations.waz_company_id', 'organizations.name as organization_name')
            ->join('organizations', 'organizations.id', '=', 'billing_invoices.organization_id')
            ->whereNull('billing_invoices.waz_invoice_id')
            ->whereNull('billing_invoices.waz_setup_invoice_id')
            ->whereNotNull('organizations.waz_company_id')
            ->when($this->option('organization'), fn ($q, $id) => $q->where('billing_invoices.organization_id', $id))
            ->orderBy('billing_invoices.id')
            ->get();
    }

    /** @return array<int, int> */
    private function knownRemoteIds(): array
    {
        return BillingInvoice::query()
            ->whereNotNull('waz_invoice_id')->pluck('waz_invoice_id')
            ->merge(BillingInvoice::query()->whereNotNull('waz_setup_invoice_id')->pluck('waz_setup_invoice_id'))
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * السطور المتوقّعة لفاتورة محلّية — بنفس حساب WazSyncService.
     *
     * @return array<int, array{kind: string, gross: float}>
     */
    private function expectedLines(BillingInvoice $invoice): array
    {
        $charged = (float) ($invoice->total ?? 0);
        $charged = round($charged > 0 ? $charged : (float) $invoice->subtotal, 2);

        $setup = round(min((float) ($invoice->setup_fee ?? 0), $charged), 2);
        $plan = round(max(0, $charged - $setup), 2);

        $lines = [];
        if ($setup > 0) {
            $lines[] = ['kind' => 'رسوم تأسيس', 'gross' => $setup];
        }
        if ($plan > 0) {
            $lines[] = ['kind' => 'اشتراك', 'gross' => $plan];
        }

        return $lines;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws WazBusinessException
     */
    private function remoteInvoices(WazBusinessService $waz, int $companyId): array
    {
        if (!array_key_exists($companyId, $this->remoteCache)) {
            $this->remoteCache[$companyId] = array_values(array_filter(
                $waz->listInvoices($companyId),
                'is_array'
            ));
        }

        return $this->remoteCache[$companyId];
    }

    /**
     * فواتير واز المرشّحة لمبلغ معيّن: غير محجوزة، ومطابقة ضمن الفارق المقبول.
     *
     * @param  array<int, array<string, mixed>>  $remote
     */
    private function candidatesFor(array $remote, float $gross, float $tolerance): Collection
    {
        return collect($remote)
            ->reject(fn ($r) => isset($this->claimed[(int) ($r['id'] ?? 0)]))
            ->filter(fn ($r) => abs(round((float) ($r['total'] ?? 0), 2) - $gross) <= $tolerance)
            ->values();
    }

    /**
     * فواتير عند واز لم تُطابق شيئاً ولا نعرف معرّفها — تكرارات محتملة.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orphanRemoteInvoices(): array
    {
        $orphans = [];
        foreach ($this->remoteCache as $companyId => $invoices) {
            foreach ($invoices as $remote) {
                if (!isset($this->claimed[(int) ($remote['id'] ?? 0)])) {
                    $orphans[] = $remote + ['_company' => $companyId];
                }
            }
        }

        return $orphans;
    }

    private function renderMatched(array $rows): void
    {
        $this->line('');
        $this->info('◆ مطابقة واضحة — فاتورة واحدة عند واز بنفس المبلغ (تُربط بلا إنشاء)');

        if (!$rows) {
            $this->line('   لا شيء');

            return;
        }

        $this->table(
            ['محلّية', 'المنشأة', 'البند', 'المبلغ', 'واز #', 'رقمها', 'تاريخها'],
            array_map(fn ($r) => [
                '#' . $r['invoice']->id,
                mb_substr((string) $r['invoice']->organization_name, 0, 22),
                $r['kind'],
                number_format($r['gross'], 2),
                $r['remote']['id'],
                $r['remote']['formatted_number'] ?? $r['remote']['number'] ?? '—',
                $r['remote']['date'] ?? '—',
            ], $rows)
        );
    }

    private function renderAmbiguous(array $rows): void
    {
        $this->line('');
        $this->warn('◆ تكرار محتمل — أكثر من فاتورة عند واز بنفس المبلغ (تحتاج قراراً بشرياً)');

        if (!$rows) {
            $this->line('   لا شيء');

            return;
        }

        foreach ($rows as $r) {
            $this->line(sprintf(
                '   محلّية #%d · %s · %s · %s ريال — عند واز %d فواتير بنفس المبلغ:',
                $r['invoice']->id,
                mb_substr((string) $r['invoice']->organization_name, 0, 22),
                $r['kind'],
                number_format($r['gross'], 2),
                count($r['remote'])
            ));
            foreach ($r['remote'] as $remote) {
                $this->line(sprintf(
                    '        واز #%-6s رقم %-16s تاريخ %-12s حالة %s',
                    $remote['id'],
                    $remote['formatted_number'] ?? $remote['number'] ?? '—',
                    $remote['date'] ?? '—',
                    $remote['status'] ?? '—'
                ));
            }
        }
    }

    private function renderMissing(array $rows): void
    {
        $this->line('');
        $this->error('◆ مفقودة — لا فاتورة عند واز بهذا المبلغ (تحتاج إنشاءً)');

        if (!$rows) {
            $this->line('   لا شيء');

            return;
        }

        $this->table(
            ['محلّية', 'المنشأة', 'شركة واز', 'البند', 'المبلغ'],
            array_map(fn ($r) => [
                '#' . $r['invoice']->id,
                mb_substr((string) $r['invoice']->organization_name, 0, 22),
                $r['invoice']->waz_company_id,
                $r['kind'],
                number_format($r['gross'], 2),
            ], $rows)
        );
    }

    private function renderOrphans(array $rows): void
    {
        $this->line('');
        $this->warn('◆ عند واز ولا مقابل لها محلّياً — تكرارات أو فواتير أُنشئت يدوياً');

        if (!$rows) {
            $this->line('   لا شيء');

            return;
        }

        $this->table(
            ['واز #', 'شركة', 'رقمها', 'المبلغ', 'تاريخها', 'حالة'],
            array_map(fn ($r) => [
                $r['id'],
                $r['_company'],
                $r['formatted_number'] ?? $r['number'] ?? '—',
                number_format((float) ($r['total'] ?? 0), 2),
                $r['date'] ?? '—',
                $r['status'] ?? '—',
            ], $rows)
        );
    }

    private function renderUnreachable(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $this->line('');
        $this->error('◆ تعذّرت قراءتها من واز');
        foreach ($rows as $r) {
            $this->line(sprintf('   محلّية #%d — %s', $r['invoice']->id, $r['error']));
        }
    }

    private function renderSummary(array $matched, array $ambiguous, array $missing, array $orphans, array $unreachable): void
    {
        $sum = fn (array $rows) => array_sum(array_column($rows, 'gross'));

        $this->line('');
        $this->line(str_repeat('─', 78));
        $this->info('الخلاصة');
        $this->table(
            ['التصنيف', 'العدد', 'المبلغ', 'الإجراء'],
            [
                ['مطابقة واضحة', count($matched), number_format($sum($matched), 2), 'تُربط محلّياً — بلا إنشاء'],
                ['تكرار محتمل', count($ambiguous), number_format($sum($ambiguous), 2), 'قرار بشري'],
                ['مفقودة', count($missing), number_format($sum($missing), 2), 'تحتاج إنشاءً'],
                ['يتيمة عند واز', count($orphans), number_format(array_sum(array_map(fn ($o) => (float) ($o['total'] ?? 0), $orphans)), 2), 'مراجعة'],
                ['تعذّرت قراءتها', count($unreachable), '—', 'إعادة التشغيل'],
            ]
        );
        $this->line('');
        $this->comment('لم يُنشأ ولم يُعدَّل ولم يُحذف شيء — لا محلّياً ولا عند واز.');
        $this->line('');
    }

    private function writeJson(string $path, array $matched, array $ambiguous, array $missing, array $orphans): void
    {
        $strip = fn (array $rows) => array_map(fn ($r) => [
            'local_invoice_id' => $r['invoice']->id,
            'organization_id' => $r['invoice']->organization_id,
            'organization' => $r['invoice']->organization_name,
            'waz_company_id' => (int) $r['invoice']->waz_company_id,
            'kind' => $r['kind'],
            'gross' => $r['gross'],
            'remote' => $r['remote'] ?? null,
        ], $rows);

        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'matched' => $strip($matched),
            'ambiguous' => $strip($ambiguous),
            'missing' => $strip($missing),
            'orphans' => $orphans,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("حُفظ التقرير في {$path}");
    }
}
