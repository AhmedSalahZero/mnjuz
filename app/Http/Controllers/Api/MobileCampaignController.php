<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\MobileStoreCampaign;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Services\ActivityLogger;
use App\Services\CampaignAudienceService;
use App\Services\CampaignMediaHistoryService;
use App\Services\CampaignResendService;
use App\Services\CampaignService;
use App\Services\Carousel\CarouselDefinition;
use App\Services\SubscriptionService;
use App\Support\WhatsappErrorExplainer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * الحملات من تطبيق الجوال: القائمة، والتفاصيل، والإنشاء، والحذف، وإعادة
 * إرسال الفاشل.
 *
 * لا منطق حملات هنا: الإنشاء يمرّ بـ CampaignService::store نفسها التي
 * يستعملها الويب، والتحقّق بقواعد StoreCampaign نفسها، وإعادة الإرسال
 * بـ CampaignResendService. المتحكّم يقرأ المنشأة من التوكن ويشكّل الردّ.
 *
 * الصلاحية كالويب: كل أعضاء المنشأة يديرون الحملات (الموظّف مسموح له
 * بقسم الحملات في RestrictOrganizationAgent).
 */
class MobileCampaignController extends Controller
{
    use MobileApi;

    /** القيم الديناميكية التي تُملأ من بيانات جهة الاتصال (TemplateTrait::getParameters). */
    private const DYNAMIC_VALUES = ['first name', 'last name', 'name', 'phone', 'email'];

    private const MEDIA_FORMATS = ['IMAGE', 'VIDEO', 'DOCUMENT'];

