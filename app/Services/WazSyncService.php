<?php

namespace App\Services;

use App\Exceptions\WazBusinessException;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * الطبقة التي تربط سجلات منجز شات بمنصة واز أعمال.
 *
 * WazBusinessService يعرف الـHTTP فقط؛ هذه الطبقة تعرف معنى العمل: أي فاتورة
 * تُرسَل ومتى، وكيف تُقسَّم، وما الذي يمنع التكرار.
 *
 * كل دالة **مُتماثلة**: إعادة تشغيلها لا تُنشئ سجلاً مكرراً لأن وجود المعرّف
 * المحفوظ يعني أن المزامنة تمّت.
 */
class WazSyncService
{
    public function __construct(private WazBusinessService $waz)
    {
    }

    public function enabled(): bool
    {
        return $this->waz->isConfigured();
    }

    /**
     * معرّف الشركة في واز لمنشأة لدينا.
     */
    public function companyId(int $organizationId): ?int
    {
        $id = Organization::where('id', $organizationId)->value('waz_company_id');

        return $id ? (int) $id : null;
    }

    /**
     * معرّف جهة الاتصال في واز لمستخدم لدينا.
     */
    public function contactId(int $userId): ?int
    {
        $id = User::where('id', $userId)->value('waz_contact_id');

        return $id ? (int) $id : null;
    }

    /**
     * جهة اتصال الشركة الرئيسية في واز.
     *
     * التسجيل يُنشئ جهة اتصال للمالك وحده، أما من يُدعى للفريق لاحقاً — مدير أو
     * موظف — فلا نظير له هناك. بدون بديل تبقى تذكرته عندنا ولا يراها الدعم،
     * فنُسندها إلى جهة اتصال المنشأة الرئيسية: وصولها باسم الشركة أنفع من
     * ضياعها باسم صاحبها.
     */
    public function primaryContactId(int $companyId): ?int
    {
        return Cache::remember(
            'waz_primary_contact_' . $companyId,
            now()->addHours(6),
            function () use ($companyId) {
                $contacts = $this->waz->listContacts($companyId);
                $fallback = null;

                foreach ($contacts as $contact) {
                    if (!is_array($contact) || !isset($contact['id'])) {
                        continue;
                    }

                    if ((string) ($contact['is_primary'] ?? '') === '1') {
                        return (int) $contact['id'];
                    }

                    $fallback ??= (int) $contact['id'];
                }

                return $fallback;
            }
        );
    }

    /**
     * إصدار فاتورة (أو فاتورتين) في واز مقابل فاتورة محلية.
     *
     * التوثيق يشترط فصل رسوم التأسيس عن الاشتراك في فاتورتين مستقلتين، وعندنا
     * هما عمودان في صفّ واحد — فنُصدر فاتورة لكل مبلغ غير صفري.
     *
     * @throws WazBusinessException
     */
    public function syncInvoice(BillingInvoice $invoice): void
    {
        // الشراء يُنشئ الدفعة والفاتورة في معاملة واحدة، فيُطلق المراقب
        // وظيفتين تصلان الطابور معاً — ووظيفة الدفعة تُزامن الفاتورة أيضاً إن
        // لم تُزامَن. عاملان متوازيان يقرآن waz_invoice_id فارغاً في اللحظة
        // نفسها فيُصدر كلٌّ منهما فاتورة: يرى المحاسب فاتورتين لعملية واحدة،
        // إحداهما مدفوعة والأخرى مستحقّة. القفل يجعل الثانية تنتظر ثم تقرأ
        // المعرّف المحفوظ فتنصرف.
        $lock = Cache::lock('waz_sync_invoice_' . $invoice->id, 120);

        try {
            $lock->block(30);
        } catch (LockTimeoutException $e) {
            Log::warning('Waz sync: could not acquire invoice lock', ['invoice_id' => $invoice->id]);

            return;
        }

        try {
            // القراءة داخل القفل لا قبله، وإلا عملنا على نسخة سبقها غيرنا.
            $invoice->refresh();
            $this->createInvoicesFor($invoice);
        } finally {
            $lock->release();
        }
    }

