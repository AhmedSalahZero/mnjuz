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

    /** نصّ حرّ من الزائر بلا محارف تحكّم (ما عدا السطر الجديد والجدولة). */
    protected static function cleanText(?string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }
}
