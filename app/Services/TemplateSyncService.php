<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Template;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * مزامنة نسختنا من القوالب مع Meta.
 *
 * ثلاثة أعطاب قائمة يعالجها هذا الملفّ:
 *
 *   ١. **تعديل صورة القالب لا يظهر.** WhatsappService::updateTemplate كان
 *      يحفظ الحالة ولا يحفظ metadata — والسطر معلَّق منذ أول commit في
 *      السكربت الأصلي. فتُحدَّث الصورة عند Meta وتبقى القديمة عندنا، وكل
 *      شاشة تقرأ نسختنا فتعرض القديمة إلى الأبد.
 *
 *   ٢. **التعديل من لوحة Meta لا يصلنا.** ProcessTemplateStatusJob كان
 *      يحفظ الحالة وحدها كذلك.
 *
 *   ٣. **الرابط الموقَّع ينتهي، والصورة باقية.** وهذا فرق جوهري يسهل
 *      الخلط فيه:
 *
 *        • **الصورة** عند Meta لا تنتهي أبداً — تبقى ما بقي القالب.
 *        • **الرابط** الذي تُعطينا إيّاه إذنُ وصولٍ موقَّع، يحمل وقت
 *          انتهاء (`oe=`) نحو ثلاثين يوماً. بعده يردّ CDN واتساب 403
 *          **ولو كانت الصورة سليمة في مكانها**.
 *
 *      أثبتناه على الإنتاج (قالب 639): رابطنا المخزَّن ميّت ويردّ 403،
 *      وسألنا Meta فأعطت رابطاً جديداً يردّ 200 — و**معرّف الملف في
 *      الرابطين واحد** (534427910_957055977019128_...). أي نفس الصورة
 *      بتوقيع جديد، لا صورة أخرى.
 *
 *      وقِسنا العيّنة: عشرة روابط `oe` ماضية ⇒ 403 كلّها، وعشرة `oe`
 *      مستقبلية ⇒ 200 كلّها. وعلى الإنتاج ١٣٥ من ٢٤٤ رابطاً ميّت.
 *
 *      فما تفعله المزامنة ليس «إحضار صورة جديدة» بل **تجديد إذن الوصول
 *      إلى صورة لم تتغيّر**.
 *
 * ولماذا لا نحفظ ما أرسلناه بدل سؤال Meta؟ لأن ما نرسله ليس القالب: ينقصه
 * الاسم واللغة والمعرّف، وقد تُعدّل Meta عليه. حفظه كما هو يُتلف الصفّ —
 * وهذا على الأرجح سبب تعليق السطر أصلاً بدل إصلاحه.
 *
 * وهذه الخدمة لا تقرأ session ولا auth: تعمل من الويب ومن الطابور ومن
 * سطر الأوامر سواءً — بخلاف WhatsappService::syncTemplates التي تعتمد
 * على الجلسة فلا تصلح لمهمّة مجدولة.
 */
class TemplateSyncService
{
    /** الحقول التي نطلبها من Meta — components يحمل الصورة. */
    private const FIELDS = 'id,name,status,category,language,components';

