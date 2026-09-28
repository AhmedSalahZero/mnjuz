<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatStatusLog;
use App\Models\Contact;
use App\Support\JsonText;
use Illuminate\Support\Facades\DB;

/**
 * تطبيق تحديث حالة على الرسائل الصادرة.
 *
 * السلّم أحادي الاتجاه كما في مسار واتساب: الرسالة المقروءة لا تعود
 * «مُسلَّمة» لأن حدثاً قديماً وصل متأخّراً. FIELD() في MySQL تُعطي رتبة كل
 * حالة، والشرط يمنع النزول.
 *
 * منصّات ميتا تُرسل «علامة مائية» (watermark) بدل معرّفات: كل ما أُرسل قبل
 * هذا الوقت صار مقروءاً. نُحدّث عندها بالدفعة لا بالمعرّف.
 */
class StatusRecorder
{
    /** ترتيب الحالات — الأعلى لا يُستبدل بالأدنى. */
    private const LADDER = ['failed', 'accepted', 'sent', 'delivered', 'read'];

    public function record(ChannelAccount $account, InboundStatus $status): int
    {
        $rank = array_search($status->status, self::LADDER, true);

        if ($rank === false) {
            return 0;
        }

        return $status->isWatermark()
            ? $this->applyWatermark($account, $status)
            : $this->applyToIds($account, $status);
    }

    private function applyToIds(ChannelAccount $account, InboundStatus $status): int
    {
        $ids = array_filter($status->providerMessageIds);

        if (!$ids) {
            return 0;
        }

        $chats = Chat::query()
            ->where('organization_id', $account->organization_id)
            ->whereIn('wam_id', $ids)
            ->get(['id', 'status']);

        $updated = 0;

        foreach ($chats as $chat) {
            if ($this->advance($chat, $status)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * كل رسالة صادرة إلى هذه المحادثة أُرسلت قبل العلامة المائية.
     *
     * الحدّ بخمسمئة صفّ حماية من محادثة طويلة جداً: التحديث يجري داخل
     * طابور، وصفّ واحد ضخم يحجز الاتصال دقائق. الأحدث هو ما يراه الموظّف.
     */
    private function applyWatermark(ChannelAccount $account, InboundStatus $status): int
    {
        if (!$status->senderId || !$status->watermark) {
            return 0;
        }

        $contact = Contact::query()
            ->where('organization_id', $account->organization_id)
            ->where('channel', $account->channel)
            ->where('channel_identifier', $status->senderId)
            ->first();

        if (!$contact) {
            return 0;
        }

        $rank = array_search($status->status, self::LADDER, true);

        $chats = Chat::query()
            ->where('contact_id', $contact->id)
            ->where('type', 'outbound')
            ->whereNull('deleted_at')
            ->where('created_at', '<=', $status->watermark->format('Y-m-d H:i:s'))
            ->whereRaw('FIELD(COALESCE(status, ?), ?, ?, ?, ?, ?) < ?', array_merge(
                ['sent'],
                self::LADDER,
                [$rank + 1]
            ))
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'status']);

        $updated = 0;

        foreach ($chats as $chat) {
            if ($this->advance($chat, $status)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * رفع حالة صفّ واحد إن كانت الحالة الجديدة أعلى.
     * التحديث الشرطي في جملة SQL واحدة يمنع سباق حدثين متزامنين.
     */
    private function advance(Chat $chat, InboundStatus $status): bool
    {
        $rank = array_search($status->status, self::LADDER, true);

        $affected = DB::table('chats')
            ->where('id', $chat->id)
            ->whereRaw('FIELD(COALESCE(status, ?), ?, ?, ?, ?, ?) < ?', array_merge(
                ['sent'],
                self::LADDER,
                [$rank + 1]
            ))
            ->update(['status' => $status->status]);

        // السجلّ يُكتب دائماً — حتى لو لم تتقدّم الحالة — فهو أثر التشخيص.
        ChatStatusLog::create([
            'chat_id' => $chat->id,
            'metadata' => JsonText::encode(array_filter([
                'id' => $chat->wam_id ?? null,
                'status' => $status->status,
                'errors' => $status->errorMessage
                    ? [['message' => $status->errorMessage, 'code' => $status->errorCode]]
                    : null,
            ], static fn ($v) => $v !== null)),
            'created_at' => now(),
        ]);

        return $affected > 0;
    }
}