    private function createInvoicesFor(BillingInvoice $invoice): void
    {
        $companyId = $this->companyId((int) $invoice->organization_id);
        if (!$companyId) {
            Log::info('Waz sync: skipping invoice, organization has no waz company', [
                'invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
            ]);

            return;
        }

        $billing = $this->billingAddress((int) $invoice->organization_id, $companyId);
        $planKey = $this->planKey($invoice);
        $cfg = config('waz.invoices');

        // رسوم التأسيس: فاتورة مستقلة، مرة واحدة.
        $setupFee = $this->setupGross($invoice);
        if ($setupFee > 0 && !$invoice->waz_setup_invoice_id) {
            $created = $this->waz->createInvoice([
                'company_id' => $companyId,
                'description' => $cfg['descriptions']['setup'],
                'long_description' => $cfg['long_descriptions']['setup'],
                'gross' => $setupFee,
                'billing' => $billing,
            ]);
            $invoice->waz_setup_invoice_id = $created['id'];
            $invoice->save();
        }

        // الاشتراك في الباقة.
        $planGross = $this->planGross($invoice);
        if ($planGross > 0 && !$invoice->waz_invoice_id) {
            $created = $this->waz->createInvoice([
                'company_id' => $companyId,
                'description' => $cfg['descriptions'][$planKey] ?? $cfg['descriptions']['start'],
                'long_description' => $cfg['long_descriptions']['plan'],
                'gross' => $planGross,
                'billing' => $billing,
            ]);
            $invoice->waz_invoice_id = $created['id'];
            $invoice->save();
        }
    }

    /**
     * حصّة الاشتراك من الفاتورة، شاملة الضريبة كما دفعها العميل.
     *
     * المرجع هو `total` — المبلغ المُحصَّل بعد خصم الكوبون — لا `subtotal` الذي
     * يسبق الخصم. وهو يشمل رسوم التأسيس (`netAmount` في SubscriptionService =
     * الوعاء + الضريبة + التأسيس)، فطرحُها لازم وإلا صدرت في فاتورتها وفي
     * فاتورة الباقة معاً. الخصم يقع كلّه على الاشتراك: رسوم التأسيس مبلغ مقطوع.
     */
    private function planGross(BillingInvoice $invoice): float
    {
        return round(max(0, $this->chargedTotal($invoice) - $this->setupGross($invoice)), 2);
    }

    private function setupGross(BillingInvoice $invoice): float
    {
        return round(min((float) ($invoice->setup_fee ?? 0), $this->chargedTotal($invoice)), 2);
    }

    /**
     * المبلغ المُحصَّل فعلاً. الفواتير السابقة للكوبونات فيها total = subtotal،
     * فالرجوع إلى subtotal عند غياب total يبقيها على قيمتها.
     */
    private function chargedTotal(BillingInvoice $invoice): float
    {
        $total = (float) ($invoice->total ?? 0);

        return round($total > 0 ? $total : (float) $invoice->subtotal, 2);
    }

    /**
     * تسجيل دفعة على الفاتورة المقابلة في واز.
     *
     * @throws WazBusinessException
     */
    public function syncPayment(BillingPayment $payment): void
    {
        if ($payment->waz_synced_at) {
            return;
        }

        $invoice = $payment->invoice_id
            ? BillingInvoice::find($payment->invoice_id)
            : null;

        if (!$invoice || (!$invoice->waz_invoice_id && !$invoice->waz_setup_invoice_id)) {
            Log::info('Waz sync: skipping payment, invoice not synced yet', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
            ]);

            return;
        }

