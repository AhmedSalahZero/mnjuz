<?php

namespace App\Console\Commands;

use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * لماذا لم تظهر رسالة العميل كغير مقروءة؟
 *
 * الشكوى «العميل أرسل ولم يظهر شيء» تحتمل ثلاثة أعطال مختلفة تماماً، ولا
 * يفرّق بينها إلا الأثر في القاعدة:
 *
 *   ١. الرسالة غير محفوظة أصلاً ⇒ عطل في الويب هوك، لا علاقة له بالواجهة.
 *   ٢. محفوظة و is_read = 1 ⇒ شيءٌ علّمها مقروءة. الردّ الآلي يفعل هذا عمداً
 *      (AutoReplyService) — فتصل الرسالة ولا يظهر لها عدّاد أبداً.
 *   ٣. محفوظة و is_read = 0 ⇒ وصلت وبقيت غير مقروءة، فالخلل في التوصيل
 *      اللحظي إلى المتصفّح لا في الحفظ.
 *
 * الأمر للقراءة فقط: لا يكتب حرفاً، فيصحّ تشغيله على الإنتاج.
 */
class DiagnoseUnreadChats extends Command
{
    protected $signature = 'chat:diagnose-unread
        {contact : رقم الهاتف أو uuid جهة الاتصال}
        {--organization= : رقم المنظّمة عند تكرار الرقم بين منظّمات}
        {--hours=48 : المدى الزمني بالساعات}';

    protected $description = 'تشخيص شكوى «رسالة العميل لم تظهر» بالأدلّة من قاعدة البيانات';

    /** فارق زمني نعتبر ضمنه أن الردّ الصادر جاء آلياً لا بشرياً. */
    private const AUTO_REPLY_WINDOW_SECONDS = 20;

    public function handle(): int
    {
        $contact = $this->findContact();

        if (!$contact) {
            $this->error('لم يُعثر على جهة اتصال بهذا الرقم أو المعرّف.');

            return self::FAILURE;
        }

        $since = now()->subHours(max(1, (int) $this->option('hours')));

        $this->header($contact, $since);

        $messages = Chat::where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            // organization_id مطلوب: مُحوِّل created_at في النموذج يقرؤه
            // لتحويل التوقيت، وإسقاطه من الأعمدة يُسقط الاستعلام كلّه.
            ->get(['id', 'organization_id', 'type', 'is_read', 'created_at', 'user_id', 'metadata']);

        if ($messages->isEmpty()) {
            $this->warn('  لا رسائل في هذا المدى — وسّع --hours أو تأكّد من الرقم.');

            return self::SUCCESS;
        }

        $this->timeline($messages);
        $this->verdict($contact, $messages);

        return self::SUCCESS;
    }

    // -------------------------------------------------------- العرض

    private function header(Contact $contact, Carbon $since): void
    {
        $autoReplies = AutoReply::where('organization_id', $contact->organization_id)->count();

        $this->newLine();
        $this->line('  العميل      : ' . trim((string) $contact->full_name) . ' ' . $contact->phone);
        $this->line('  المنظّمة    : #' . $contact->organization_id);
        $this->line('  المدى       : منذ ' . $since->toDateTimeString());
        $this->line('  ردود آليّة  : ' . ($autoReplies > 0
            ? "<options=bold>{$autoReplies}</> — تعليم الرسائل مقروءة وارد"
            : 'لا يوجد'));
        $this->newLine();
    }

    /** @param \Illuminate\Support\Collection<int, Chat> $messages */
    private function timeline($messages): void
    {
        $rows = [];

        foreach ($messages as $message) {
            $inbound = $message->type === 'inbound';

            $rows[] = [
                $message->created_at,
                $inbound ? '← وارد' : '→ صادر',
                $this->preview($message->metadata),
                $inbound
                    ? ($message->is_read ? '✅ مقروءة' : '🔵 غير مقروءة')
                    : ($message->user_id ? 'موظّف #' . $message->user_id : 'آلي / بلا موظّف'),
                $inbound ? $this->explainRead($message, $messages) : '',
            ];
        }

        $this->table(['الوقت', 'الاتجاه', 'المحتوى', 'الحالة', 'لماذا؟'], $rows);
    }

