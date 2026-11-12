<?php

namespace App\Http\Requests\WebChat;

use App\Services\WebChat\WebChatSettings;
use Illuminate\Validation\Validator;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * نموذج ما قبل المحادثة: الاسم إجباري دائماً، والجوال والبريد حسب إعداد التاجر
 * (مخفيّ أو اختياري أو إجباري). الجوال إجباري ما لم يغيّره التاجر.
 *
 * الرقم الاختياري إن كُتب يُتحقَّق منه كالإجباري: رقمٌ خاطئ يُردّ للزائر
 * ليصحّحه، لا يُسقط بصمت. والمخفيّ يُتجاهل ولو أرسله widget قديم في الذاكرة.
 *
 * حماية من الآليّين بلا captcha: حقل مخفيّ (website) لا يملؤه إنسان، وزمن
 * أدنى بين ظهور النموذج وإرساله. السكربت الذي يرسل فوراً أو يملأ كل حقل
 * يُرفض بنفس رسالة الخطأ العامّة.
 */
class StartWebChatSessionRequest extends WebChatRequest
{
    public const MIN_FILL_MS = 1500;

    private ?string $e164 = null;

    private ?array $prechat = null;

    public function rules(): array
    {
        $prechat = $this->prechat();
        $email = $prechat['email'];

        return [
            'name' => ['required', 'string', 'max:80'],
            'phone' => match ($prechat['phone']) {
                'required' => ['required', 'string', 'max:32'],
                'optional' => ['nullable', 'string', 'max:32'],
                default => ['nullable'],
            },
            'country' => ['nullable', 'string', 'size:2'],
            'email' => $email === 'required'
                ? ['required', 'email', 'max:191']
                : ['nullable', 'email', 'max:191'],
            'consent' => $prechat['consent_text'] ? ['accepted'] : ['nullable'],
            'website' => ['nullable', 'max:0'],
            'elapsed_ms' => ['required', 'integer', 'min:' . self::MIN_FILL_MS],
            'page_url' => ['nullable', 'string', 'max:2048'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'locale' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->prechat()['phone'] === 'off' || $validator->errors()->has('phone')) {
                return;
            }

            $phone = trim((string) $this->input('phone'));

            // الاختياري المتروك فارغاً. والإجباري الفارغ أوقفته قاعدة required.
            if ($phone === '') {
                return;
            }

            $this->e164 = self::toE164(
                $phone,
                $this->input('country') ?: $this->prechat()['default_country']
            );

            if ($this->e164 === null) {
                $validator->errors()->add('phone', __('Enter a valid mobile number.'));
            }
        });
    }

    public function visitorName(): string
    {
        return self::cleanText($this->input('name'), 80);
    }

    /** الرقم بصيغة E.164، أو null حين يكون الحقل مخفيّاً أو تُرك فارغاً. */
    public function e164(): ?string
    {
        return $this->e164;
    }

    public function emailOrNull(): ?string
    {
        $email = trim((string) $this->input('email'));

        return $this->prechat()['email'] === 'off' || $email === '' ? null : mb_strtolower($email);
    }

    /** إعداد النموذج مرّة واحدة لكل طلب: القواعد والتحقّق والقراءة تسأل عنه. */
    private function prechat(): array
    {
        return $this->prechat ??= WebChatSettings::for($this->widget())['prechat'];
    }

    /**
     * رقم دولي كامل أو محلّي مع دولة. «0501234567» بدولة SA يصير
     * «+966501234567»، و«+966 50-123 4567» يُقبل كما يكتبه الناس.
     */
    public static function toE164(string $phone, ?string $country): ?string
    {
        $phone = trim($phone);
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if ($digits === '' || strlen(ltrim($digits, '+')) < 6) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        try {
            $number = str_starts_with($digits, '+')
                ? new PhoneNumber($digits)
                : new PhoneNumber($digits, strtoupper((string) $country));

            return $number->isValid() ? $number->formatE164() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