        // الدفعة عندنا واحدة، وفاتورتها هناك اثنتان (التأسيس والاشتراك) — فتُوزَّع
        // عليهما بقدر استحقاق كلٍّ منهما. بلا توزيع تُسجَّل كاملة على واحدة
        // فتظهر مدفوعة بأكثر من قيمتها والأخرى غير مدفوعة.
        $remaining = round((float) $payment->amount, 2);
        $method = $payment->payment_method ?: ($payment->processor ?: 'Online');

        $dues = [
            [$invoice->waz_setup_invoice_id, $this->setupGross($invoice), '-setup'],
            [$invoice->waz_invoice_id, $this->planGross($invoice), ''],
        ];

        $split = $invoice->waz_setup_invoice_id && $invoice->waz_invoice_id;

        foreach ($dues as [$wazInvoiceId, $due, $suffix]) {
            if (!$wazInvoiceId || $remaining <= 0) {
                continue;
            }

            // آخر فاتورة تأخذ ما تبقّى كاملاً، فلا تضيع هللات التقريب.
            $share = $wazInvoiceId === $invoice->waz_invoice_id
                ? $remaining
                : round(min($remaining, $due), 2);

            if ($share <= 0) {
                continue;
            }

            // المنصة تعتبر معرّف العملية فريداً عالمياً لا لكل فاتورة: إرسال
            // القسطين بمعرّف واحد يجعلها ترفض الثاني وتردّ duplicate. فنلحق
            // لاحقة بقسط التأسيس ونُبقي معرّف البوابة كما هو لفاتورة الاشتراك.
            $transactionId = $payment->transaction_id;
            if ($split && $suffix && $transactionId) {
                $transactionId .= $suffix;
            }

            $this->waz->addPayment([
                'invoice_id' => (int) $wazInvoiceId,
                'amount' => $share,
                'payment_method' => $method,
                'transaction_id' => $transactionId,
            ]);

            $remaining = round($remaining - $share, 2);
        }

