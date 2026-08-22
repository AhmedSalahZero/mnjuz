<?php

namespace App\Services;

use App\Helpers\MessagingWindowHelper;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\ConversationRating;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * استبيان رضا العميل بعد إغلاق المحادثة.
 *
 * نقطة الإطلاق واحدة — Contact::toggleTicketStatus — فتغطّي الإغلاق من الويب
 * ومن التطبيق وأي إغلاق آلي يُضاف لاحقاً بلا تكرار الشرط في كل مسار.
 *
 * الإرسال لا يُفشل الإغلاق أبداً: فشل الاستبيان مزعج، وفشل إغلاق المحادثة
 * يعطّل عمل الموظّف. لذلك كل شيء هنا داخل try وما يُخفق يُكتب في السجلّ.
 */
class ConversationRatingService
{
    /** مفتاح ميزة الباقة: هل يُسمح بحذف التقييمات أصلاً؟ */
    public const FEATURE_DELETE = 'rating_delete';

    public const DEFAULT_MESSAGE = 'شكراً لتواصلك معنا 🌟 يسعدنا تقييمك لمستوى الخدمة: {rating_link}';

    /** نصّ الزرّ الذي يفتح صفحة التقييم. */
    public const DEFAULT_BUTTON_LABEL = 'تقييم مستوى الخدمة';

    /**
     * حدود واتساب لرسالة cta_url. تجاوزها يجعل Meta ترفض الطلب كلّه، فالقصّ
     * هنا أفضل من استبيان لا يصل.
     * https://developers.facebook.com/docs/whatsapp/cloud-api/messages/interactive-cta-url-button-messages
     */
    public const BUTTON_LABEL_MAX = 20;
    public const BODY_MAX = 1024;

    /** التهدئة الافتراضية بالأيام بين طلبَي تقييم للعميل نفسه. 0 = بلا تهدئة. */
    public const DEFAULT_COOLDOWN_DAYS = 30;

    public const MAX_COOLDOWN_DAYS = 365;

