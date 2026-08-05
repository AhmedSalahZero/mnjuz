<?php

namespace App\Services;

use App\Exceptions\WazBusinessException;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * عميل منصة واز أعمال (business.waz.com.sa).
 *
 * عند التسجيل نُنشئ Company ثم Contact مربوطة بها. أي فشل يرمي
 * WazBusinessException ليوقف التسجيل — الحساب لا يُنشأ محلياً إلا بعد نجاح
 * الربط، وهو قرار مقصود لضمان تطابق المنصتين.
 */
class WazBusinessService
{
    public function isConfigured(): bool
    {
        return !empty(config('waz.token')) && !empty(config('waz.base_url'));
    }

    /**
     * معرّف الدولة الرقمي كما تقبله المنصة. يقبل المعرّف نفسه أو اسم الدولة.
     */
    public function countryId(string|int|null $country): ?int
    {
        if ($country === null || $country === '') {
            return null;
        }

        $map = config('waz_countries', []);

        if (is_numeric($country)) {
            return in_array((int) $country, $map, true) ? (int) $country : null;
        }

        return $map[$country] ?? null;
    }

    /**
     * قائمة الدول للعرض في نموذج التسجيل: القيمة هي المعرّف الذي ترسله المنصة،
     * و flag هو رمز ISO الذي يختار العلم من الـsprite في الواجهة.
     *
     * @return array<int, array{value: int, label: string, flag: ?string}>
     */
    public function countryOptions(): array
    {
        $flags = config('waz_country_flags', []);

        $options = [];
        foreach (config('waz_countries', []) as $name => $id) {
            $options[] = [
                'value' => $id,
                'label' => $name,
                'flag' => $flags[$name] ?? null,
            ];
        }

        return $options;
    }

    /**
     * إنشاء شركة (عميل) وإرجاع معرّفها.
     *
     * @param  array{company: string, phone: string, email?: string, vat?: ?string, website?: ?string, street: string, city: string, state: string, zip: string, country_id: int, language?: ?string}  $data
     *
     * @throws WazBusinessException
     */
    public function createCompany(array $data): int
    {
        $response = [];

        try {
            $response = $this->post('/api/customers/', $this->companyPayload($data));
        } catch (WazBusinessException $e) {
            // المنصة بطيئة أحياناً فتُنفّذ الطلب ثم تتأخر استجابتها عن المهلة.
            // اعتبار ذلك فشلاً يجعل التسجيل يتراجع ثم يُعيد المحاولة فتتكرّر
            // الشركة. فعند انقطاع الاتصال نبحث عمّا إذا كانت قد أُنشئت فعلاً.
            if (!$e->connectionFailed) {
                throw $e;
            }

            $id = $this->findCompanyIdByName($data['company']);
            if ($id !== null) {
                Log::warning('Waz: company creation timed out but the record exists', [
                    'company' => $data['company'],
                    'company_id' => $id,
                ]);

                return $id;
            }

            throw $e;
        }

        // المنصة ترجع {"status":true,"message":"Client add successful."} بلا معرّف،
        // فنبحث عن الشركة التي أنشأناها للتوّ. نحتفظ بمحاولة القراءة المباشرة
        // تحسّباً لإصدار يرجع المعرّف لاحقاً.
        $id = $this->idFromResponse($response);
        if ($id !== null) {
            return $id;
        }

        $id = $this->findCompanyIdByName($data['company']);
        if ($id !== null) {
            return $id;
        }

        Log::error('Waz: company created but its id could not be resolved', [
            'company' => $data['company'],
            'body' => $response,
        ]);

        throw new WazBusinessException('Waz Business did not return a company id.');
    }

