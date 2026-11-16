<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmailChannel;
use App\Models\Addon;
use App\Services\Email\EmailChannelSettings;
use Inertia\Inertia;

/**
 * بريد التاجر لحملات البريد: خادم SMTP خاصّ به (Gmail، Zoho، دومينه).
 *
 * يُحفظ صفّاً في channel_accounts (channel = email) ببيانات مشفّرة، صفٌّ
 * واحد لكل منشأة. لا يمرّ بـ ChannelManager::driver(): البريد قناة إرسال
 * فقط، بلا webhook ولا محادثة.
 *
 * لا يُحفظ حساب قبل أن يقبل الخادم تسجيل الدخول — حساب محفوظ ببيانات
 * خاطئة يبدو مربوطاً وتفشل كل حملاته.
 */
class EmailChannelController extends Controller
{
    public function __construct(private readonly EmailChannelSettings $email)
    {
    }

    public function show()
    {
        $view = $this->email->view($this->email->account($this->organizationId()));

        return Inertia::render('User/Settings/EmailChannel', [
            'title' => __('Email'),
            'account' => $view['account'],
            'smtp' => $view['smtp'],
            'ports' => $view['ports'],
            'modules' => Addon::get(),
        ]);
    }

    public function save(StoreEmailChannel $request)
    {
        $result = $this->email->save($this->organizationId(), $request->validated(), auth()->id());

        return back()->with('status', $result->toFlash());
    }

    /** رسالة تجربة إلى بريد المستخدم نفسه — تكشف ما لا يكشفه تسجيل الدخول (رفض المرسِل). */
    public function sendTest()
    {
        $result = $this->email->sendTest($this->organizationId(), (string) auth()->user()->email);

        return back()->with('status', $result->toFlash());
    }

    private function organizationId(): int
    {
        return (int) session()->get('current_organization');
    }
}