    /**
     * تُستدعى بعد تحويل المحادثة إلى «مغلقة».
     * تُرجع الصفّ المُنشأ أو null إن لم يُرسَل شيء (والسبب مكتوب في السجلّ).
     */
    public static function onConversationClosed(Contact $contact, ?int $agentId = null): ?ConversationRating
    {
        try {
            $organizationId = (int) $contact->organization_id;
            $settings = self::settings($organizationId);

            if (empty($settings['active'])) {
                return null;
            }

            if (!self::shouldAskAgain($contact, (int) $settings['cooldown_days'])) {
                return null;
            }

            // الرسالة الحرّة لا تمرّ خارج نافذة الـ24 ساعة — واتساب يرفضها.
            if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
                Log::info('Conversation rating skipped: messaging window closed', [
                    'contact_id' => $contact->id,
                    'organization_id' => $organizationId,
                ]);

                return null;
            }

            $rating = self::createPending($contact, $agentId);

            $sent = self::send(
                $organizationId,
                $contact,
                self::buildBody($organizationId, $contact, $settings),
                $settings['button_label'],
                self::linkFor($rating)
            );
            if (!$sent) {
                // لا نُبقي رمزاً حيّاً لرابط لم يصل صاحبه أبداً.
                $rating->forceDelete();

                return null;
            }

            $rating->forceFill(['sent_at' => now()])->save();

            return $rating;
        } catch (\Throwable $e) {
            Log::warning('Conversation rating failed', [
                'contact_id' => $contact->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** إعدادات الاستبيان من بيانات المنظمة. */
    public static function settings(int $organizationId): array
    {
        $organization = Organization::find($organizationId);
        $metadata = $organization && $organization->metadata
            ? json_decode($organization->metadata, true)
            : [];

        $ratings = is_array($metadata) ? ($metadata['ratings'] ?? []) : [];

        return [
            'active' => !empty($ratings['active']),
            'message' => trim((string) ($ratings['message'] ?? '')) ?: self::DEFAULT_MESSAGE,
            'button_label' => self::normalizeButtonLabel($ratings['button_label'] ?? null),
            'cooldown_days' => self::normalizeCooldown($ratings['cooldown_days'] ?? null),
        ];
    }

    /** نصّ الزرّ بعد القصّ إلى حدّ واتساب. الفارغ يعود إلى الافتراضي. */
    public static function normalizeButtonLabel($value): string
    {
        $label = trim((string) ($value ?? ''));

        if ($label === '') {
            return self::DEFAULT_BUTTON_LABEL;
        }

        return mb_substr($label, 0, self::BUTTON_LABEL_MAX);
    }

    public static function normalizeCooldown($value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_COOLDOWN_DAYS;
        }

        return max(0, min(self::MAX_COOLDOWN_DAYS, (int) $value));
    }

    /**
     * تقييمٌ لكل محادثة، لا لكل عميل.
     *
     * «المحادثة» هنا ليست التذكرة: إعادة الفتح تُعيد استخدام صفّ التذكرة نفسه
     * (ChatService يضبط status='open' على الصفّ القائم)، فالتذاكر عملياً واحدة
     * لكل عميل — 367,984 تذكرة مقابل 367,735 عميلاً في قاعدة الإنتاج. العلامة
     * الصحيحة لمحادثة جديدة هي حدث إعادة الفتح المسجَّل في chat_ticket_logs.
     *
     * withTrashed مقصود: حذف المالك لتقييمٍ لا يُعيد فتح باب طلبٍ فوريّ جديد،
     * وإلا لأمكن استدرار تقييم بحذف القديم.
     */
    private static function shouldAskAgain(Contact $contact, int $cooldownDays): bool
    {
        $lastAsked = ConversationRating::withTrashed()
            ->where('contact_id', $contact->id)
            ->max('created_at');

        $lastAsked = $lastAsked ? Carbon::parse($lastAsked) : null;

        $lastReopen = null;
        if ($lastAsked) {
            $reopenedAt = ChatTicketLog::where('contact_id', $contact->id)
                ->where('description', 'like', '%closed to open%')
                ->where('created_at', '>', $lastAsked)
                ->max('created_at');

            $lastReopen = $reopenedAt ? Carbon::parse($reopenedAt) : null;
        }

        return self::decideAsk($lastAsked, $lastReopen, $cooldownDays);
    }

    /**
     * القرار وحده بلا استعلامات — ليكون قابلاً للاختبار مباشرةً.
     *
     * ثلاث حالات متتابعة: لم يُسأل قطّ ← اسأل. سُئل داخل مدّة التهدئة ← لا تسأل.
     * سُئل قبلها وعاد بمحادثة جديدة ← اسأل.
     */
    public static function decideAsk(?Carbon $lastAskedAt, ?Carbon $lastReopenAt, int $cooldownDays): bool
    {
        if ($lastAskedAt === null) {
            return true;
        }

        if ($cooldownDays > 0 && $lastAskedAt->gt(now()->subDays($cooldownDays))) {
            return false;
        }

        return $lastReopenAt !== null && $lastReopenAt->gt($lastAskedAt);
    }

    private static function createPending(Contact $contact, ?int $agentId): ConversationRating
    {
        $agentId = $agentId ?? self::resolveAgentId($contact);
        $agent = $agentId ? User::find($agentId) : null;

        return ConversationRating::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => (int) $contact->organization_id,
            'contact_id' => (int) $contact->id,
            'agent_id' => $agentId,
            'contact_name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: null,
            'contact_phone' => $contact->phone,
            'agent_name' => $agent
                ? (trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')) ?: $agent->email)
                : null,
            'token' => self::generateToken(),
            'status' => ConversationRating::STATUS_PENDING,
            'expires_at' => now()->addDays(ConversationRating::LINK_VALID_DAYS),
        ]);
    }

    /** الموظّف المعيَّن على التذكرة الحالية إن لم يُمرَّر صراحةً. */
    private static function resolveAgentId(Contact $contact): ?int
    {
        $assigned = ChatTicket::where('contact_id', $contact->id)
            ->where('is_latest', true)
            ->value('assigned_to');

        return $assigned ? (int) $assigned : null;
    }

    private static function generateToken(): string
    {
        do {
            $token = Str::random(48);
        } while (ConversationRating::where('token', $token)->exists());

        return $token;
    }

    public static function linkFor(ConversationRating $rating): string
    {
        return rtrim(config('app.url'), '/') . '/rate/' . $rating->token;
    }

    /**
     * متن الرسالة بلا الرابط — الرابط صار زرّاً.
     *
     * إبقاؤه نصّاً بجانب الزرّ يُكرّره ويُفقد الزرّ معناه، ويترك العميل أمام
     * رابط طويل هو ما أردنا إخفاءه. ونحذف معه علامة الترقيم المعلّقة التي كانت
     * تسبقه («… لمستوى الخدمة:») كي لا ينتهي المتن بنقطتين بلا ما بعدهما.
     */
    public static function buildBody(int $organizationId, Contact $contact, array $settings): string
    {
        $message = ContactPlaceholderService::replace($organizationId, $contact->uuid, $settings['message']);
        $message = str_replace('{rating_link}', '', $message);

        // روابط مكتوبة يدوياً في النصّ تبقى — قد تكون مقصودة لغرض آخر.
        $message = preg_replace('/[ \t]+/u', ' ', (string) $message);
        $message = trim((string) preg_replace('/\s*[:：\-–—]\s*$/u', '', trim((string) $message)));

        // متنٌ فارغ يجعل Meta ترفض الرسالة، فنعود إلى النصّ الافتراضي.
        if ($message === '') {
            $message = trim(str_replace('{rating_link}', '', self::DEFAULT_MESSAGE));
            $message = trim((string) preg_replace('/\s*[:：]\s*$/u', '', $message));
        }

        return mb_substr($message, 0, self::BODY_MAX);
    }

    /**
     * إرسال الاستبيان زرّاً يفتح صفحة التقييم.
     *
     * كان نصّاً يحمل رابطاً طويلاً؛ الزرّ يخفي الرابط ويجعل الضغط خطوة واحدة.
     * وإن رفضت Meta الرسالة التفاعلية لأي سبب نرتدّ إلى النصّ: استبيانٌ أقلّ
     * أناقة خيرٌ من استبيان لا يصل، والارتداد يقع فقط بعد فشل مُعلَن.
     */
    private static function send(int $organizationId, Contact $contact, string $body, string $buttonLabel, string $link): bool
    {
        $config = Organization::find($organizationId)?->metadata;
        $config = $config ? json_decode($config, true) : [];
        $whatsapp = $config['whatsapp'] ?? [];

        if (empty($whatsapp['access_token']) || empty($whatsapp['phone_number_id'])) {
            Log::info('Conversation rating skipped: WhatsApp is not configured', ['organization_id' => $organizationId]);

            return false;
        }

        $service = new WhatsappService(
            $whatsapp['access_token'],
            config('graph.api_version'),
            $whatsapp['app_id'] ?? null,
            $whatsapp['phone_number_id'],
            $whatsapp['waba_id'] ?? null,
            $organizationId
        );

        $sentAsButton = self::succeeded($service->sendMessage(
            $contact->uuid,
            $body,
            null,
            self::INTERACTIVE_CTA_URL,
            ['display_text' => $buttonLabel, 'url' => $link]
        ));

        if ($sentAsButton) {
            return true;
        }

        Log::info('Conversation rating: CTA button rejected, falling back to text', [
            'organization_id' => $organizationId,
            'contact_id' => $contact->id,
        ]);

        return self::succeeded($service->sendMessage(
            $contact->uuid,
            rtrim($body) . "\n" . $link,
            null,
            'text'
        ));
    }

    /** نوع الرسالة التفاعلية كما يسمّيه WhatsappService. */
    private const INTERACTIVE_CTA_URL = 'interactive call to action url';

    private static function succeeded($response): bool
    {
        $payload = is_object($response) && method_exists($response, 'getData')
            ? (array) $response->getData(true)
            : (array) $response;

        return ($payload['success'] ?? false) === true;
    }

    /**
     * تسجيل إجابة العميل. تُرجع false إن كان الرابط مستهلكاً أو منتهياً،
     * فالاستهلاك مرّة واحدة هو ما يجعل الأرقام ذات معنى.
     */
    public static function submit(ConversationRating $rating, int $stars, ?string $comment, ?string $ip = null): bool
    {
        if (!$rating->isOpenForSubmission()) {
            return false;
        }

        $rating->forceFill([
            'rating' => max(1, min(5, $stars)),
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'status' => ConversationRating::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'ip' => $ip,
        ])->save();

        ActivityLogger::log(
            ActivityLogger::RATING_RECEIVED,
            $rating->contact_name ?: $rating->contact_phone,
            'contact',
            $rating->contact_id,
            ['rating' => $rating->rating],
            (int) $rating->organization_id,
            null,
            __('Customer')
        );

        return true;
    }

    /** هل تسمح الباقة بحذف التقييمات؟ */
    public static function deletionAllowedByPlan(int $organizationId): bool
    {
        return SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, self::FEATURE_DELETE);
    }

    /**
     * الحذف مشروط بأمرين مستقلّين: أن تسمح الباقة، وأن يكون الطالب المالك.
     * المدير يرى ولا يحذف — قد يكون هو الطرف الذي وقع عليه التقييم السيّئ.
     */
    public static function canDelete(int $organizationId, ?string $role): bool
    {
        return self::deletionAllowedByPlan($organizationId) && OrganizationRole::isOwnerOnly($role);
    }
}