    /**
     * حمولة الشركة — مشتركة بين الإنشاء والتحديث حتى لا يتباعدا.
     * العنوان الواحد يملأ عنواني الفوترة والشحن؛ لا حقول منفصلة في النموذج.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function companyPayload(array $data): array
    {
        $defaults = config('waz.defaults');

        $payload = [
            'company' => $data['company'],
            'phonenumber' => $data['phone'],
            'groups_in[]' => $defaults['group_id'],
            'default_currency' => $defaults['currency'],
            'default_language' => $this->language($data['language'] ?? null),
            'address' => $data['street'],
            'city' => $data['city'],
            'state' => $data['state'],
            'zip' => $data['zip'],
            'country' => (string) $data['country_id'],
            'billing_street' => $data['street'],
            'billing_city' => $data['city'],
            'billing_state' => $data['state'],
            'billing_zip' => $data['zip'],
            'billing_country' => (string) $data['country_id'],
            'shipping_street' => $data['street'],
            'shipping_city' => $data['city'],
            'shipping_state' => $data['state'],
            'shipping_zip' => $data['zip'],
            'shipping_country' => (string) $data['country_id'],
            'custom_fields[customers][' . $defaults['source_custom_field'] . ']' => $defaults['source'],
        ];

        if (!empty($data['vat'])) {
            $payload['vat'] = $data['vat'];
            $payload['custom_fields[customers][' . $defaults['vat_custom_field'] . ']'] = $data['vat'];
        }

        if (!empty($data['website'])) {
            $payload['website'] = $data['website'];
        }

        return $payload;
    }

    /**
     * البحث عن شركة بالاسم لاسترجاع معرّفها.
     *
     * البحث يطابق اسم الشركة فقط (جرّبنا الهاتف والرقم الضريبي فلم يطابقا)،
     * والمسافة داخل المسار يحجبها Apache بـ403 — فنبحث بأطول كلمة بلا مسافات
     * ثم نطابق الاسم كاملاً على النتائج.
     */
    private function findCompanyIdByName(string $company): ?int
    {
        $rows = $this->get('/api/customers/search/' . rawurlencode($this->searchToken($company)));

        $matches = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['company'] ?? null) === $company && isset($row['userid'])) {
                $matches[] = (int) $row['userid'];
            }
        }

        // الأحدث يفوز لو تكرّر الاسم بين عملاء مختلفين.
        return $matches ? max($matches) : null;
    }

    /**
     * أطول كلمة بلا مسافات من النص — مفتاح بحث صالح داخل مسار URL.
     */
    private function searchToken(string $value): string
    {
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$words) {
            return $value;
        }

        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $words[0];
    }

    /**
     * إنشاء جهة اتصال أساسية مربوطة بالشركة وإرجاع معرّفها.
     *
     * @param  array{first_name: string, last_name: string, email: string, phone: string, password: string}  $data
     *
     * @throws WazBusinessException
     */
    public function createContact(int $companyId, array $data): int
    {
        $defaults = config('waz.defaults');

        $payload = [
            'customer_id' => (string) $companyId,
            'firstname' => $data['first_name'],
            'lastname' => $data['last_name'],
            'email' => $data['email'],
            'title' => $defaults['contact_title'],
            'phonenumber' => $data['phone'],
            'password' => $data['password'],
            'is_primary' => $defaults['contact_is_primary'],
        ];

        foreach ($defaults['contact_permissions'] as $index => $permission) {
            $payload["permissions[{$index}]"] = $permission;
        }

        // كل إشعارات الفواتير والتذاكر وغيرها تذهب لنفس البريد المُدخل.
        foreach ($defaults['contact_email_fields'] as $field) {
            $payload[$field] = $data['email'];
        }

        $response = [];

        try {
            $response = $this->post('/api/contacts/', $payload);
        } catch (WazBusinessException $e) {
            // نفس معالجة الشركة: مهلة منتهية لا تعني بالضرورة فشلاً.
            if (!$e->connectionFailed) {
                throw $e;
            }

            $id = $this->findContactIdByEmail($companyId, $data['email']);
            if ($id !== null) {
                Log::warning('Waz: contact creation timed out but the record exists', [
                    'company_id' => $companyId,
                    'contact_id' => $id,
                ]);

                return $id;
            }

            throw $e;
        }

        // كذلك جهة الاتصال ترجع رسالة بلا معرّف، فنقرأ جهات اتصال الشركة
        // ونطابق بالبريد الذي أنشأناه به.
        $id = $this->idFromResponse($response);
        if ($id !== null) {
            return $id;
        }

        $id = $this->findContactIdByEmail($companyId, $data['email']);
        if ($id !== null) {
            return $id;
        }

        Log::error('Waz: contact created but its id could not be resolved', [
            'company_id' => $companyId,
            'email' => $data['email'],
            'body' => $response,
        ]);

        throw new WazBusinessException('Waz Business did not return a contact id.');
    }

    /**
     * معرّف جهة الاتصال من قائمة جهات اتصال الشركة، مطابقةً بالبريد.
     */
    private function findContactIdByEmail(int $companyId, string $email): ?int
    {
        foreach ($this->get('/api/contacts/' . $companyId) as $row) {
            if (is_array($row)
                && isset($row['id'])
                && strcasecmp((string) ($row['email'] ?? ''), $email) === 0
            ) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * تحديث بيانات شركة قائمة.
     *
     * يُستدعى عندما يعدّل العميل اسم المنشأة أو العنوان أو الرقم الضريبي من
     * الإعدادات؛ بدونه تتباعد البيانات بين المنصتين، والعنوان والرقم الضريبي
     * يظهران في الفاتورة الضريبية فالتباعد هنا خلل نظامي لا تجميلي.
     *
     * @param  array<string, mixed>  $changes  الحقول المتغيّرة فقط، ويجب أن
     *   تتضمّن دائماً country_id (المتغيّرة أو الحالية) — انظر التحذير أدناه.
     *
     * @throws WazBusinessException
     */
    public function updateCompany(int $companyId, array $changes): void
    {
        // التعديل يختلف عن الإنشاء في ثلاثة أمور تفرضها المنصة:
        //  1. الجسم RAW JSON لا form-data (الأخير يردّ 406).
        //  2. تُرسَل الحقول المتغيّرة فقط.
        //  3. لكن «الجزئي» ليس جزئياً بالكامل: الحقول الرقمية غير المُرسَلة
        //     تُصفَّر إلى 0 بصمت (country و default_currency وعنوانا الفوترة
        //     والشحن)، بينما النصّية تبقى. لذلك نُرسل الرقمية دائماً.
        // ولا نستخدم POST على /api/customers/:id إطلاقاً: المنصة تتجاهل :id
        // وتُمرّره لمعالج الإنشاء فتُنتج عميلاً مكرّراً وتقول "add successful".
        if (!$changes) {
            return;
        }

        // الرقم الضريبي لا يُحفظ عندنا، وحقله المخصّص يُمحى إن لم يُرسَل — فإن
        // لم يكن ضمن التعديل نقرأ قيمته الحالية من المنصة ونُعيدها كما هي.
        if (!array_key_exists('vat', $changes)) {
            $current = $this->get('/api/customers/' . $companyId);
            $vat = $current[0]['vat'] ?? null;
            if ($vat !== null && $vat !== '') {
                $changes['vat'] = $vat;
            }
        }

        $payload = $this->companyChangesPayload($changes);

        if (!$this->isConfigured()) {
            throw new WazBusinessException('Waz Business API token is not configured.');
        }

        try {
            $response = Http::withHeaders([
                'authtoken' => (string) config('waz.token'),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('waz.timeout', 15))
                ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json')
                ->put($this->url('/api/customers/' . $companyId));
        } catch (Throwable $e) {
            Log::error('Waz: company update request failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            throw WazBusinessException::connection('Could not reach Waz Business: ' . $e->getMessage(), $e);
        }

        $body = $response->json();

        if (!$response->successful() || (is_array($body) && ($body['status'] ?? null) === false)) {
            Log::error('Waz: company update rejected', [
                'company_id' => $companyId,
                'fields' => array_keys($payload),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new WazBusinessException(
                $this->messageFrom(is_array($body) ? $body : null)
                    ?? ('Waz Business rejected the company update (HTTP ' . $response->status() . ').')
            );
        }
    }

    /**
     * تحويل الحقول المتغيّرة إلى أسماء المنصة — بلا أي حقل لم يُمرَّر.
     *
     * العنوان يُرسَل معه عنوانا الفوترة والشحن لأنهما مرآته عندنا؛ ولو لم
     * نُحدّثهما لبقيت الفاتورة تحمل العنوان القديم.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    private function companyChangesPayload(array $changes): array
    {
        $defaults = config('waz.defaults');
        $payload = [];

        // الحقول الرقمية والعلاقات تُرسَل دائماً — غيابها يمحوها لا يُبقيها.
        // أُثبِت على المجموعة: الشركات التي أُنشئت ولم تُعدَّل احتفظت بمجموعة
        // «منجز شات»، وكل شركة عُدِّلت فقدتها لأن groups_in[] لم يُرسَل.
        $payload['default_currency'] = (string) $defaults['currency'];
        $payload['groups_in'] = [$defaults['group_id']];

        // مصدر العميل ثابت ولا يتغيّر، فنُعيد إرساله مع كل تحديث لئلا يُمحى
        // مثل المجموعة. (الحقلان معرّفان على الإنتاج لا على demo.)
        $payload['custom_fields[customers][' . $defaults['source_custom_field'] . ']'] = $defaults['source'];

        if (isset($changes['country_id'])) {
            foreach (['country', 'billing_country', 'shipping_country'] as $field) {
                $payload[$field] = (string) $changes['country_id'];
            }
        }

        $direct = [
            'company' => 'company',
            'phone' => 'phonenumber',
            'website' => 'website',
        ];
        foreach ($direct as $key => $field) {
            if (array_key_exists($key, $changes)) {
                $payload[$field] = (string) $changes[$key];
            }
        }

        // كل جزء من العنوان ينعكس على الحقل الرئيسي والفوترة والشحن.
        $address = [
            'street' => ['address', 'billing_street', 'shipping_street'],
            'city' => ['city', 'billing_city', 'shipping_city'],
            'state' => ['state', 'billing_state', 'shipping_state'],
            'zip' => ['zip', 'billing_zip', 'shipping_zip'],
        ];
        foreach ($address as $key => $fields) {
            if (array_key_exists($key, $changes)) {
                foreach ($fields as $field) {
                    $payload[$field] = (string) $changes[$key];
                }
            }
        }

        if (array_key_exists('vat', $changes)) {
            $payload['vat'] = (string) $changes['vat'];
            $payload['custom_fields[customers][' . $defaults['vat_custom_field'] . ']'] = (string) $changes['vat'];
        }

        if (array_key_exists('language', $changes)) {
            $payload['default_language'] = $this->language($changes['language']);
        }

        return $payload;
    }

    /**
     * حذف جهة اتصال — عند حذف مستخدم أو مغادرته المنشأة.
     */
    public function deleteContact(int $contactId): bool
    {
        return $this->delete('/api/delete/contacts/' . $contactId);
    }

    /**
     * قائمة جهات اتصال شركة.
     *
     * @return array<int, mixed>
     */
    public function listContacts(int $companyId): array
    {
        return $this->get('/api/contacts/' . $companyId);
    }

    /**
     * الخدمات/المنتجات المعرّفة في واز — مصدر أسماء وأسعار الخدمات الرسمية
     * بدل تكرارها نصّاً عند إنشاء الفواتير.
     *
     * @return array<int, mixed>
     */
    public function listItems(): array
    {
        return $this->get('/api/items/');
    }

    /**
     * حذف شركة — تعويض عندما تُنشأ الشركة ثم يفشل ما بعدها، حتى لا تبقى
     * شركة يتيمة في المنصة لعميل لم يكتمل تسجيله.
     */
    public function deleteCompany(int $companyId): bool
    {
        return $this->delete('/api/delete/customers/' . $companyId);
    }

    /**
     * حذف لا يرمي استثناءً: يُستخدم في مسارات التعويض والتنظيف، فلا يصحّ أن
     * يُخفي فشلُ التنظيف الخطأَ الأصلي الذي استدعاه.
     */
    private function delete(string $path): bool
    {
        try {
            $response = $this->request()->delete($this->url($path));
        } catch (Throwable $e) {
            Log::warning('Waz: delete request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return false;
        }

        $body = $response->json();
        $ok = $response->successful()
            && (!is_array($body) || !array_key_exists('status', $body)
                || filter_var($body['status'], FILTER_VALIDATE_BOOLEAN));

        if (!$ok) {
            Log::warning('Waz: delete rejected', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $ok;
    }

    /**
     * إنشاء فاتورة لبند خدمة واحد.
     *
     * التوثيق ينبّه: رسوم التأسيس والاشتراك فاتورتان **منفصلتان** لا بندان في
     * فاتورة واحدة — لذلك هذه الدالة تُنشئ فاتورة ببند واحد، وتُستدعى مرتين.
     *
     * المبالغ: `gross` هو ما يدفعه العميل فعلاً شاملاً الضريبة، فنشتقّ منه
     * السعر قبلها. مثال التوثيق يثبّت هذا الاتجاه: باقة Pro المعروضة بـ322
     * فاتورتها subtotal=280 و total=322 — أي أن السعر المعلن هو الإجمالي لا
     * الوعاء. عكسُها يُصدر فاتورة أعلى ممّا دُفع بـ15%.
     *
     * @param  array{company_id: int, description: string, long_description?: string, gross: float, qty?: int, date?: \DateTimeInterface, billing?: array<string, string>, number?: string}  $data
     * @return array{id: ?int, number: string, response: array<string, mixed>}
     *
     * @throws WazBusinessException
     */
    public function createInvoice(array $data): array
    {
        $cfg = config('waz.invoices');
        // Carbon لأن DateTimeInterface لا يضمن modify/add، وDateTimeImmutable
        // لا يعدّل نفسه — فالنسخة الصريحة تتفادى الحالتين.
        $date = Carbon::instance(
            ($data['date'] ?? null) instanceof \DateTimeInterface ? $data['date'] : now()
        );
        $total = round((float) $data['gross'], 2);
        $subtotal = round($total / (1 + $cfg['tax_rate'] / 100), 2);
        $number = $data['number'] ?? $this->invoiceNumber();

        $payload = [
            'clientid' => (string) $data['company_id'],
            'number' => $number,
            'date' => $date->format('d/m/Y'),
            'duedate' => $date->copy()->addDays((int) $cfg['due_days'])->format('d/m/Y'),
            'currency' => config('waz.defaults.currency'),
            'tags' => $cfg['tags'],
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
            'allowed_payment_modes[]' => $cfg['allowed_payment_mode'],
            'cancel_overdue_reminders' => '1',
            'adminnote' => $cfg['admin_note'],
            'newitems[0][description]' => $data['description'],
            'newitems[0][long_description]' => $data['long_description'] ?? $cfg['long_descriptions']['plan'],
            'newitems[0][qty]' => '1',
            'newitems[0][unit]' => $cfg['unit'],
            // التوثيق يشترط تطابق rate مع subtotal حرفياً — بند واحد بكمية 1.
            'newitems[0][rate]' => number_format($subtotal, 2, '.', ''),
            'newitems[0][taxname][]' => $cfg['tax_name'],
        ];

        // عنوان الفوترة كما سُجّل وقت التسجيل. الدولة رقمها لا اسمها: إرسال
        // الاسم يُخزَّن 0 أي بلا دولة، كما ينصّ الـOverview على معرّفات الدول.
        foreach (($data['billing'] ?? []) as $key => $value) {
            $value = (string) $value;

            if ($key === 'country') {
                $value = (string) (config('waz_countries.' . $value) ?? '');
                if ($value === '') {
                    continue;
                }
            }

            $payload['billing_' . $key] = $value;
        }

        // الفواتير — بخلاف العملاء وجهات الاتصال — ترجع invoice_id مباشرة.
        $response = $this->post('/api/invoices', $payload);

        return [
            'id' => $this->idFromResponse($response),
            'number' => $number,
            'response' => $response,
        ];
    }

    /**
     * فاتورة واحدة بمعرّفها.
     *
     * @return array<int, mixed>
     */
    public function getInvoice(int $invoiceId): array
    {
        return $this->get('/api/invoices/' . $invoiceId);
    }

    /**
     * فواتير شركة. الرد يحمل hash الفاتورة لبناء رابط العرض للعميل.
     *
     * @return array<int, mixed>
     */
    public function listInvoices(int $companyId): array
    {
        return $this->get('/api/invoices/?clientid=' . $companyId);
    }

    /**
     * رابط عرض الفاتورة للعميل: {base}/invoice/{id}/{hash}
     */
    public function invoiceUrl(int $invoiceId, string $hash): string
    {
        return rtrim((string) config('waz.base_url'), '/')
            . config('waz.invoice_view_path') . $invoiceId . '/' . $hash;
    }

    /**
     * تسجيل دفعة على فاتورة. بدونها تبقى الفاتورة «غير مدفوعة» في المحاسبة
     * رغم نجاح الدفع لدينا، فتلزم تسوية يدوية.
     *
     * @param  array{invoice_id: int, amount: float, payment_method: string, transaction_id?: ?string, note?: ?string}  $data
     * @return array<string, mixed>
     *
     * @throws WazBusinessException
     */
    public function addPayment(array $data): array
    {
        $payload = [
            'invoiceid' => (string) $data['invoice_id'],
            'amount' => number_format((float) $data['amount'], 2, '.', ''),
            'paymentmode' => config('waz.invoices.allowed_payment_mode'),
            'paymentmethod' => $data['payment_method'],
            'note' => $data['note'] ?? 'The payment was processed via Mnjz Chat platform.',
        ];

        if (!empty($data['transaction_id'])) {
            $payload['transactionid'] = (string) $data['transaction_id'];

            // معرّف العملية فريد لدى بوابة الدفع، فوجوده على الفاتورة يعني أن
            // هذه الدفعة سُجّلت في محاولة سابقة. الفحص قبل الإرسال يجعل إعادة
            // المحاولة — لأي سبب — لا تُضاعف المبلغ المحصَّل.
            if ($this->paymentExists($payload)) {
                Log::info('Waz: payment already recorded, skipping', [
                    'invoice_id' => $payload['invoiceid'],
                    'transaction_id' => $payload['transactionid'],
                ]);

                return ['id' => null, 'duplicate' => true];
            }
        }

        try {
            return $this->post('/api/payments', $payload);
        } catch (WazBusinessException $e) {
            // المنصة تُسجّل الدفعة ثم تتأخر استجابتها عن المهلة. اعتبارها فشلاً
            // يجعل المزامنة تُعيد المحاولة فتُسجَّل الدفعة مرتين وتظهر الفاتورة
            // مدفوعة بضعف قيمتها — لذلك نتحقق أولاً.
            if (!$e->connectionFailed || !$this->paymentExists($payload)) {
                throw $e;
            }

            return ['id' => null, 'recovered' => true];
        }
    }

    /**
     * هل سُجّلت هذه الدفعة على الفاتورة فعلاً؟
     *
     * نُطابق على معرّف العملية إن وُجد لأنه فريد، وإلا على المبلغ — فالبحث
     * النصّي في المنصة يطابق حقولاً عدّة، والتصفية على invoiceid إلزامية.
     *
     * @param  array<string, string>  $payload
     */
    private function paymentExists(array $payload): bool
    {
        $invoiceId = (string) $payload['invoiceid'];
        $needle = $payload['transactionid'] ?? $invoiceId;

        foreach ($this->listPayments($needle) as $payment) {
            if (!is_array($payment) || (string) ($payment['invoiceid'] ?? '') !== $invoiceId) {
                continue;
            }

            if (isset($payload['transactionid'])) {
                if ((string) ($payment['transactionid'] ?? '') === $payload['transactionid']) {
                    return true;
                }

                continue;
            }

            if ((string) ($payment['amount'] ?? '') === $payload['amount']) {
                return true;
            }
        }

        return false;
    }

    /**
     * بحث في الدفعات. الرد مُغلَّف مثل التذاكر.
     *
     * @return array<int, mixed>
     */
    public function listPayments(string $query): array
    {
        $rows = $this->get('/api/payments/search/' . rawurlencode($query));

        if (isset($rows[0]['data']) && is_array($rows[0]['data'])) {
            return $rows[0]['data'];
        }

        return $rows;
    }

    /**
     * فتح تذكرة دعم باسم جهة اتصال العميل.
     *
     * @param  array{company_id: int, contact_id: int, subject: string, message: string, department?: string, priority?: string, company_name?: ?string}  $data
     * @return array<string, mixed>
     *
     * @throws WazBusinessException
     */
    public function createTicket(array $data): array
    {
        $cfg = config('waz.tickets');

        $payload = [
            'subject' => $data['subject'],
            'message' => $data['message'],
            'department' => $cfg['departments'][$data['department'] ?? ''] ?? $cfg['default_department'],
            'priority' => $cfg['priorities'][$data['priority'] ?? ''] ?? $cfg['default_priority'],
            'contactid' => (string) $data['contact_id'],
            'userid' => (string) $data['company_id'],
            'service' => $cfg['service'],
            'tags' => $cfg['tags'],
        ];

        if (!empty($data['company_name'])) {
            $payload['custom_fields[tickets][' . $cfg['company_custom_field'] . ']'] = $data['company_name'];
        }

        try {
            return $this->post('/api/tickets', $payload);
        } catch (WazBusinessException $e) {
            // كالشركة وجهة الاتصال: المنصة تُنشئ التذكرة ثم تتأخر استجابتها عن
            // المهلة. اعتبارها فشلاً يجعل العميل يُعيد الإرسال فتتكرّر التذكرة
            // على فريق الدعم. نتحقق أولاً بالبحث عن العنوان.
            if (!$e->connectionFailed || !$this->ticketExists($data['company_id'], $data['subject'])) {
                throw $e;
            }

            Log::warning('Waz: ticket creation timed out but the record exists', [
                'company_id' => $data['company_id'],
                'subject' => $data['subject'],
            ]);

            return [];
        }
    }

    /**
     * هل توجد تذكرة بهذا العنوان لهذه الشركة؟
     */
    private function ticketExists(int $companyId, string $subject): bool
    {
        foreach ($this->listTickets($companyId) as $ticket) {
            if (is_array($ticket) && ($ticket['subject'] ?? null) === $subject) {
                return true;
            }
        }

        return false;
    }

    /**
     * تذاكر العميل. الرد يحمل ticketkey لبناء رابط العرض.
     *
     * @return array<int, mixed>
     */
    public function listTickets(int $companyId): array
    {
        $rows = $this->get('/api/tickets?userid=' . $companyId);

        // التذاكر — بخلاف بقية القوائم — تُغلَّف في مظروف
        // {status, count, limit, offset, data:[...]}، فنُخرج القائمة منه.
        if (isset($rows[0]['data']) && is_array($rows[0]['data'])) {
            return $rows[0]['data'];
        }

        return $rows;
    }

    /**
     * رابط عرض التذكرة للعميل: {base}/forms/tickets/{ticketkey}
     */
    public function ticketUrl(string $ticketKey): string
    {
        return rtrim((string) config('waz.base_url'), '/')
            . config('waz.tickets.view_path') . $ticketKey;
    }

    /**
     * حجز موعد اجتماع. اللون يصنّف سبب الاجتماع لفريق الدعم — مفاتيحه في
     * config('waz.meetings.colors').
     *
     * @param  array{company_id: int, title: string, description: string, start: \DateTimeInterface, reason?: string}  $data
     * @return array<string, mixed>
     *
     * @throws WazBusinessException
     */
    public function bookMeeting(array $data): array
    {
        $cfg = config('waz.meetings');

        return $this->post('/api/calendar/', [
            'title' => $data['title'],
            'description' => $data['description'],
            'start' => $data['start']->format($cfg['datetime_format']),
            'color' => $cfg['colors'][$data['reason'] ?? ''] ?? $cfg['default_color'],
            'userid' => (string) $data['company_id'],
            'reminder_before_type' => $cfg['reminder_before_type'],
            'reminder_before' => $cfg['reminder_before'],
            'isstartnotified' => '1',
            'public' => '1',
        ]);
    }

    /**
     * كل المواعيد المحجوزة.
     *
     * @return array<int, mixed>
     */
    public function listMeetings(): array
    {
        return $this->get('/api/calendar/');
    }

    /**
     * إلغاء موعد.
     */
    public function deleteMeeting(int $meetingId): bool
    {
        return $this->delete('/api/calendar/' . $meetingId);
    }

    /**
     * رقم فاتورة عشوائي غير مكرّر — التوثيق يطلب رقماً فريداً بطول ثابت.
     */
    private function invoiceNumber(): string
    {
        return (string) random_int(
            (int) config('waz.invoices.number_min', 100000000),
            (int) config('waz.invoices.number_max', 999999999)
        );
    }

    private function language(?string $locale): string
    {
        return config('waz.languages.' . ($locale ?? ''), config('waz.default_language'));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('waz.base_url'), '/') . $path;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['authtoken' => (string) config('waz.token')])
            ->timeout((int) config('waz.timeout', 15))
            ->asForm();
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     *
     * @throws WazBusinessException
     */
    private function post(string $path, array $payload): array
    {
        if (!$this->isConfigured()) {
            throw new WazBusinessException('Waz Business API token is not configured.');
        }

        try {
            $response = $this->request()->post($this->url($path), $payload);
        } catch (Throwable $e) {
            Log::error('Waz: request failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw WazBusinessException::connection('Could not reach Waz Business: ' . $e->getMessage(), $e);
        }

        $body = $response->json();

        if (!$response->successful()) {
            Log::error('Waz: request rejected', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new WazBusinessException(
                $this->messageFrom($body) ?? ('Waz Business returned HTTP ' . $response->status())
            );
        }

        // المنصة قد ترجع 200 مع status=false عند رفض منطقي (بريد مكرر مثلاً).
        // مقارنة صارمة: status هنا منطقية، ولا نخلطها بحقل حالة نصّي في سجل.
        if (is_array($body) && ($body['status'] ?? null) === false) {
            Log::error('Waz: request returned status=false', ['path' => $path, 'body' => $body]);

            throw new WazBusinessException($this->messageFrom($body) ?? 'Waz Business rejected the request.');
        }

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function messageFrom(?array $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $message = $body['message'] ?? $body['error'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * قراءة GET ترجع قائمة. المنصة تردّ 404 مع "No data were found" عندما لا
     * توجد نتائج، وهي حالة طبيعية لا خطأ — فنُعيد قائمة فارغة.
     *
     * @return array<int, mixed>
     */
    private function get(string $path): array
    {
        try {
            $response = $this->request()->get($this->url($path));
        } catch (Throwable $e) {
            Log::warning('Waz: lookup request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }

        $body = $response->json();

        if (!is_array($body)) {
            return [];
        }

        // القوائم ترجع كمصفوفة متسلسلة. أما {"status":false,"message":"No data
        // were found"} فهو كائن خطأ — لولا هذا الفحص لعُدّ عنصرين في القائمة.
        if (array_is_list($body)) {
            return $body;
        }

        // المقارنة الصارمة مقصودة: كائن الخطأ يحمل status منطقية false، بينما
        // سجلات المنصة تحمل status نصية للحالة ("1" غير مدفوعة، "2" مدفوعة).
        // استخدام filter_var هنا كان يعتبر الفاتورة المدفوعة خطأً.
        if (($body['status'] ?? null) === false) {
            return [];
        }

        // سجل مفرد رجع ككائن — نغلّفه ليتوحّد شكل الإرجاع.
        return [$body];
    }

    /**
     * المعرّف من جسم الاستجابة إن وُجد. المنصة الحالية لا ترجعه عند الإنشاء،
     * لكننا نقرأه أولاً تحسّباً لإصدار يضيفه فنوفّر نداء البحث.
     *
     * @param  array<string, mixed>  $body
     */
    private function idFromResponse(array $body): ?int
    {
        foreach (['id', 'userid', 'customer_id', 'contact_id', 'invoice_id', 'insert_id'] as $key) {
            if (isset($body[$key]) && is_numeric($body[$key])) {
                return (int) $body[$key];
            }
        }

        if (isset($body['data']) && is_array($body['data'])) {
            foreach (['id', 'userid', 'customer_id', 'contact_id'] as $key) {
                if (isset($body['data'][$key]) && is_numeric($body['data'][$key])) {
                    return (int) $body['data'][$key];
                }
            }
        }

        return null;
    }
}
