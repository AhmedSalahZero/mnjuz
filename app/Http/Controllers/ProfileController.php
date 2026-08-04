<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreProfile;
use App\Http\Requests\StoreProfileAddress;
use App\Http\Requests\StoreProfilePassword;
use App\Http\Requests\StoreProfileTfa;
use App\Http\Requests\Verification\UpdateVerificationSettingRequest;
use App\Exceptions\WazBusinessException;
use App\Models\Organization;
use App\Models\User;
use App\Services\WazBusinessService;
use Illuminate\Support\Facades\Log;
use DB;
use Hash;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Redirect;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

class ProfileController extends BaseController
{
    public function update(StoreProfile $request)
    {
        $first_name = $request->first_name;
        $last_name = $request->last_name;
        $email = $request->email;
        $phone = $request->phone;
        $language = $request->language;

        // Get current user language before update
        $currentUser = auth()->user();
        $oldLanguage = $currentUser->language ?? 'en';
        
        $response = User::where('id', auth()->user()->id)->update([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'language' => $language,
        ]);

        // Set the session locale to the user's selected language
        if ($language) {
            session(['locale' => $language]);
        }

        // Check if language was changed and add refresh parameter
        $needsRefresh = $language && $language !== $oldLanguage;
        
        if ($needsRefresh) {
            // For Inertia.js, we need to redirect with the refresh parameter
            return redirect()->back()->with([
                'status' => [
                    'type' => 'success', 
                    'message' => __('Profile updated successfully!')
                ],
                'refresh_lang' => true
            ]);
        }

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Profile updated successfully!')
            ]
        );
    }

    public function updatePassword(StoreProfilePassword $request)
    {
        $old_password = $request->old_password;
        $password = Hash::make($request->password);

        $response = User::where('id', auth()->user()->id)->update([
            'password' => $password,
        ]);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Profile updated successfully!')
            ]
        );
    }

    public function tfaSetup(): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        $tfa = new TwoFactorAuth(new BaconQrCodeProvider());

        $secret = $user->tfa_secret;
        if (!$secret) {
            $secret = $tfa->createSecret();
            User::where('id', $user->id)->update(['tfa_secret' => $secret]);
        }

        $qrcode = $tfa->getQRCodeImageAsDataUri(
            preg_replace('#^https?://#', '', config('app.url')),
            $secret
        );

        return response()->json([
            'secret' => $secret,
            'qrcode' => $qrcode,
        ]);
    }

    public function updateTfa(StoreProfileTfa $request){
        $status = $request->status;
        $token = $request->token;
        $userId = auth()->user()->id;

        if ($status === 0) {
            User::where('id', $userId)->update([
                'tfa' => 0,
            ]);

            return Redirect::back()->with('status', [
                'type' => 'success',
                'message' => __('Two-factor authentication disabled successfully!'),
            ]);
        }

        User::where('id', $userId)->update(['tfa' => true]);

        return Redirect::back()->with('status', [
            'type' => 'success',
            'message' => __('Two-factor authentication enabled successfully!'),
        ]);
    }

    public function updateVerification(UpdateVerificationSettingRequest $request)
    {
        User::where('id', auth()->user()->id)->update([
            'verification_enabled' => (bool) $request->boolean('verification_enabled'),
        ]);

        return Redirect::back()->with('status', [
            'type' => 'success',
            'message' => __('Verification setting updated successfully!'),
        ]);
    }

    public function updateOrganization(StoreProfileAddress $request)
    {
        $organizationId = session('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

        $metadataArray['notifications']['enable_sound'] = $request->input('enable_sound_notification');
        $metadataArray['notifications']['tone'] = $request->input('tone');
        $metadataArray['notifications']['volume'] = $request->input('volume');
        $metadataArray['timezone'] = $request->input('timezone');
		$metadataArray['auth_template'] = $request->input('auth_template');
		$metadataArray['auth_template_parameters'] = $request->input('auth_template_parameters');

        $metadataArray['campaigns']['enable_resend'] = $request->input('enable_campaign_resend');
        $metadataArray['campaigns']['move_failed_contacts_to_group'] = $request->input('move_failed_contacts_to_group');
        $metadataArray['campaigns']['resend_intervals'] = $request->input('resend_intervals');
        $metadataArray['campaigns']['failed_campaign_group'] = $request->input('failed_campaign_group');

        if (! isset($metadataArray['support'])) {
            $metadataArray['support'] = [];
        }
        $metadataArray['support']['ticket_form_url'] = $request->input('support_ticket_form_url');

        $addressArray['street'] = $request->input('address');
        $addressArray['city'] = $request->input('city');
        $addressArray['state'] = $request->input('state');
        $addressArray['zip'] = $request->input('zip');
        $addressArray['country'] = $request->input('country');

        // نلتقط الحالة قبل الحفظ لنعرف ما تغيّر فعلاً — واز تقبل التعديل
        // الجزئي، فلا نرسل حقولاً لم يمسّها المستخدم.
        $wazChanges = $this->wazCompanyChanges($organizationConfig, $request->input('organization_name'), $addressArray);

        $organizationConfig->name = $request->input('organization_name');
        $organizationConfig->address = json_encode($addressArray);
        $organizationConfig->metadata = json_encode($metadataArray);

        if($organizationConfig->save()){
            // المزامنة بعد الحفظ المحلي: بياناتنا هي المصدر، وتعذّر الوصول
            // لواز لا يمنع العميل من تعديل إعداداته.
            $synced = $this->syncCompanyChanges($organizationConfig, $wazChanges);

            return Redirect::back()->with(
                'status', $synced
                    ? ['type' => 'success', 'message' => __('Organization updated successfully!')]
                    : ['type' => 'warning', 'message' => __('Your changes were saved, but updating them on the billing platform is delayed.')]
            );
        } else {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Something went wrong. Refresh the page and try again')
                ]
            );
        }
    }

    /**
     * الفروق بين بيانات المنشأة المحفوظة وما أُرسل — بمفاتيح WazBusinessService.
     *
     * @param  array<string, mixed>  $newAddress
     * @return array<string, mixed>
     */
    private function wazCompanyChanges(Organization $organization, ?string $newName, array $newAddress): array
    {
        $old = json_decode((string) $organization->address, true) ?: [];
        $changes = [];

        if ((string) $organization->name !== (string) $newName) {
            $changes['company'] = $newName;
        }

        foreach (['street', 'city', 'state', 'zip'] as $field) {
            if ((string) ($old[$field] ?? '') !== (string) ($newAddress[$field] ?? '')) {
                $changes[$field] = $newAddress[$field];
            }
        }

        // نموذج الإعدادات يحمل اسم الدولة، وواز تقبل المعرّف الرقمي فقط.
        if ((string) ($old['country'] ?? '') !== (string) ($newAddress['country'] ?? '')) {
            $countryId = app(WazBusinessService::class)->countryId($newAddress['country'] ?? null);
            if ($countryId !== null) {
                $changes['country_id'] = $countryId;
            }
        }

        return $changes;
    }

    /**
     * دفع التعديلات إلى واز أعمال. يرجع false عند تعذّر ذلك ليُنبَّه المستخدم.
     *
     * @param  array<string, mixed>  $changes
     */
    private function syncCompanyChanges(Organization $organization, array $changes): bool
    {
        if (!$changes || !$organization->waz_company_id) {
            return true;
        }

        $waz = app(WazBusinessService::class);
        if (!$waz->isConfigured()) {
            return true;
        }

        try {
            $waz->updateCompany((int) $organization->waz_company_id, $changes);
        } catch (WazBusinessException $e) {
            Log::error('Waz: failed to sync organization changes', [
                'organization_id' => $organization->id,
                'waz_company_id' => $organization->waz_company_id,
                'fields' => array_keys($changes),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
