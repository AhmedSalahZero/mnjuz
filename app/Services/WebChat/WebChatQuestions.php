<?php

namespace App\Services\WebChat;

use App\Models\ChannelAccount;
use App\Models\ContactField;

/**
 * أسئلة البداية في المحادثة المباشرة: يحدّدها التاجر، ويسألها الـ widget
 * سؤالاً سؤالاً قبل المحادثة، وتُحفظ كل إجابة في حقلها من جهة الاتصال.
 *
 * السؤال: {id, target, field, validation, required, label_ar, label_en}.
 * - target: name | email | phone | field (حقل مخصّص من contact_fields).
 * - id مشتقّ من الهدف (name، email، phone، f_<uuid>)، فلكل حقل سؤال واحد
 *   بالبناء نفسه — وأخطاء التحقّق تعود بمفاتيح name/phone/email كما كانت.
 *
 * prechat.questions = null يعني «لم يحدّدها التاجر بعد»: تُشتقّ من إعداد
 * النموذج القديم (الاسم، والجوال والبريد بحسب وضعهما)، فيسأل كل widget
 * قائم ما كان يسأله.
 */
final class WebChatQuestions
{
    public const MAX = 12;
    public const TARGETS = ['name', 'email', 'phone', 'field'];
    public const VALIDATIONS = ['text', 'number', 'email', 'phone', 'date', 'select', 'saudi_id'];
    /** ما يختاره التاجر لحقل مخصّص نصّي (select يُفرض من نوع الحقل). */
    public const FIELD_VALIDATIONS = ['text', 'number', 'email', 'phone', 'date', 'saudi_id'];
    private const LABEL_MAX = 200;

    /** لكل widget جديد: الاسم والبريد والجوال، كلها إجبارية. */
    public static function defaults(): array
    {
        return [
            self::builtIn('name', true),
            self::builtIn('email', true),
            self::builtIn('phone', true),
        ];
    }

    /** إعداد النموذج القديم بالترتيب الذي كان يعرضه: الاسم، الجوال، البريد. */
    public static function derive(array $prechat): array
    {
        $questions = [self::builtIn('name', true, $prechat['name_label'] ?? null)];

        $phone = $prechat['phone'] ?? 'required';
        if ($phone !== 'off') {
            $questions[] = self::builtIn('phone', $phone !== 'optional', $prechat['phone_label'] ?? null);
        }

        $email = $prechat['email'] ?? 'off';
        if ($email === 'optional' || $email === 'required') {
            $questions[] = self::builtIn('email', $email === 'required');
        }

        return $questions;
    }

    /**
     * ما يُحفظ: قائمة نظيفة، أو null (اشتقاق). يُستدعى من WebChatSettings::merge
     * فلا تصل قيمة غير متوقّعة إلى أيّ قارئ.
     */
    public static function normalize(mixed $questions): ?array
    {
        if (!is_array($questions)) {
            return null;
        }

        $clean = [];

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $target = $question['target'] ?? null;

            if (!in_array($target, self::TARGETS, true)) {
                continue;
            }

            $field = null;

            if ($target === 'field') {
                $field = is_string($question['field'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $question['field'])
                    ? strtolower($question['field'])
                    : null;

                if ($field === null) {
                    continue;
                }
            }

            $id = $target === 'field' ? 'f_' . $field : $target;

            if (isset($clean[$id])) {
                continue;
            }

            $clean[$id] = [
                'id' => $id,
                'target' => $target,
                'field' => $field,
                'validation' => self::validationFor($target, $question['validation'] ?? null),
                'required' => (bool) ($question['required'] ?? false),
                'label_ar' => self::label($question['label_ar'] ?? null),
                'label_en' => self::label($question['label_en'] ?? null),
            ];

            if (count($clean) >= self::MAX) {
                break;
            }
        }

        return array_values($clean);
    }

    /**
     * أسئلة الـ widget فعلاً: المحفوظة أو المشتقّة، والحقول المخصّصة من
     * contact_fields الآن — حقل حُذف لا يُسأل، والقائمة المنسدلة تحمل خياراتها.
     *
     * @return array<int, array> لكل سؤال حقلٍ: field_name (مفتاح metadata).
     */
    public static function forAccount(ChannelAccount $account, ?array $settings = null): array
    {
        $settings ??= WebChatSettings::for($account);
        $questions = $settings['prechat']['questions'] ?? null;
        $questions = is_array($questions) ? $questions : self::derive($settings['prechat']);

        $uuids = array_values(array_filter(array_column($questions, 'field')));
        $fields = $uuids === []
            ? collect()
            : ContactField::query()
                ->where('organization_id', $account->organization_id)
                ->whereIn('uuid', $uuids)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy(fn (ContactField $field) => strtolower((string) $field->uuid));

        $resolved = [];

        foreach ($questions as $question) {
            if ($question['target'] === 'field') {
                $field = $fields->get($question['field']);

                // حقلٌ حُذف، أو مربّع اختيار لا يُجاب بكتابة.
                if (!$field || $field->type === 'checkbox') {
                    continue;
                }

                $question['field_name'] = (string) $field->name;

                if ($field->type === 'select') {
                    $question['validation'] = 'select';
                    $question['options'] = self::options((string) $field->value);
                } elseif ($question['validation'] === 'select') {
                    $question['validation'] = 'text';
                }
            }

            $resolved[] = $question;
        }

        return $resolved;
    }

    /** ما يصل المتصفّح: بلا uuid الحقل ولا اسمه الداخلي إلا عنواناً احتياطياً. */
    public static function publicView(array $resolved): array
    {
        return array_map(static function (array $question) {
            $view = [
                'id' => $question['id'],
                'target' => $question['target'],
                'validation' => $question['validation'],
                'required' => $question['required'],
                'label_ar' => $question['label_ar'] ?? ($question['field_name'] ?? null),
                'label_en' => $question['label_en'],
            ];

            if (isset($question['options'])) {
                $view['options'] = $question['options'];
            }

            return $view;
        }, $resolved);
    }

    /** خيارات حقل select كما تحفظها صفحة الحقول («أ, ب, ج»). */
    public static function options(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(', ', $value)),
            static fn (string $option) => $option !== ''
        ));
    }

    private static function builtIn(string $target, bool $required, ?string $labelAr = null): array
    {
        return [
            'id' => $target,
            'target' => $target,
            'field' => null,
            'validation' => self::validationFor($target, null),
            'required' => $required,
            'label_ar' => self::label($labelAr),
            'label_en' => null,
        ];
    }

    private static function validationFor(string $target, mixed $requested): string
    {
        return match ($target) {
            'name' => 'text',
            'email' => 'email',
            'phone' => 'phone',
            default => in_array($requested, self::VALIDATIONS, true) ? $requested : 'text',
        };
    }

    private static function label(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, self::LABEL_MAX);
    }
}