    /**
     * سبب كون الرسالة مقروءة، حين يمكن استنتاجه.
     *
     * @param  \Illuminate\Support\Collection<int, Chat>  $messages
     */
    private function explainRead(Chat $message, $messages): string
    {
        if (!$message->is_read) {
            return '';
        }

        // ردّ صادر بلا موظّف بعد ثوانٍ = ردّ آليّ، وهو يعلّم الوارد كلّه مقروءاً.
        // created_at يعود نصّاً بعد التحويل إلى توقيت المنظّمة، فنُحلّله للمقارنة.
        $sentAt = Carbon::parse($message->created_at);

        $auto = $messages->first(function (Chat $other) use ($sentAt) {
            if ($other->type !== 'outbound' || $other->user_id !== null) {
                return false;
            }

            $repliedAt = Carbon::parse($other->created_at);

            return $repliedAt->greaterThanOrEqualTo($sentAt)
                && $repliedAt->diffInSeconds($sentAt) <= self::AUTO_REPLY_WINDOW_SECONDS;
        });

        if ($auto) {
            return 'ردّ آليّ #' . $auto->id . ' علّمها مقروءة';
        }

        return 'فتحها موظّف أو علّمها التطبيق';
    }

    private function preview(?string $metadata): string
    {
        $decoded = json_decode((string) $metadata, true);

        if (!is_array($decoded)) {
            return '—';
        }

        $body = $decoded['text']['body'] ?? ('[' . ($decoded['type'] ?? '?') . ']');

        return mb_substr((string) $body, 0, 32);
    }

    // -------------------------------------------------------- الحكم

    /** @param \Illuminate\Support\Collection<int, Chat> $messages */
    private function verdict(Contact $contact, $messages): void
    {
        $inbound = $messages->where('type', 'inbound');
        $readByAuto = $inbound->filter(fn (Chat $m) => $m->is_read && str_contains($this->explainRead($m, $messages), 'آليّ'));
        $stillUnread = $inbound->where('is_read', 0);

        $this->newLine();
        $this->line('  <options=bold>الخلاصة</>');
        $this->line('  رسائل واردة: ' . $inbound->count()
            . ' | غير مقروءة الآن: ' . $stillUnread->count()
            . ' | علّمها ردٌّ آليّ: ' . $readByAuto->count());
        $this->newLine();

        if ($readByAuto->isNotEmpty()) {
            $this->warn('  ⇒ الشكوى صحيحة، والسبب الردّ الآليّ: يعلّم رسائل العميل مقروءة فور ردّه،');
            $this->line('     فلا يظهر لها عدّاد ولا تنبيه رغم وصولها. سلوكٌ مقصود في');
            $this->line('     AutoReplyService، وقابل للمراجعة إن لم يكن مرغوباً.');
            $this->newLine();
        }

        if ($stillUnread->isNotEmpty()) {
            $this->line('  ⇒ رسائل وصلت وبقيت غير مقروءة. إن قال الموظّف إنها لم تظهر لحظياً');
            $this->line('     فالخلل في التوصيل إلى المتصفّح لا في الحفظ: راجع اتصال البثّ');
            $this->line('     (php artisan broadcast:provider) وConsole المتصفّح.');
            $this->newLine();
        }

        if ($inbound->isEmpty()) {
            $this->error('  ⇒ لا رسائل واردة محفوظة في هذا المدى — الخلل قبل الحفظ (الويب هوك).');
            $this->newLine();
        }
    }

    // ------------------------------------------------------ مساعدات

    private function findContact(): ?Contact
    {
        $needle = trim((string) $this->argument('contact'));

        $query = Contact::query()->whereNull('deleted_at');

        if ($this->option('organization')) {
            $query->where('organization_id', (int) $this->option('organization'));
        }

        if (preg_match('/^[0-9a-f-]{36}$/i', $needle)) {
            return $query->where('uuid', $needle)->first();
        }

        $digits = preg_replace('/\D+/', '', $needle);

        return $digits === ''
            ? null
            : $query->where('phone', 'like', '%' . $digits . '%')->latest('id')->first();
    }
}
