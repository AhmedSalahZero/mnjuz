<?php

namespace App\Services\WebChat;

use App\Models\ChannelAccount;
use App\Models\ContactField;
use App\Models\Organization;
use App\Models\Setting;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * نموذج إعدادات المحادثة المباشرة: القواعد، والحفظ، والشعار، وما تعرضه
 * الصفحة.
 *
 * منقول من WebChatSettingsController كما هو ليمرّ الويب والتطبيق من طريق
 * واحد. الصلاحية والإضافة على من يستدعي؛ هنا لا جلسة ولا auth().
 */
class WebChatSettingsForm
{
    public const LOGO_DIRECTORY = 'webchat-logos';

    public function __construct(private readonly WebChatWidgetService $widgets)
    {
    }

    /** الـ widget، يُنشأ عند أول زيارة: لا شيء يُلصق قبلها، فلا ضرر. */
    public function widget(int $organizationId, ?int $userId): ChannelAccount
    {
        return $this->widgets->forOrganization($organizationId)
            ?? $this->widgets->ensureForOrganization(
                $organizationId,
                (string) (Organization::where('id', $organizationId)->value('name') ?? ''),
                (int) $userId
            );
    }

    /**
     * قواعد النموذج كاملاً، ومعها فحص الأسئلة الذي لا تقوله القواعد وحدها.
     *
     * @param  array<string, mixed>  $input
     */
    public function validator(array $input, ChannelAccount $account): ValidatorContract
    {
        $validator = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'allowed_domains' => ['nullable', 'array', 'max:50'],
            'allowed_domains.*' => ['string', 'max:255'],
            'appearance.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.position' => ['required', Rule::in(WebChatSettings::POSITIONS)],
            'appearance.offset_x' => ['required', 'integer', 'between:0,200'],
            'appearance.offset_y' => ['required', 'integer', 'between:0,200'],
            'appearance.title' => ['nullable', 'string', 'max:80'],
            'appearance.subtitle' => ['nullable', 'string', 'max:80'],
            'appearance.welcome_message' => ['nullable', 'string', 'max:500'],
            // sometimes: صفحة إعدادات مفتوحة قبل الحقول الإنجليزية تحفظ بدونها.
            'appearance.title_en' => ['sometimes', 'nullable', 'string', 'max:80'],
            'appearance.subtitle_en' => ['sometimes', 'nullable', 'string', 'max:80'],
            'appearance.welcome_message_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'appearance.language' => ['required', Rule::in(WebChatSettings::LANGUAGES)],
            'prechat.default_country' => ['required', 'string', 'size:2', 'alpha'],
            // sometimes: صفحة إعدادات مفتوحة من قبل هذا الحقل تحفظ بدونه، فيبقى
            // الافتراضي (إجباري) بدل خطأ على حقل لا يراه التاجر.
            'prechat.phone' => ['sometimes', Rule::in(WebChatSettings::PHONE_MODES)],
            'prechat.email' => ['required', Rule::in(WebChatSettings::EMAIL_MODES)],
            'prechat.name_label' => ['nullable', 'string', 'max:60'],
            'prechat.phone_label' => ['nullable', 'string', 'max:60'],
            'prechat.consent_text' => ['nullable', 'string', 'max:500'],
            'prechat.consent_text_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            // sometimes: صفحة أو تطبيقٌ أقدم منهما يحفظ بدونهما فتبقى المحفوظة.
            'prechat.handoff_message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'prechat.handoff_message_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            // أسئلة البداية. sometimes: صفحة فُتحت قبل منشئ الأسئلة تحفظ بدونه
            // فتبقى الأسئلة المحفوظة كما هي.
            'prechat.questions' => ['sometimes', 'nullable', 'array', 'max:' . WebChatQuestions::MAX],
            'prechat.questions.*.target' => ['required', Rule::in(WebChatQuestions::TARGETS)],
            'prechat.questions.*.field' => [
                'nullable',
                'required_if:prechat.questions.*.target,field',
                'string',
                Rule::exists('contact_fields', 'uuid')
                    ->where('organization_id', $account->organization_id)
                    ->whereNull('deleted_at'),
            ],
            'prechat.questions.*.validation' => ['nullable', Rule::in(WebChatQuestions::VALIDATIONS)],
            'prechat.questions.*.required' => ['boolean'],
            'prechat.questions.*.label_ar' => ['nullable', 'string', 'max:200'],
            'prechat.questions.*.label_en' => ['nullable', 'string', 'max:200'],
            'behavior.show_agent_names' => ['required', 'boolean'],
            'behavior.sound' => ['required', 'boolean'],
            'behavior.uploads' => ['required', 'boolean'],
        ], [
            'prechat.questions.max' => __('Up to :max questions.', ['max' => WebChatQuestions::MAX]),
            'prechat.questions.*.field.exists' => __('This contact field no longer exists.'),
        ]);

        $questions = data_get($input, 'prechat.questions');
        $validator->after(fn ($validator) => $this->checkQuestions($validator, $account, $questions));

        return $validator;
    }

    /**
     * حفظ ما مرّ بالقواعد فوق المحفوظ.
     *
     * @param  array<string, mixed>  $validated
     */
    public function apply(ChannelAccount $account, array $validated): void
    {
        $current = WebChatSettings::for($account);

        $account->forceFill(['settings' => WebChatSettings::merge([
            'enabled' => (bool) $validated['enabled'],
            'allowed_domains' => array_values((array) ($validated['allowed_domains'] ?? [])),
            // الشعار له مساره الخاص (storeLogo)، فلا يمحوه حفظ النموذج. والنسخ
            // الإنجليزية الغائبة عن الطلب (صفحة فُتحت قبلها) تبقى كما حُفظت.
            'appearance' => array_replace(
                array_intersect_key($current['appearance'], array_flip(['title_en', 'subtitle_en', 'welcome_message_en'])),
                $validated['appearance'],
                [
                    'logo_url' => $current['appearance']['logo_url'],
                    'logo_path' => $current['appearance']['logo_path'],
                ]
            ),
            'prechat' => array_replace(
                array_intersect_key($current['prechat'], array_flip(['consent_text_en', 'questions', 'handoff_message', 'handoff_message_en'])),
                $validated['prechat']
            ),
            'behavior' => $validated['behavior'],
        ])])->save();
    }

    /** @return array<string, array<int, string>> */
    public function logoRules(): array
    {
        return [
            // لا SVG: يُخدم من نطاقنا ويحمل سكربتاً.
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ];
    }

    /** شعار الـ widget: صورة صغيرة تُخزَّن عندنا ويُحسب رابطها عند العرض. */
    public function storeLogo(ChannelAccount $account, UploadedFile $file): void
    {
        $name = strtolower((string) Str::ulid()) . '.' . $file->extension();
        $settings = WebChatSettings::for($account);

        if (Setting::where('key', 'storage_system')->value('value') === 'aws') {
            $path = $file->storeAs(self::LOGO_DIRECTORY . '/' . $account->organization_id, $name, 's3');
            $settings['appearance']['logo_url'] = $path ? Storage::disk('s3')->url($path) : null;
            $settings['appearance']['logo_path'] = null;
        } else {
            $path = $file->storeAs('public/' . self::LOGO_DIRECTORY . '/' . $account->organization_id, $name, 'local');
            $settings['appearance']['logo_path'] = $path ?: null;
            $settings['appearance']['logo_url'] = null;
        }

        $this->deleteLocalLogo(WebChatSettings::for($account)['appearance']['logo_path']);
        $account->forceFill(['settings' => WebChatSettings::merge($settings)])->save();
    }

    public function removeLogo(ChannelAccount $account): void
    {
        $settings = WebChatSettings::for($account);

        $this->deleteLocalLogo($settings['appearance']['logo_path']);
        $settings['appearance']['logo_path'] = null;
        $settings['appearance']['logo_url'] = null;

        $account->forceFill(['settings' => WebChatSettings::merge($settings)])->save();
    }

    /** ما تعرضه صفحة الإعدادات. */
    public function view(ChannelAccount $account): array
    {
        $settings = WebChatSettings::for($account);
        $key = (string) $account->external_id;

        return [
            'key' => $key,
            'snippet' => self::snippet($key),
            'settings' => $settings,
            'logo' => WebChatSettings::publicView($account)['appearance']['logo_url'],
            'preview_url' => url('/settings/webchat/preview'),
            // منشئ الأسئلة: ما يسأله الـ widget الآن (المحفوظ، أو المشتقّ من
            // النموذج القديم فيصير صريحاً بأوّل حفظ)، والافتراضي، والحقول.
            'questions' => $settings['prechat']['questions'] ?? WebChatQuestions::derive($settings['prechat']),
            'default_questions' => WebChatQuestions::defaults(),
            'contact_fields' => ContactField::query()
                ->where('organization_id', $account->organization_id)
                ->whereNull('deleted_at')
                ->orderBy('position')
                ->get(['uuid', 'name', 'type', 'value'])
                ->map(fn (ContactField $field) => $field->only(['uuid', 'name', 'type', 'value']))
                ->values()
                ->all(),
            'faqs' => WebChatFaqs::forDashboard((int) $account->organization_id),
            'faqs_max' => WebChatFaqs::MAX,
        ];
    }

    public static function snippet(string $key, bool $preview = false): string
    {
        return '<script async src="' . e(WebChatAssets::loaderUrl()) . '" data-mnjz-key="' . e($key) . '"'
            . ($preview ? ' data-mnjz-preview="1"' : '')
            . '></script>';
    }

    /**
     * ما لا تقوله القواعد وحدها: لكل حقل سؤال واحد، ومربّع الاختيار لا يُسأل
     * (يُجاب بنعم/لا لا بكتابة). الخادم يطبّع أيضاً، لكن التاجر يُقال له هنا.
     */
    private function checkQuestions($validator, ChannelAccount $account, mixed $questions): void
    {
        if (!is_array($questions)) {
            return;
        }

        $seen = [];
        $fields = array_values(array_filter(array_map(
            fn ($question) => is_array($question) && ($question['target'] ?? null) === 'field' ? ($question['field'] ?? null) : null,
            $questions
        )));
        $checkboxes = $fields === [] ? [] : ContactField::query()
            ->where('organization_id', $account->organization_id)
            ->whereIn('uuid', $fields)
            ->where('type', 'checkbox')
            ->pluck('uuid')
            ->all();

        foreach ($questions as $index => $question) {
            if (!is_array($question)) {
                continue;
            }

            $id = ($question['target'] ?? null) === 'field' ? 'f_' . ($question['field'] ?? '') : ($question['target'] ?? '');

            if (isset($seen[$id])) {
                $validator->errors()->add("prechat.questions.$index.target", __('Each field can be asked only once.'));
            }

            $seen[$id] = true;

            if (in_array($question['field'] ?? null, $checkboxes, true)) {
                $validator->errors()->add("prechat.questions.$index.field", __('Checkbox fields cannot be asked.'));
            }
        }
    }

    private function deleteLocalLogo(?string $path): void
    {
        if ($path && str_starts_with($path, 'public/' . self::LOGO_DIRECTORY . '/')) {
            Storage::disk('local')->delete($path);
        }
    }
}