    /**
     * تحديث قالب واحد من Meta.
     *
     * لا ترمي أبداً: تُستدعى بعد تعديل نجح فعلاً عند Meta، وإفشال الطلب
     * بسبب مزامنة متعثّرة يُوهم المستخدم أن التعديل لم يُحفظ — وقد حُفظ.
     */
    public function refreshOne(Template $template): bool
    {
        if (empty($template->meta_id)) {
            return false;
        }

        $credentials = $this->credentialsFor((int) $template->organization_id);

        if (!$credentials) {
            return false;
        }

        $data = $this->get(
            "https://graph.facebook.com/{$credentials['api_version']}/{$template->meta_id}",
            ['fields' => self::FIELDS],
            $credentials['access_token']
        );

        if (!is_array($data) || empty($data['id'])) {
            return false;
        }

        // التوثيق أعلاه يَعِد بأنها لا ترمي، والوعد يحتاج حارساً: عمودٌ
        // أضيق ممّا تُرجعه Meta كان يُخرج استثناءً يُفشل تعديلاً نجح فعلاً.
        try {
            $this->store($template, $data);
        } catch (\Throwable $e) {
            Log::warning('Template refresh could not be stored', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * تحديث كل قوالب منشأة من Meta.
     *
     * نستعمل نقطة القائمة لا نقطة القالب الواحد: نداء واحد لكل صفحة بدل
     * نداء لكل قالب — منشأة فيها مئة قالب تصير نداءً أو اثنين لا مئة.
     *
     * @return array{updated: int, created: int, failed: int, pages: int, error: ?string}
     */
    public function refreshOrganization(int $organizationId): array
    {
        $result = ['updated' => 0, 'created' => 0, 'failed' => 0, 'pages' => 0, 'error' => null];

        $credentials = $this->credentialsFor($organizationId);

        if (!$credentials) {
            $result['error'] = 'WhatsApp is not connected';

            return $result;
        }

        $url = "https://graph.facebook.com/{$credentials['api_version']}/{$credentials['waba_id']}/message_templates";
        $query = ['fields' => self::FIELDS, 'limit' => 100];

        // حدّ الصفحات حارسٌ ضدّ حلقة لا تنتهي: رابط paging معطوب من Meta
        // كان سيُبقي المهمّة تدور إلى الأبد.
        $maxPages = 20;

        while ($url && $result['pages'] < $maxPages) {
            $page = $this->get($url, $query, $credentials['access_token']);
            $query = [];   // رابط الصفحة التالية يحمل معاملاته بنفسه

            if (!is_array($page)) {
                $result['error'] = 'Could not read templates from Meta';
                break;
            }

            $result['pages']++;

            foreach ($page['data'] ?? [] as $item) {
                if (empty($item['id'])) {
                    continue;
                }

                // قالب واحد فاسد لا يُسقط المنشأة كلّها.
                //
                // وقع فعلاً: قالب اسمه رابط خرائط جوجل (نحو ٤٠٠ حرف) تجاوز
                // عمود name، فرمى 1406 وخرج الاستثناء من الحلقة ومن الأمر
                // ومن الترحيل — فتوقّفت مزامنة **كل** المنشآت الباقية بسبب
                // صفّ واحد. الحصر هنا يجعل الخسارة قالباً لا نظاماً.
                try {
                    // withTrashed: مطابقة بما عند Meta لا استعمال للقالب.
                    // وإخفاء المحذوف يُنشئ صفّاً ثانياً لنفس meta_id.
                    $template = Template::withTrashed()
                        ->where('organization_id', $organizationId)
                        ->where('meta_id', $item['id'])
                        ->first();

                    if ($template) {
                        $this->store($template, $item);
                        $result['updated']++;

                        continue;
                    }

                    $this->create($organizationId, $item);
                    $result['created']++;
                } catch (\Throwable $e) {
                    $result['failed']++;

                    Log::warning('Template sync skipped one template', [
                        'organization_id' => $organizationId,
                        'meta_id' => $item['id'],
                        'name' => mb_substr((string) ($item['name'] ?? ''), 0, 80),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $url = $page['paging']['next'] ?? null;
        }

        return $result;
    }

    /* ───────────────────────── داخلي ───────────────────────── */

    /**
     * حفظ ما أعادته Meta.
     *
     * metadata يُستبدل كاملاً لا يُدمج: هو نسخة ممّا عند Meta، والدمج
     * يُبقي مكوّناً حذفه المستخدم — كترويسة أزالها فتعود في المعاينة.
     */
    private function store(Template $template, array $data): void
    {
        $template->metadata = \App\Support\JsonText::encode($data);

        if (!empty($data['status'])) {
            $template->status = $data['status'];
        }

        if (!empty($data['category'])) {
            $template->category = $data['category'];
        }

        // القالب الموجود عند Meta ليس محذوفاً عندنا.
        $template->deleted_at = null;
        $template->updated_at = now();
        $template->save();
    }

    private function create(int $organizationId, array $data): void
    {
        $template = new Template();
        $template->organization_id = $organizationId;
        $template->meta_id = $data['id'];
        $template->name = $data['name'] ?? '';
        $template->category = $data['category'] ?? '';
        $template->language = $data['language'] ?? '';
        $template->metadata = \App\Support\JsonText::encode($data);
        $template->status = $data['status'] ?? 'PENDING';
        // لا auth() هنا: المهمّة المجدولة بلا مستخدم، والعمود لا يقبل NULL.
        $template->created_by = (int) (Organization::where('id', $organizationId)->value('created_by') ?? 0);
        $template->created_at = now();
        $template->updated_at = now();
        $template->save();
    }

    /** بيانات اعتماد واتساب للمنشأة، أو null إن لم تكن مربوطة. */
    private function credentialsFor(int $organizationId): ?array
    {
        $organization = Organization::find($organizationId);

        if (!$organization) {
            return null;
        }

        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsapp = $metadata['whatsapp'] ?? null;

        if (!is_array($whatsapp) || empty($whatsapp['access_token']) || empty($whatsapp['waba_id'])) {
            return null;
        }

        return [
            'access_token' => $whatsapp['access_token'],
            'waba_id' => $whatsapp['waba_id'],
            'api_version' => config('graph.api_version'),
        ];
    }

    /**
     * نداء GET واحد إلى Graph.
     *
     * يبتلع كل خطأ ويُرجع null: المزامنة عملية صيانة، ولا يجوز أن تُسقط
     * تعديلاً نجح ولا مهمّة مجدولة تُعالج مئة منشأة.
     */
    private function get(string $url, array $query, string $accessToken): ?array
    {
        try {
            // Http لا Guzzle خاماً: بقيّة الملفّ يستعمل Guzzle مباشرةً،
            // وذاك يجعل كل نداء غير قابل للاختبار بلا شبكة حقيقية.
            $response = Http::withToken($accessToken)
                ->connectTimeout(10)
                ->timeout(30)
                ->get($url, $query);

            if (!$response->successful()) {
                Log::warning('Template sync request rejected', [
                    'url' => strtok($url, '?'),
                    'status' => $response->status(),
                ]);

                return null;
            }

            $decoded = $response->json();

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('Template sync request failed', [
                'url' => strtok($url, '?'),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
