<?php

namespace App\Http\Requests;

use App\Services\Email\SmtpHostGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ربط بريد التاجر (SMTP). كلمة المرور لا تُرسل للمتصفّح أبداً، فتُترك فارغة
 * لإبقاء المحفوظة — إلا إن تغيّر الخادم أو المنفذ أو اسم المستخدم: جلسة
 * مخترَقة لا تستطيع توجيه كلمة المرور المحفوظة إلى خادم تختاره.
 */
class StoreEmailChannel extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'in:' . implode(',', SmtpHostGuard::ALLOWED_PORTS)],
            'encryption' => ['required', 'in:tls,ssl'],
            'username' => ['required', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:512'],
            'from_address' => ['required', 'email:rfc', 'max:191'],
            'from_name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'reply_to' => ['nullable', 'email:rfc', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return [
            'host.regex' => __('Enter the SMTP server name only, without http:// or a path.'),
            'port.in' => __('Use the SMTP submission port 465, 587 or 2525.'),
            'from_name.not_regex' => __('The sender name must be on one line.'),
        ];
    }
}
