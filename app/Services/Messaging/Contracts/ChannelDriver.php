<?php

namespace App\Services\Messaging\Contracts;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Services\Messaging\ChannelCapabilities;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\OutboundMedia;
use App\Services\Messaging\SendResult;
use Illuminate\Http\Request;

/**
 * العقد الذي تلتزم به كل منصّة.
 *
 * قاعدة التصميم: لا يعرف ما فوق هذه الطبقة اسم أي منصّة. من يريد إرسال
 * رسالة يطلب سائق جهة الاتصال ويستدعي sendText — ولا يسأل عن واتساب ولا
 * عن تيليجرام. وكل ما لا تدعمه منصّة يُعلَن في capabilities() لا يُكتشَف
 * بخطأ من المنصّة بعد الإرسال.
 */
interface ChannelDriver
{
    /** اسم القناة كما في App\Services\Messaging\Channel. */
    public function channel(): string;

    /** ما تستطيعه هذه القناة — تُستشار قبل كل عملية قد لا تدعمها. */
    public function capabilities(): ChannelCapabilities;

    /**
     * هل لجهة الاتصال عنوان يصلح للإرسال على هذه القناة؟
     * (واتساب: رقم هاتف. غيرها: channel_identifier.)
     */
    public function canAddress(Contact $contact): bool;

    /* ───────────────────────── الوارد ───────────────────────── */

    /**
     * التحقّق من أن الطلب قادم فعلاً من المنصّة لا من مُنتحِل.
     * توقيع HMAC أو secret token حسب المنصّة.
     */
    public function verifyWebhook(Request $request, ?ChannelAccount $account = null): bool;

    /**
     * ردّ التحقّق الأولي (handshake) الذي تطلبه بعض المنصّات عند الربط،
     * أو null إن كانت المنصّة لا تحتاجه.
     */
    public function webhookChallenge(Request $request, ?ChannelAccount $account = null): ?string;

    /**
     * استخراج معرّف الحساب المستقبِل من الحمولة، ليُعرَف أي منشأة تخصّها
     * الرسالة قبل أي عمل آخر. null يعني حمولة لا تخصّنا.
     */
    public function resolveAccountExternalId(Request $request): ?string;

    /**
     * تحويل حمولة الـ webhook إلى أحداث مطبَّعة.
     *
     * @return array<int, \App\Services\Messaging\InboundMessage|\App\Services\Messaging\InboundStatus>
     */
    public function parseWebhook(Request $request, ChannelAccount $account): array;

    /** تنزيل ملف وارد وإرجاع محتواه الخام مع نوعه. */
    public function downloadMedia(ChannelAccount $account, InboundMedia $media): ?DownloadedMedia;

    /**
     * جلب اسم العميل وصورته حين لا تأتي مع الرسالة (ميتا لا تُرسلها).
     * تُستدعى مرّة واحدة عند إنشاء جهة الاتصال.
     *
     * @return array{name?: string, username?: string, avatar?: string}|null
     */
    public function fetchProfile(ChannelAccount $account, string $externalId): ?array;

    /* ───────────────────────── الصادر ───────────────────────── */

    public function sendText(
        ChannelAccount $account,
        Contact $contact,
        string $body,
        array $options = []
    ): SendResult;

    public function sendMedia(
        ChannelAccount $account,
        Contact $contact,
        OutboundMedia $media,
        array $options = []
    ): SendResult;

    /**
     * إرسال موقع جغرافي.
     *
     * تُستدعى فقط حين تُعلن capabilities()->location أنها مدعومة، لكن كل
     * سائق يُنفّذها على أي حال ويردّ خطأً مفهوماً — فالعقد لا يعتمد على
     * انضباط المُستدعي.
     *
     * @param  array{latitude: mixed, longitude: mixed, name?: ?string, address?: ?string}  $location
     */
    public function sendLocation(
        ChannelAccount $account,
        Contact $contact,
        array $location,
        array $options = []
    ): SendResult;

    /* ───────────────────────── الحساب ───────────────────────── */

    /**
     * التحقّق من صحّة بيانات الربط عند حفظها، وإرجاع ما تعرفه المنصّة عن
     * الحساب (الاسم، المعرّف، الصورة) لعرضه في الإعدادات.
     *
     * يرمي ChannelConfigurationException إذا كانت البيانات خاطئة.
     *
     * @return array{external_id: string, name?: string, username?: string, avatar?: string}
     */
    public function verifyCredentials(array $credentials): array;

    /**
     * تسجيل عنوان الـ webhook لدى المنصّة بعد الربط.
     * بعض المنصّات تحتاجه (تيليجرام)، وبعضها يُضبط يدوياً في لوحتها.
     */
    public function registerWebhook(ChannelAccount $account): bool;

    /** إلغاء التسجيل عند فصل الحساب. */
    public function unregisterWebhook(ChannelAccount $account): bool;
}
