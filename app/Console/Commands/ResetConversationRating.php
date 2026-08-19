<?php

namespace App\Console\Commands;

use App\Helpers\MessagingWindowHelper;
use App\Models\Contact;
use App\Models\ConversationRating;
use App\Services\ConversationRatingService;
use Illuminate\Console\Command;

/**
 * تصفير تقييمات عميل بعينه ليُطلب منه التقييم من جديد.
 *
 * الحاجة تظهر في التجربة: shouldAskAgain يمنع الطلب الثاني ما دام هناك صفّ
 * تقييم داخل مدّة التهدئة، وحذفه من الواجهة لا يكفي — الفحص يستخدم withTrashed
 * عمداً كي لا يُستدرّ تقييم جديد بحذف القديم. فالتصفير الحقيقي يحتاج forceDelete
 * وهو ما يفعله هذا الأمر.
 */
class ResetConversationRating extends Command
{
    protected $signature = 'ratings:reset
                            {target : رقم الهاتف أو معرّف جهة الاتصال}
                            {--org= : حصر البحث بمنشأة بعينها}
                            {--send : أرسل طلب التقييم فوراً بعد التصفير}';

    protected $description = 'Clear a contact\'s rating records so a new rating request can be sent';

    public function handle(): int
    {
        $contacts = $this->resolveContacts();

        if ($contacts->isEmpty()) {
            $this->error('لم يُعثر على جهة اتصال مطابقة.');

            return self::FAILURE;
        }

        foreach ($contacts as $contact) {
            $this->line('');
            $this->info("جهة الاتصال #{$contact->id} — {$contact->phone} — منشأة {$contact->organization_id}");

            $removed = ConversationRating::withTrashed()
                ->where('contact_id', $contact->id)
                ->forceDelete();

            $this->line("   صفوف تقييم مُزالة: {$removed}");

            $settings = ConversationRatingService::settings((int) $contact->organization_id);
            $windowOpen = MessagingWindowHelper::isMessagingWindowOpen($contact);

            $this->line('   الاستبيان مفعّل : ' . ($settings['active'] ? 'نعم' : 'لا — فعّله من الإعدادات ← إعدادات التذاكر'));
            $this->line('   نافذة 24 ساعة  : ' . ($windowOpen ? 'مفتوحة' : 'مغلقة — اطلب منه إرسال رسالة أولاً'));

            if (!$this->option('send')) {
                continue;
            }

            $rating = ConversationRatingService::onConversationClosed($contact);

            if ($rating) {
                $this->line('   ✅ أُرسل: ' . ConversationRatingService::linkFor($rating));
            } else {
                $this->line('   ❌ لم يُرسل — السبب في السطرين أعلاه أو في storage/logs');
            }
        }

        return self::SUCCESS;
    }

    /**
     * الهدف رقمٌ أو معرّف. الأرقام تُطابَق بصيغها المتعدّدة لأن ما يُخزَّن قد
     * يكون E.164 وما يكتبه المستخدم غالباً بصيغة محلية.
     */
    private function resolveContacts()
    {
        $target = trim((string) $this->argument('target'));

        $query = Contact::query();

        if ($org = $this->option('org')) {
            $query->where('organization_id', (int) $org);
        }

        if (ctype_digit($target) && !str_starts_with($target, '0') && strlen($target) <= 9) {
            return $query->where('id', (int) $target)->get();
        }

        $digits = preg_replace('/\D+/', '', $target);
        $suffix = substr($digits, -9);

        return $query->where(function ($q) use ($target, $digits, $suffix) {
            $q->where('phone', $target)
              ->orWhere('phone', '+' . $digits)
              ->orWhere('phone', 'like', '%' . $suffix);
        })->get();
    }
}
