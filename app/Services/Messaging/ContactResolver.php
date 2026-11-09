<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;
use App\Models\Contact;
use Illuminate\Support\Facades\Log;

/**
 * إيجاد أو إنشاء جهة الاتصال المُرسِلة على قناة غير واتساب.
 *
 * قرار مُتَّخذ من صاحب المنتج: من يراسلنا من واتساب ومن تيليجرام هما
 * **جهتا اتصال منفصلتان** لا شخص واحد. لذلك المفتاح هو
 * (organization_id, channel, channel_identifier) ولا يوجد أي ربط بينهما.
 * هذا يُبسّط التنفيذ كثيراً ويجعل السلوك متوقَّعاً للموظّف.
 *
 * phone يبقى NULL لهذه الجهات — والفهرس الفريد القائم
 * (organization_id, phone_active_key) يسمح بذلك لأن MySQL يقبل تكرار NULL
 * في الفهارس الفريدة. أي أننا لا نلمس الفهرس القائم إطلاقاً.
 */
class ContactResolver
{
    /**
     * @return array{0: ?Contact, 1: bool}  [جهة الاتصال، هل هي جديدة]
     */
    public function resolve(ChannelAccount $account, InboundMessage $message): array
    {
        $externalId = trim($message->senderId);

        if ($externalId === '') {
            Log::warning('Inbound message has no sender id, skipping', [
                'channel' => $account->channel,
                'account_id' => $account->id,
                'provider_message_id' => $message->providerMessageId,
            ]);

            return [null, false];
        }

        $existing = Contact::query()
            ->where('organization_id', $account->organization_id)
            ->where('channel', $account->channel)
            ->where('channel_identifier', $externalId)
            ->first();

        if ($existing) {
            $this->fillMissingProfile($existing, $message);

            return [$existing, false];
        }

        return $this->create($account, $message, $externalId);
    }

    /**
     * @return array{0: ?Contact, 1: bool}
     */
    private function create(ChannelAccount $account, InboundMessage $message, string $externalId): array
    {
        [$firstName, $lastName] = $this->splitName($message->senderName);

        $attributes = [
            'organization_id' => $account->organization_id,
            'channel' => $account->channel,
            'channel_identifier' => $externalId,
            'channel_account_id' => $account->id,
            // phone لا يُذكر أصلاً: ذكره ولو null يجعله «متغيّراً» فيمسح
            // ContactObserver::saving رقم العرض (formatted_phone) أدناه.
            // والعمود فارغ افتراضياً، فالنتيجة phone = NULL كما كانت.
            'formatted_phone' => $this->displayPhone($message),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $this->email($message),
            'avatar' => $message->senderAvatar,
            'metadata' => $this->buildMetadata($message),
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            return [Contact::create($attributes), true];
        } catch (\Illuminate\Database\QueryException $e) {
            // سباق بين وظيفتين تعالجان رسالتين من نفس المُرسِل في اللحظة
            // ذاتها — الفهرس الفريد يحسمه ونجلب ما أنشأته الأخرى.
            if (($e->errorInfo[1] ?? null) === 1062) {
                $contact = Contact::query()
                    ->where('organization_id', $account->organization_id)
                    ->where('channel', $account->channel)
                    ->where('channel_identifier', $externalId)
                    ->first();

                return [$contact, false];
            }

            throw $e;
        }
    }

    /**
     * ملء الاسم أو الصورة إن كانا غائبين وأتت المنصّة بهما لاحقاً.
     * لا نكتب فوق اسم موجود: قد يكون الموظّف عدّله يدوياً.
     */
    private function fillMissingProfile(Contact $contact, InboundMessage $message): void
    {
        $updates = [];

        if (trim((string) $contact->first_name) === '' && trim((string) $message->senderName) !== '') {
            [$firstName, $lastName] = $this->splitName($message->senderName);
            $updates['first_name'] = $firstName;

            if ($lastName !== null && trim((string) $contact->last_name) === '') {
                $updates['last_name'] = $lastName;
            }
        }

        if (empty($contact->avatar) && !empty($message->senderAvatar)) {
            $updates['avatar'] = $message->senderAvatar;
        }

        if (empty($contact->formatted_phone) && $this->displayPhone($message) !== null) {
            $updates['formatted_phone'] = $this->displayPhone($message);
        }

        if (empty($contact->email) && $this->email($message) !== null) {
            $updates['email'] = $this->email($message);
        }

        if ($updates) {
            $contact->update($updates);
        }
    }

    /**
     * اسم واحد يصل من أغلب المنصّات. نقسمه على أول مسافة فقط: الاسم
     * الأخير قد يكون مركّباً، والقسمة على كل مسافة تُنتج حقولاً مشوّهة.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return [null, null];
        }

        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * رقم للعرض والبحث فقط (formatted_phone بلا فهرس فريد)، بنفس صيغة
     * عرض أرقام واتساب ليبدو واحداً في القائمة والترويسة والتطبيق.
     */
    private function displayPhone(InboundMessage $message): ?string
    {
        $phone = trim((string) $message->senderPhone);

        if ($phone === '') {
            return null;
        }

        $display = \App\Services\PhoneService::formatForDisplay($phone) ?: $phone;

        return mb_substr((string) $display, 0, 64);
    }

    private function email(InboundMessage $message): ?string
    {
        $email = trim((string) $message->senderEmail);

        return $email === '' ? null : mb_substr($email, 0, 255);
    }

    /** ما تعرفه المنصّة عن العميل ولا مكان له في أعمدة الجدول. */
    private function buildMetadata(InboundMessage $message): ?string
    {
        $data = array_filter([
            'channel_username' => $message->senderUsername,
            'channel_display_name' => $message->senderName,
        ], static fn ($v) => $v !== null && $v !== '');

        return $data ? \App\Support\JsonText::encode($data) : null;
    }

    /**
     * جهة اتصال بمعرّف خارجي — يستعملها الإرسال الابتدائي (حملة مثلاً)
     * حين نعرف المعرّف ولم تصلنا منه رسالة بعد.
     */
    public function findByExternalId(int $organizationId, string $channel, string $externalId): ?Contact
    {
        return Contact::query()
            ->where('organization_id', $organizationId)
            ->where('channel', $channel)
            ->where('channel_identifier', $externalId)
            ->first();
    }
}