        $payment->waz_synced_at = now();
        $payment->save();
    }

    /**
     * فتح تذكرة الدعم في واز مقابل تذكرة محلية.
     *
     * @return bool هل وصلت التذكرة إلى واز فعلاً. القيمة false تعني أن الحساب
     *              غير مربوط، فالتذكرة محفوظة عندنا ولن يراها الدعم — وهو ما
     *              يجب إبلاغ العميل به بدل إظهار نجاح كامل.
     *
     * @throws WazBusinessException
     */
    public function syncTicket(Ticket $ticket, int $organizationId): bool
    {
        if ($ticket->waz_ticket_id) {
            return true;
        }

        $companyId = $this->companyId($organizationId);

        if (!$companyId) {
            Log::info('Waz sync: skipping ticket, organization has no waz company', [
                'ticket_id' => $ticket->id,
                'organization_id' => $organizationId,
            ]);

            return false;
        }

        $contactId = $this->contactId((int) $ticket->user_id)
            ?? $this->primaryContactId($companyId);

        if (!$contactId) {
            Log::info('Waz sync: skipping ticket, company has no contact', [
                'ticket_id' => $ticket->id,
                'company_id' => $companyId,
            ]);

            return false;
        }

        $this->waz->createTicket([
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'subject' => $ticket->subject,
            'message' => $ticket->message,
            'company_name' => Organization::where('id', $organizationId)->value('name'),
        ]);

        // التذكرة لا تُرجع معرّفها عند الإنشاء، فنقرأ قائمة تذاكر الشركة
        // ونطابق بالعنوان لالتقاط id و ticketkey.
        foreach ($this->waz->listTickets($companyId) as $row) {
            if (is_array($row) && ($row['subject'] ?? null) === $ticket->subject) {
                $ticket->waz_ticket_id = $row['id'] ?? null;
                $ticket->waz_ticket_key = $row['ticketkey'] ?? null;
                $ticket->save();
                break;
            }
        }

        return true;
    }

    /**
     * فواتير المنشأة كما هي في واز، مع رابط العرض لكل فاتورة.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invoicesFor(int $organizationId): array
    {
        $companyId = $this->companyId($organizationId);
        if (!$companyId) {
            return [];
        }

        $rows = [];
        foreach ($this->waz->listInvoices($companyId) as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }

            $rows[] = [
                'id' => $invoice['id'] ?? null,
                'number' => $invoice['number'] ?? null,
                'date' => $invoice['date'] ?? null,
                'duedate' => $invoice['duedate'] ?? null,
                'subtotal' => $invoice['subtotal'] ?? null,
                'total' => $invoice['total'] ?? null,
                'status' => $invoice['status'] ?? null,
                'currency_name' => $invoice['currency_name'] ?? null,
                'url' => isset($invoice['id'], $invoice['hash'])
                    ? $this->waz->invoiceUrl((int) $invoice['id'], (string) $invoice['hash'])
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * روابط عرض فواتير واز مفهرسة بمعرّف الفاتورة.
     *
     * الرابط يحتاج id و hash معاً، والـhash لا يُحفظ عندنا — يأتي من
     * `GET /api/invoices/?clientid=X` وحده. فنجلب القائمة مرة واحدة ونبني منها
     * خريطة، بدل نداء لكل فاتورة.
     *
     * @return array<int, string>  waz_invoice_id => رابط العرض
     */
    public function invoiceUrlsFor(int $organizationId): array
    {
        $companyId = $this->companyId($organizationId);
        if (!$companyId || !$this->enabled()) {
            return [];
        }

        $urls = [];
        foreach ($this->waz->listInvoices($companyId) as $invoice) {
            if (is_array($invoice) && isset($invoice['id'], $invoice['hash'])) {
                $urls[(int) $invoice['id']] = $this->waz->invoiceUrl(
                    (int) $invoice['id'],
                    (string) $invoice['hash']
                );
            }
        }

        return $urls;
    }

    /**
     * تذاكر المنشأة كما هي في واز، مع رابط العرض.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ticketsFor(int $organizationId): array
    {
        $companyId = $this->companyId($organizationId);
        if (!$companyId) {
            return [];
        }

        $rows = [];
        foreach ($this->waz->listTickets($companyId) as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $rows[] = [
                'id' => $ticket['id'] ?? null,
                'subject' => $ticket['subject'] ?? null,
                'status' => $ticket['status'] ?? null,
                'department' => $ticket['department'] ?? null,
                'priority' => $ticket['priority'] ?? null,
                'date' => $ticket['date'] ?? null,
                'url' => isset($ticket['ticketkey'])
                    ? $this->waz->ticketUrl((string) $ticket['ticketkey'])
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * مواعيد المنشأة وحدها.
     *
     * تنبيه أمني: `GET /api/calendar/` يرجع مواعيد **كل** العملاء بلا تصفية،
     * فلا يجوز عرض ناتجه كما هو. نصفّي بـ userid قبل أي عرض.
     *
     * @return array<int, array<string, mixed>>
     */
    public function meetingsFor(int $organizationId): array
    {
        $companyId = $this->companyId($organizationId);
        if (!$companyId) {
            return [];
        }

        $rows = [];
        foreach ($this->waz->listMeetings() as $meeting) {
            if (!is_array($meeting) || (int) ($meeting['userid'] ?? 0) !== $companyId) {
                continue;
            }

            $rows[] = [
                'id' => $meeting['eventid'] ?? null,
                'title' => $meeting['title'] ?? null,
                'description' => $meeting['description'] ?? null,
                'start' => $meeting['start'] ?? null,
                'color' => $meeting['color'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * إلغاء موعد بعد التأكد أنه يخصّ هذه المنشأة.
     *
     * المنصة تحذف بالمعرّف بلا فحص ملكية، فالتحقق مسؤوليتنا — وإلا أمكن
     * لعميل إلغاء موعد عميل آخر بتخمين الرقم.
     */
    public function cancelMeeting(int $organizationId, int $meetingId): bool
    {
        $owned = false;
        foreach ($this->meetingsFor($organizationId) as $meeting) {
            if ((int) $meeting['id'] === $meetingId) {
                $owned = true;
                break;
            }
        }

        if (!$owned) {
            Log::warning('Waz sync: refused to cancel a meeting that does not belong to the organization', [
                'organization_id' => $organizationId,
                'meeting_id' => $meetingId,
            ]);

            return false;
        }

        return $this->waz->deleteMeeting($meetingId);
    }

    /**
     * حذف جهة اتصال المستخدم من واز عند مغادرته المنشأة أو حذف حسابه.
     *
     * لا يرمي: إزالة العضو نجحت عندنا فعلاً، وفشل التنظيف في منصة خارجية
     * لا يصحّ أن يُبطلها.
     */
    public function removeContact(User $user): bool
    {
        if (!$user->waz_contact_id || !$this->enabled()) {
            return false;
        }

        $deleted = $this->waz->deleteContact((int) $user->waz_contact_id);

        if ($deleted) {
            $user->forceFill(['waz_contact_id' => null])->save();
        }

        return $deleted;
    }

    /**
     * عنوان الفوترة المحفوظ للمنشأة، بالشكل الذي تتوقعه المنصة.
     *
     * @return array<string, string>
     */
    private function billingAddress(int $organizationId, ?int $companyId = null): array
    {
        $address = json_decode(
            (string) Organization::where('id', $organizationId)->value('address'),
            true
        ) ?: [];

        $billing = [];
        foreach (['street', 'city', 'state', 'zip', 'country'] as $key) {
            $billing[$key] = trim((string) ($address[$key] ?? ''));
        }

        // المنصة ترفض الفاتورة كلّها إذا خلا billing_street — مُرسَلاً فارغاً أو
        // محذوفاً، جرّبتُ الحالتين — ولا ترثه من بطاقة العميل. وأكثر من نصف
        // منشآتنا بلا عنوان محفوظ (سُجّلت قبل أن يُطلب العنوان)، فكانت كل
        // فاتورة لها تفشل وتُعيد المحاولة بلا طائل. نستكمل الناقص ممّا سجّلته
        // المنصة نفسها وقت التسجيل، ثم بقيمة أخيرة كي تصدر الفاتورة.
        if ($billing['street'] === '' && $companyId) {
            try {
                foreach ($this->companyAddress($companyId) as $key => $value) {
                    if (($billing[$key] ?? '') === '') {
                        $billing[$key] = $value;
                    }
                }
            } catch (WazBusinessException $e) {
                Log::warning('Waz sync: could not read company address', [
                    'company_id' => $companyId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($billing['street'] === '') {
            $billing['street'] = (string) config('waz.invoices.fallback_billing_street');
        }

        return $billing;
    }

    /**
     * عنوان الشركة من المنصة، مُخزَّن مؤقتاً — الفاتورتان تُصدَران في نفس
     * المزامنة فلا داعي لطلبه مرتين.
     *
     * @return array<string, string>
     *
     * @throws WazBusinessException
     */
    private function companyAddress(int $companyId): array
    {
        return Cache::remember(
            'waz_company_address_' . $companyId,
            now()->addHours(6),
            fn () => $this->waz->companyBillingAddress($companyId)
        );
    }

    /**
     * مفتاح وصف الباقة في واز من اسم الباقة لدينا.
     */
    private function planKey(BillingInvoice $invoice): string
    {
        $name = strtolower((string) ($invoice->plan?->name ?? ''));

        return match (true) {
            str_contains($name, 'business') && str_contains($name, 'pro') => 'business_pro',
            str_contains($name, 'business') => 'business',
            str_contains($name, 'pro') => 'pro',
            default => 'start',
        };
    }
}
