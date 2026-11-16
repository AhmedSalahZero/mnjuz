<?php

namespace App\Http\Requests\WebChat;

use App\Services\WebChat\WebChatAnswers;
use App\Services\WebChat\WebChatPhone;
use App\Services\WebChat\WebChatQuestions;
use App\Services\WebChat\WebChatSettings;
use Illuminate\Validation\Validator;

/**
 * بداية المحادثة: إجابات أسئلة البداية كما حدّدها التاجر (WebChatQuestions).
 *
 * الـ widget يرسلها بمعرّف السؤال (answers)، والأخطاء تعود بالمعرّف نفسه
 * فيُعيد السؤال الخاطئ. والـ widget القديم (في ذاكرة متصفّحٍ أثناء النشر)
 * يرسل name/phone/email مسطّحة: تُقرأ إجاباتٍ لأسئلة name/phone/email.
 *
 * الإجابة الاختيارية إن كُتبت يُتحقَّق منها كالإجبارية، والسؤال الذي لا
 * يسأله الـ widget يُتجاهل ولو أُرسل.
 *
 * حماية من الآليّين بلا captcha: حقل مخفيّ (website) لا يملؤه إنسان، وزمن
 * أدنى بين ظهور الأسئلة وإرسالها.
 */
class StartWebChatSessionRequest extends WebChatRequest
{
    public const MIN_FILL_MS = 1500;

    /** @var array<string, string> القيم المحفوظة بمعرّف السؤال، بعد التحقّق. */
    private array $values = [];

    private ?array $settings = null;

    private ?array $questions = null;

    public function rules(): array
    {
        return [
            'answers' => ['sometimes', 'array', 'max:20'],
            'countries' => ['sometimes', 'array', 'max:20'],
            'countries.*' => ['nullable', 'string', 'size:2'],
            'country' => ['nullable', 'string', 'size:2'],
            'consent' => $this->settings()['prechat']['consent_text'] ? ['accepted'] : ['nullable'],
            'website' => ['nullable', 'max:0'],
            'elapsed_ms' => ['required', 'integer', 'min:' . self::MIN_FILL_MS],
            'page_url' => ['nullable', 'string', 'max:2048'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'locale' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return ['consent.accepted' => __('Please accept to continue.')];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $result = WebChatAnswers::validate(
                $this->questions(),
                $this->answers(),
                $this->countries(),
                $this->settings()['prechat']['default_country']
            );

            foreach ($result['errors'] as $id => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($id, $message);
                }
            }

            $this->values = $result['values'];
        });
    }

    public function visitorName(): string
    {
        return $this->values['name'] ?? '';
    }

    /** الرقم بصيغة E.164، أو null حين لا يُسأل أو تُرك فارغاً. */
    public function e164(): ?string
    {
        return $this->values['phone'] ?? null;
    }

    public function emailOrNull(): ?string
    {
        return $this->values['email'] ?? null;
    }

    /** @return array<string, string> اسم الحقل المخصّص ⇐ الإجابة (مفتاح contacts.metadata). */
    public function fieldAnswers(): array
    {
        $answers = [];

        foreach ($this->questions() as $question) {
            if ($question['target'] === 'field' && isset($this->values[$question['id']])) {
                $answers[$question['field_name']] = $this->values[$question['id']];
            }
        }

        return $answers;
    }

    /** هل أجاب الزائر عن شيء؟ بلا إجابة لا تُنشأ جهة اتصال فارغة. */
    public function hasAnswers(): bool
    {
        return $this->values !== [];
    }

    /**
     * رقم دولي من نصّ حرّ. يستعمله منشئ المسارات (update_contact) على ما
     * يكتبه العميل في المحادثة، فيبقى متساهلاً كما كان. انظر WebChatPhone.
     */
    public static function toE164(string $phone, ?string $country): ?string
    {
        return WebChatPhone::lenient($phone, $country);
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        $answers = $this->input('answers');

        if (is_array($answers)) {
            return $answers;
        }

        // الـ widget القديم: حقول مسطّحة بأسماء الأسئلة الأساسية.
        return array_filter([
            'name' => $this->input('name'),
            'phone' => $this->input('phone'),
            'email' => $this->input('email'),
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string, ?string> دولة كل سؤال جوال. */
    private function countries(): array
    {
        $countries = $this->input('countries');
        $countries = is_array($countries) ? $countries : [];

        // الـ widget القديم يرسل دولة الجوال في country.
        if (!isset($countries['phone']) && is_string($this->input('country'))) {
            $countries['phone'] = $this->input('country');
        }

        return array_map(static fn ($country) => is_string($country) ? strtoupper($country) : null, $countries);
    }

    /** إعداد الـ widget مرّة لكل طلب: القواعد والتحقّق والقراءة تسأل عنه. */
    private function settings(): array
    {
        return $this->settings ??= WebChatSettings::for($this->widget());
    }

    private function questions(): array
    {
        return $this->questions ??= WebChatQuestions::forAccount($this->widget(), $this->settings());
    }
}
