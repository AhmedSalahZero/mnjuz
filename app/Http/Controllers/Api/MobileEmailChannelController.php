<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailChannel;
use App\Services\ActivityLogger;
use App\Services\Email\EmailChannelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * بريد التاجر لحملات البريد (SMTP) من التطبيق — للمالك والمدير.
 *
 * نفس قواعد الويب (StoreEmailChannel) ونفس الحفظ (EmailChannelSettings):
 * لا يُحفظ حساب قبل أن يقبل الخادم تسجيل الدخول، وكلمة المرور لا تُرسل
 * أبداً (has_password وحده). الفصل من DELETE /settings/channels/{uuid}.
 */
class MobileEmailChannelController extends Controller
{
    use MobileApi;

    public function __construct(private readonly EmailChannelSettings $email)
    {
    }

    public function show(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        return $this->ok($this->email->view($this->email->account($this->organizationId($request))));
    }

    public function save(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);
        $form = new StoreEmailChannel();
        $validator = Validator::make($request->all(), $form->rules(), $form->messages());

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        try {
            $result = $this->email->save($organizationId, $validator->validated(), (int) $request->user()->id);
        } catch (ValidationException $e) {
            // رفض الخادم (كلمة مرور خاطئة، خادم لا يُوصل إليه) على حقله، كما
            // يعرضه نموذج الويب تحت الحقل نفسه.
            return $this->fail(400, __('The provided data is invalid.'), $e->errors());
        }

        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, __('Email'), null, null, [], $organizationId);

        return $this->ok($this->email->view($result->account), $result->message);
    }

    /** رسالة تجربة إلى بريد المستخدم نفسه. */
    public function test(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $result = $this->email->sendTest($this->organizationId($request), (string) $request->user()->email);

        return $result->isError()
            ? $this->fail(422, $result->message)
            : $this->ok(null, $result->message);
    }
}
