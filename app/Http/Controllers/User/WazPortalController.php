<?php

namespace App\Http\Controllers\User;

use App\Exceptions\WazBusinessException;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\BookMeetingRequest;
use App\Services\WazBusinessService;
use App\Services\WazSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

/**
 * ما يعرضه العميل من منصة واز أعمال داخل منجز شات: فواتيره الرسمية وحجز
 * مواعيد الدعم. مصدر البيانات هو واز مباشرة لا نسخة محلية، فلا يوجد مصدرا
 * حقيقة يتباعدان.
 */
class WazPortalController extends BaseController
{
    public function invoices(WazSyncService $waz)
    {
        $organizationId = (int) session('current_organization');

        $rows = [];
        $error = null;

        if ($waz->enabled()) {
            try {
                $rows = $waz->invoicesFor($organizationId);
            } catch (WazBusinessException $e) {
                $error = __('We could not load your invoices right now.');
                Log::error('Waz: failed to load invoices', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Inertia::render('User/Billing/WazInvoices', [
            'title' => __('Invoices'),
            'rows' => $rows,
            'loadError' => $error,
        ]);
    }

    public function meetings(WazSyncService $waz)
    {
        $organizationId = (int) session('current_organization');
        $available = $waz->enabled() && $waz->companyId($organizationId) !== null;

        $rows = [];
        if ($available) {
            try {
                $rows = $waz->meetingsFor($organizationId);
            } catch (WazBusinessException $e) {
                Log::error('Waz: failed to load meetings', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Inertia::render('User/Support/BookMeeting', [
            'title' => __('Book a meeting'),
            'reasons' => $this->meetingReasons(),
            'available' => $available,
            'rows' => $rows,
        ]);
    }

    /**
     * إلغاء موعد. الخدمة تتحقق من ملكيته للمنشأة قبل الحذف.
     */
    public function cancelMeeting(int $meetingId, WazSyncService $waz)
    {
        $cancelled = $waz->cancelMeeting((int) session('current_organization'), $meetingId);

        return Redirect::back()->with('status', $cancelled
            ? ['type' => 'success', 'message' => __('The meeting was cancelled.')]
            : ['type' => 'error', 'message' => __('We could not cancel this meeting.')]);
    }

    public function bookMeeting(BookMeetingRequest $request, WazSyncService $sync, WazBusinessService $waz)
    {
        $organizationId = (int) session('current_organization');
        $companyId = $sync->companyId($organizationId);

        if (!$companyId) {
            return Redirect::back()->with('status', [
                'type' => 'error',
                'message' => __('Your organization is not linked to the support platform yet.'),
            ]);
        }

        try {
            $waz->bookMeeting([
                'company_id' => $companyId,
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'start' => \Carbon\Carbon::parse($request->input('start')),
                'reason' => $request->input('reason'),
            ]);
        } catch (WazBusinessException $e) {
            Log::error('Waz: failed to book meeting', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return Redirect::back()->withInput()->with('status', [
                'type' => 'error',
                'message' => __('We could not book your meeting right now. Please try again shortly.'),
            ]);
        }

        return Redirect::back()->with('status', [
            'type' => 'success',
            'message' => __('Your meeting request was sent successfully.'),
        ]);
    }

    /**
     * أسباب الاجتماع كما يصنّفها فريق الدعم — كل سبب لون في تقويمهم.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function meetingReasons(): array
    {
        $labels = [
            'platform_issue' => __('A problem with the platform'),
            'how_to_use' => __('How to use the platform'),
            'campaigns' => __('Marketing campaigns'),
            'auto_reply_chatbot' => __('Auto reply and chatbot'),
            'contacts_import' => __('Contacts and importing'),
        ];

        $reasons = [];
        foreach (array_keys(config('waz.meetings.colors', [])) as $key) {
            $reasons[] = ['value' => $key, 'label' => $labels[$key] ?? $key];
        }

        return $reasons;
    }
}
