<?php

namespace App\Helpers;

use App\Models\Organization;

/**
 * هل ربط واتساب صالحٌ لإرسال رسالة؟
 *
 * الفحص كان محبوساً في متحكّم واحد، ومسار رفع القطع يحتاجه قبل قبول أول
 * بايت: رفعُ ستين ميغابايت ثم اكتشافُ أن المنشأة غير مربوطة يُهدر الشبكة
 * والقرص معاً، ولا يصل العميل شيء.
 */
class WhatsappConnectionHelper
{
    /** رسالة الخطأ الصالحة للعرض، أو null إن كان الربط سليماً. */
    public static function errorFor($organizationId): ?string
    {
        $organization = $organizationId ? Organization::find($organizationId) : null;

        if (!$organization) {
            return __('No active organization was found for your account. Please select an organization and try again.');
        }

        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsapp = $metadata['whatsapp'] ?? null;

        if (!is_array($whatsapp) || !$whatsapp) {
            return __('WhatsApp is not connected for :organization. Connect your WhatsApp Business account from Settings → WhatsApp, then try again.', [
                'organization' => $organization->name,
            ]);
        }

        // بيانات الاعتماد الدنيا لأي نداء إلى واجهة واتساب.
        $missing = array_values(array_filter(
            ['access_token', 'phone_number_id', 'waba_id'],
            fn ($key) => empty($whatsapp[$key])
        ));

        if ($missing) {
            return __('The WhatsApp connection for :organization is incomplete (missing: :fields). Reconnect it from Settings → WhatsApp.', [
                'organization' => $organization->name,
                'fields' => implode(', ', $missing),
            ]);
        }

        return null;
    }
}