    /** حالات السجلّ كما يعرضها التطبيق (حالة المحادثة حين نجح الطلب). */
    private const LOG_STATUSES = ['pending', 'sent', 'delivered', 'read', 'failed'];

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $query = Campaign::with(['template' => fn ($q) => $q->withTrashed(), 'contactGroup'])
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at');

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhereHas('template', fn ($t) => $t->where('name', 'like', $term));
            });
        }

        if ($request->filled('status') && in_array($request->input('status'), ['scheduled', 'ongoing', 'completed', 'failed'], true)) {
            $query->where('status', $request->input('status'));
        }

        $rows = $query->orderByDesc('id')->paginate($this->perPage($request, 10, 50));
        $timezone = $this->timezone($organizationId);

        $rows->getCollection()->transform(fn (Campaign $campaign) => $this->present($campaign, $timezone));

        return $this->paginated($rows);
    }

    /**
     * ما يحتاجه نموذج الإنشاء دفعة واحدة: القوالب المعتمدة، والمجموعات
     * بعدد من سيصلهم فعلاً، وحدّ الباقة، والمنطقة الزمنية التي يُقرأ بها
     * وقت الجدولة.
     */
    public function options(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $templates = Template::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get(['uuid', 'name', 'language', 'category', 'metadata'])
            ->map(fn (Template $template) => [
                'uuid' => $template->uuid,
                'name' => $template->name,
                'language' => $template->language,
                'category' => $template->category,
                'header_format' => $template->component('HEADER')['format'] ?? null,
            ])
            ->values();

        $groups = ContactGroup::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name'])
            ->map(fn (ContactGroup $group) => [
                'uuid' => $group->uuid,
                'name' => $group->name,
                // من سيصلهم فعلاً: بلا المحذوفين ومن أوقفوا التسويق.
                'contacts_count' => CampaignAudienceService::count($organizationId, $group->id),
            ])
            ->values();

        $limit = SubscriptionService::featureLimit($organizationId, 'campaign_limit');
        // العدّ كما في isSubscriptionFeatureLimitReached: المحذوفة تُحسب.
        $used = Campaign::where('organization_id', $organizationId)->count();
        $timezone = $this->timezone($organizationId);

        return $this->ok([
            'templates' => $templates,
            'contact_groups' => $groups,
            'all_contacts_count' => CampaignAudienceService::count($organizationId, 0),
            'dynamic_values' => self::DYNAMIC_VALUES,
            'timezone' => $timezone,
            'now' => Carbon::now($timezone)->format('Y-m-d H:i'),
            'limits' => [
                'campaign_limit' => $limit,
                'used' => $used,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
            ],
            'subscription_active' => SubscriptionService::isSubscriptionActive((string) $organizationId),
        ]);
    }

    /**
     * قالب واحد ومعه هيكل المعاملات جاهزاً للتعبئة.
     *
     * نفس ما يبنيه نموذج الويب (CampaignForm::loadTemplate): التطبيق يملأ
     * القيم ويرسل الهيكل كما هو في header/body/footer/buttons.
     */
    public function template(Request $request, string $uuid): JsonResponse
    {
        $template = Template::where('uuid', $uuid)
            ->where('organization_id', $this->organizationId($request))
            ->whereNull('deleted_at')
            ->first();

        if (!$template) {
            return $this->fail(404, __('Data not found'));
        }

        // التطبيق لا يعرف بطاقات الـ carousel بعد: رسالة واضحة بدل نموذج
        // ناقص تُرسَل منه حملة ترفضها Meta.
        if (CarouselDefinition::isCarousel($template->getRawOriginal('metadata'))) {
            return $this->fail(422, __('Carousel templates can only be sent from the web dashboard for now.'));
        }

        $header = $template->component('HEADER');
        $body = $template->component('BODY');
        $footer = $template->component('FOOTER');
        $buttons = $template->component('BUTTONS')['buttons'] ?? [];

        $format = $header['format'] ?? null;

        return $this->ok([
            'template' => [
                'uuid' => $template->uuid,
                'name' => $template->name,
                'language' => $template->language,
                'category' => $template->category,
                'status' => $template->status,
            ],
            'parameters' => [
                'header' => [
                    'format' => $format,
                    'text' => $header['text'] ?? null,
                    'parameters' => $this->headerParameters($format, $header['example'] ?? null),
                ],
                'body' => [
                    'text' => $body['text'] ?? null,
                    'parameters' => array_map(
                        fn ($value) => ['type' => 'text', 'selection' => 'static', 'value' => $value],
                        $body['example']['body_text'][0] ?? []
                    ),
                ],
                'footer' => ['text' => $footer['text'] ?? null],
                'buttons' => array_map(fn (array $button) => [
                    'type' => $button['type'] ?? null,
                    'text' => $button['text'] ?? null,
                    'value' => $button[strtolower((string) ($button['type'] ?? ''))] ?? null,
                    'parameters' => ($button['type'] ?? null) === 'QUICK_REPLY'
                        ? [['type' => 'static', 'value' => null]]
                        : array_map(fn ($value) => ['type' => 'static', 'value' => $value], (array) ($button['example'] ?? [])),
                ], $buttons),
            ],
            'dynamic_values' => self::DYNAMIC_VALUES,
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $campaign = $this->find($request, $uuid);

        if (!$campaign) {
            return $this->fail(404, __('Data not found'));
        }

        $resendService = app(CampaignResendService::class);
        $data = $this->present($campaign, $this->timezone($organizationId));
        $data['metadata'] = $this->presentMetadata($campaign);
        $data['resendable_count'] = $resendService->canResend($campaign)
            ? $resendService->resendableLogIds($campaign->id)->count()
            : 0;

        return $this->ok($data);
    }

    /** مستلمو الحملة وحالة كل رسالة وسبب فشلها. */
    public function logs(Request $request, string $uuid): JsonResponse
    {
        $campaign = $this->find($request, $uuid);

        if (!$campaign) {
            return $this->fail(404, __('Data not found'));
        }

        $query = CampaignLog::with(['contact' => fn ($q) => $q->withTrashed(), 'chat.logs'])
            ->where('campaign_id', $campaign->id);

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->whereHas('contact', function ($q) use ($term) {
                $q->withTrashed()->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            });
        }

        $status = $request->input('status');
        if (in_array($status, self::LOG_STATUSES, true)) {
            $this->filterLogStatus($query, $status);
        }

        $rows = $query->orderBy('id')->paginate($this->perPage($request, 25));
        $timezone = $this->timezone($this->organizationId($request));

        $rows->getCollection()->transform(fn (CampaignLog $log) => $this->presentLog($log, $timezone));

        return $this->paginated($rows);
    }

    public function store(MobileStoreCampaign $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if (!SubscriptionService::isSubscriptionActive((string) $organizationId)) {
            return $this->fail(403, __('Your subscription is not active. Renew it to continue.'));
        }

        $selectedTemplate = Template::where('uuid', (string) $request->input('template'))
            ->where('organization_id', $organizationId)
            ->first(['id', 'metadata']);

        if ($selectedTemplate && CarouselDefinition::isCarousel($selectedTemplate->getRawOriginal('metadata'))) {
            return $this->fail(422, __('Carousel templates can only be sent from the web dashboard for now.'));
        }

        try {
            $campaign = app(CampaignService::class)->store($request, $organizationId);
        } catch (ValidationException $e) {
            return $this->fail(400, __('The provided data is invalid.'), $e->errors());
        }

        ActivityLogger::log(
            ActivityLogger::CAMPAIGN_CREATED,
            (string) $campaign->name,
            'campaign',
            $campaign->id,
            ['source' => 'mobile'],
            $organizationId
        );

        $campaign->load(['template' => fn ($q) => $q->withTrashed(), 'contactGroup']);

        return $this->ok($this->present($campaign, $this->timezone($organizationId)), __('Campaign created successfully!'));
    }

    public function resendFailed(Request $request, string $uuid): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $campaign = $this->find($request, $uuid);

        if (!$campaign) {
            return $this->fail(404, __('Data not found'));
        }

        if (!SubscriptionService::isSubscriptionActive((string) $organizationId)) {
            return $this->fail(403, __('Your subscription is not active. Renew it to continue.'));
        }

        $resendService = app(CampaignResendService::class);

        if (!$resendService->canResend($campaign)) {
            return $this->fail(422, __('This campaign cannot be resent.'));
        }

        $count = $resendService->resend($campaign);

        if ($count === 0) {
            return $this->fail(422, __('No failed campaign messages to resend.'));
        }

        return $this->ok(
            ['queued' => $count, 'status' => $campaign->fresh()->status],
            __(':count failed campaign message(s) queued for resending.', ['count' => $count])
        );
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $campaign = $this->find($request, $uuid);

        if (!$campaign) {
            return $this->fail(404, __('Data not found'));
        }

        // حذف ناعم كالويب: الطوابير تتجاهل الحملة المحذوفة فيقف إرسالها.
        $campaign->forceFill([
            'deleted_by' => $request->user()->id,
            'deleted_at' => now(),
        ])->save();

        ActivityLogger::log(
            ActivityLogger::CAMPAIGN_DELETED,
            $campaign->name,
            'campaign',
            $campaign->id,
            ['source' => 'mobile'],
            $this->organizationId($request)
        );

        return $this->ok(null, __('Data deleted successfully!'));
    }

    public function mediaHistory(Request $request): JsonResponse
    {
        $type = strtoupper((string) $request->input('type'));

        if (!in_array($type, self::MEDIA_FORMATS, true)) {
            return $this->fail(400, __('The provided data is invalid.'), [
                'type' => [__('The type must be one of: :types.', ['types' => implode(', ', self::MEDIA_FORMATS)])],
            ]);
        }

        return $this->ok(app(CampaignMediaHistoryService::class)->listForOrganization($this->organizationId($request), $type));
    }

    public function destroyMediaHistory(Request $request, string $uuid): JsonResponse
    {
        $deleted = app(CampaignMediaHistoryService::class)->deleteForOrganization($this->organizationId($request), $uuid);

        return $deleted ? $this->ok(null, __('Data deleted successfully!')) : $this->fail(404, __('Data not found'));
    }

    // ------------------------------------------------------------------

    private function find(Request $request, string $uuid): ?Campaign
    {
        return Campaign::with(['template' => fn ($q) => $q->withTrashed(), 'contactGroup'])
            ->where('uuid', $uuid)
            ->where('organization_id', $this->organizationId($request))
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(Campaign $campaign, string $timezone): array
    {
        $counts = $campaign->getCounts();
        $metadata = json_decode((string) $campaign->getRawOriginal('metadata'), true) ?: [];
        $groupId = (int) $campaign->getRawOriginal('contact_group_id');

        return [
            'uuid' => $campaign->uuid,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'failure_reason' => $metadata['failure_reason'] ?? null,
            'template' => $campaign->template ? [
                'uuid' => $campaign->template->uuid,
                'name' => $campaign->template->name,
                'language' => $campaign->template->language,
            ] : null,
            'audience' => $groupId === 0
                ? ['type' => 'all', 'group' => null]
                : ['type' => 'group', 'group' => $campaign->contactGroup ? [
                    'uuid' => $campaign->contactGroup->uuid,
                    'name' => $campaign->contactGroup->name,
                ] : null],
            'stats' => [
                'total' => (int) ($counts->total_message_count ?? 0),
                'sent' => (int) ($counts->total_sent_count ?? 0),
                'delivered' => (int) ($counts->total_delivered_count ?? 0),
                'read' => (int) ($counts->total_read_count ?? 0),
                'failed' => (int) ($counts->total_failed_count ?? 0),
            ],
            'scheduled_at' => $this->local($campaign->getRawOriginal('scheduled_at'), $timezone),
            'created_at' => $this->local($campaign->getRawOriginal('created_at'), $timezone),
            'can_resend' => in_array($campaign->status, CampaignResendService::RESENDABLE_CAMPAIGN_STATUSES, true)
                && (int) ($counts->total_failed_count ?? 0) > 0,
        ];
    }

    /** ما حُفظ من معاملات القالب — يعرضه التطبيق في صفحة التفاصيل. */
    private function presentMetadata(Campaign $campaign): array
    {
        $metadata = json_decode((string) $campaign->getRawOriginal('metadata'), true) ?: [];

        return [
            'header' => $metadata['header'] ?? null,
            'body' => $metadata['body'] ?? null,
            'footer' => $metadata['footer'] ?? null,
            'buttons' => $metadata['buttons'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentLog(CampaignLog $log, string $timezone): array
    {
        $chat = $log->chat;
        $succeeded = $log->status === 'success';
        $status = $succeeded
            ? ($chat?->status === 'accepted' ? 'sent' : ($chat?->status ?? 'sent'))
            : ($log->status === 'ongoing' ? 'pending' : $log->status);

        $error = null;
        if ($status === 'failed') {
            // السجلّ الفاشل نوعان: رُفض الطلب قبل الإرسال (السبب في سجلّ الحملة)،
            // أو قبلته Meta ثم فشل التسليم فعُلِّم فاشلاً بعدها (سجلّ الحملة فيه
            // «accepted» وحدها، والسبب في سجلّ حالة المحادثة).
            $error = $succeeded
                ? $this->chatFailure($chat)
                : (WhatsappErrorExplainer::explain($log->getRawOriginal('metadata')) ?? $this->chatFailure($chat));
        }

        $contact = $log->contact;

        return [
            'id' => $log->id,
            'contact' => $contact ? [
                'uuid' => $contact->uuid,
                'name' => trim(($contact->getRawOriginal('first_name') ?? '') . ' ' . ($contact->getRawOriginal('last_name') ?? '')) ?: null,
                'phone' => $contact->getRawOriginal('phone'),
            ] : null,
            'status' => $status,
            'retry_count' => (int) $log->retry_count,
            'error' => $error,
            'sent_at' => $this->local(
                $succeeded && $chat ? $chat->getRawOriginal('created_at') : $log->getRawOriginal('updated_at'),
                $timezone
            ),
        ];
    }

    /** سبب فشل التسليم: آخر بلاغ «failed» في سجلّ حالة المحادثة. */
    private function chatFailure($chat): ?array
    {
        if (!$chat) {
            return null;
        }

        foreach ($chat->logs->sortByDesc('id') as $statusLog) {
            $explained = WhatsappErrorExplainer::explain($statusLog->getRawOriginal('metadata'));
            if ($explained !== null) {
                return $explained;
            }
        }

        return null;
    }

    private function filterLogStatus($query, string $status): void
    {
        match ($status) {
            'pending' => $query->whereIn('status', ['pending', 'ongoing']),
            'failed' => $query->where(function ($q) {
                $q->where('status', 'failed')
                    ->orWhere(fn ($ok) => $ok->where('status', 'success')->whereHas('chat', fn ($c) => $c->where('status', 'failed')));
            }),
            'sent' => $query->where('status', 'success')->whereHas('chat', fn ($c) => $c->whereIn('status', ['accepted', 'sent'])),
            default => $query->where('status', 'success')->whereHas('chat', fn ($c) => $c->where('status', $status)),
        };
    }

    /**
     * كـ buildHeaderParameters في الويب: ترويسة الوسائط لها معامل واحد
     * دائماً — وُجد مثالها أو لم يوجد.
     */
    private function headerParameters(?string $format, ?array $example): array
    {
        if ($format === 'TEXT') {
            return array_map(
                fn ($value) => ['type' => 'text', 'selection' => 'static', 'value' => $value],
                (array) ($example['header_text'] ?? [])
            );
        }

        if (!in_array($format, self::MEDIA_FORMATS, true)) {
            return [];
        }

        return [[
            'type' => $format,
            'selection' => 'upload',
            'value' => null,
            // مثال القالب في Meta — رابط مؤقّت للمعاينة فقط.
            'example_url' => $example['header_handle'][0] ?? null,
        ]];
    }

    private function timezone(int $organizationId): string
    {
        $metadata = json_decode((string) Organization::whereKey($organizationId)->value('metadata'), true);
        $timezone = is_array($metadata) ? ($metadata['timezone'] ?? null) : null;
        $timezone = $timezone ?: Setting::where('key', 'timezone')->value('value') ?: 'UTC';

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    /** تاريخ القاعدة (UTC) بتوقيت المنشأة بصيغة ISO 8601. */
    private function local(?string $utc, string $timezone): ?string
    {
        if (!$utc) {
            return null;
        }

        return Carbon::parse($utc, 'UTC')->setTimezone($timezone)->toIso8601String();
    }
}
