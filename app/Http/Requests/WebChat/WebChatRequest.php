<?php

namespace App\Http\Requests\WebChat;

use App\Http\Middleware\WebChat\AuthenticateWebChatVisitor;
use App\Http\Middleware\WebChat\ResolveWebChatWidget;
use App\Models\ChannelAccount;
use App\Models\WebChatVisitor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * أساس طلبات الـ widget: خطأ التحقّق JSON دائماً. الـ widget لا يتبع
 * إعادة توجيه — صفحة التاجر ليست صفحتنا.
 */
abstract class WebChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function widget(): ChannelAccount
    {
        return $this->attributes->get(ResolveWebChatWidget::ATTRIBUTE);
    }

    public function visitor(): ?WebChatVisitor
    {
        return $this->attributes->get(AuthenticateWebChatVisitor::ATTRIBUTE);
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'error' => 'validation_failed',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * ما فعله الزائر قبل أوّل رسالة (core/faq.ts journeyContext): يأتي مع
     * أوّل رسالة أو ملف، ويحفظه WebChatLeads::recordJourney مرّة واحدة.
     */
    protected static function journeyRules(): array
    {
        return [
            'context' => ['nullable', 'array:menu,faqs,not_found'],
            'context.menu' => ['nullable', 'string', 'in:' . implode(',', \App\Services\WebChat\WebChatLeads::MENU_CHOICES)],
            'context.faqs' => ['nullable', 'array', 'max:' . \App\Services\WebChat\WebChatFaqs::MAX],
            'context.faqs.*' => ['integer', 'min:1'],
            'context.not_found' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{menu?: ?string, faqs?: ?array<int, int|string>, not_found?: mixed}|null */
    public function journeyContext(): ?array
    {
        $context = $this->validated('context');

        return is_array($context) ? $context : null;
    }

    /** نصّ حرّ من الزائر بلا محارف تحكّم (ما عدا السطر الجديد والجدولة). */
    protected static function cleanText(?string $value, int $max): string
    {
        return \App\Services\WebChat\WebChatAnswers::cleanText($value, $max);
    }
}
