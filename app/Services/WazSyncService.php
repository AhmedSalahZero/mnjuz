<?php

namespace App\Services;

use App\Exceptions\WazBusinessException;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
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
     * إصدار فاتورة (أو فاتورتين) في واز مقابل فاتورة محلية.
     *
     * التوثيق يشترط فصل رسوم التأسيس عن الاشتراك في فاتورتين مستقلتين، وعندنا
     * هما عمودان في صفّ واحد — فنُصدر فاتورة لكل مبلغ غير صفري.
     *
     * @throws WazBusinessException
     */
    public function syncInvoice(BillingInvoice $invoice): void
    {
        $companyId = $this->companyId((int) $invoice->organization_id);
        if (!$companyId) {
            Log::info('Waz sync: skipping invoice, organization has no waz company', [
                'invoice_id' => $invoice->id,
                'organization_id' => $invoice->organization_id,
            ]);

            return;
        }

        $billing = $this->billingAddress((int) $invoice->organization_id);
        $planKey = $this->planKey($invoice);
        $cfg = config('waz.invoices');

        // رسوم التأسيس: فاتورة مستقلة، مرة واحدة.
        $setupFee = (float) ($invoice->setup_fee ?? 0);
        if ($setupFee > 0 && !$invoice->waz_setup_invoice_id) {
            $created = $this->waz->createInvoice([
                'company_id' => $companyId,
                'description' => $cfg['descriptions']['setup'],
                'long_description' => $cfg['long_descriptions']['setup'],
                'rate' => $setupFee,
                'billing' => $billing,
            ]);
            $invoice->waz_setup_invoice_id = $created['id'];
            $invoice->save();
        }

        // الاشتراك في الباقة.
        $subtotal = (float) $invoice->subtotal;
        if ($subtotal > 0 && !$invoice->waz_invoice_id) {
            $created = $this->waz->createInvoice([
                'company_id' => $companyId,
                'description' => $cfg['descriptions'][$planKey] ?? $cfg['descriptions']['start'],
                'long_description' => $cfg['long_descriptions']['plan'],
                'rate' => $subtotal,
                'billing' => $billing,
            ]);
            $invoice->waz_invoice_id = $created['id'];
            $invoice->save();
        }
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

        // الدفعة تُسجَّل على فاتورة الاشتراك؛ رسوم التأسيس تُسدَّد بفاتورتها.
        $wazInvoiceId = $invoice?->waz_invoice_id ?? $invoice?->waz_setup_invoice_id;

        if (!$wazInvoiceId) {
            Log::info('Waz sync: skipping payment, invoice not synced yet', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
            ]);

            return;
        }

        $this->waz->addPayment([
            'invoice_id' => (int) $wazInvoiceId,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method ?: ($payment->processor ?: 'Online'),
            'transaction_id' => $payment->transaction_id,
        ]);

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
        $contactId = $this->contactId((int) $ticket->user_id);

        if (!$companyId || !$contactId) {
            Log::info('Waz sync: skipping ticket, missing waz ids', [
                'ticket_id' => $ticket->id,
                'company_id' => $companyId,
                'contact_id' => $contactId,
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
    private function billingAddress(int $organizationId): array
    {
        $address = json_decode(
            (string) Organization::where('id', $organizationId)->value('address'),
            true
        ) ?: [];

        return [
            'street' => (string) ($address['street'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'state' => (string) ($address['state'] ?? ''),
            'zip' => (string) ($address['zip'] ?? ''),
            'country' => (string) ($address['country'] ?? ''),
        ];
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
