<?php

namespace App\Services\WebChat;

use App\Models\ChannelAccount;
use App\Models\WebChatFaq;
use App\Models\WebChatVisitor;
use App\Services\Messaging\ContactResolver;
use App\Support\JsonText;
use Illuminate\Support\Facades\Log;

/**
 * زائرٌ أنهى أسئلة البداية يصير جهة اتصال فوراً، ولو لم يكتب رسالة
 * (قرأ الأسئلة الشائعة ومضى): اسمه وبريده وجواله وبقية إجاباته عند التاجر.
 *
 * بلا محادثة ولا تذكرة: صندوق الوارد يعرض من له محادثات (latest_chat_created_at
 * يملؤه Chat::created وحده)، فلا يمتلئ بزوّار لم يكتبوا.
 */
final class WebChatLeads
{
    /** خيارا القائمة الأولى في الـ widget: «عندي سؤال» و«عندي مشكلة». */
    public const MENU_CHOICES = ['question', 'problem'];

    public function __construct(private readonly ContactResolver $contacts)
    {
    }

    /**
     * @param array<string, string> $fieldAnswers اسم الحقل المخصّص ⇐ الإجابة
     */
    public function capture(ChannelAccount $account, WebChatVisitor $visitor, array $fieldAnswers): void
    {
        try {
            // هويّة الزائر نفسها التي تجدها أوّل رسالة منه (uuid الزائر)،
            // فتلحق الرسالة بهذه الجهة لا بجهة ثانية.
            [$contact] = $this->contacts->resolveProfile(
                $account,
                (string) $visitor->uuid,
                $visitor->name,
                $visitor->phone,
                $visitor->email
            );

            if ($contact && $fieldAnswers !== []) {
                // باسم الحقل كما تحفظه صفحة جهة الاتصال (ContactService).
                $metadata = json_decode((string) $contact->metadata, true);
                $metadata = is_array($metadata) ? $metadata : [];

                $contact->update(['metadata' => JsonText::encode(array_replace($metadata, $fieldAnswers))]);
            }
        } catch (\Throwable $e) {
            // الجلسة لا تفشل بسببه: أوّل رسالة تُنشئ الجهة كما كانت تفعل.
            Log::warning('Web chat lead capture failed', [
                'organization_id' => $account->organization_id,
                'visitor_id' => $visitor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ما فعله الزائر قبل أن يكتب: اختياره من القائمة، والأسئلة الشائعة التي
     * قرأها، وهل لم يجد جوابه. يُحفظ مع أوّل رسالة ولا يتغيّر بعدها.
     *
     * نصّ السؤال من عندنا لا من المتصفّح، ومن أسئلة هذه المنشأة وحدها: الموظّف
     * يقرأ ما كتبه التاجر، ولو عُدّل السؤال أو حُذف بعدها.
     *
     * @param array{menu?: ?string, faqs?: ?array<int, int|string>, not_found?: mixed}|null $context
     */
    public function recordJourney(WebChatVisitor $visitor, ?array $context): void
    {
        if ($context === null || $visitor->journey !== null) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($context['faqs'] ?? []))));
        $questions = $ids === [] ? collect() : WebChatFaq::query()
            ->where('organization_id', $visitor->organization_id)
            ->whereIn('id', $ids)
            ->pluck('question_ar', 'id');

        $viewed = [];
        foreach ($ids as $id) {
            if ($questions->has($id)) {
                $viewed[] = ['id' => $id, 'question' => (string) $questions[$id]];
            }
        }

        $journey = [
            'menu' => in_array($context['menu'] ?? null, self::MENU_CHOICES, true) ? $context['menu'] : null,
            'faqs_viewed' => $viewed,
            'faq_not_found' => filter_var($context['not_found'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        // أوّل كتابة تفوز: رسالتان متزامنتان لا تكتب إحداهما فوق الأخرى.
        $written = WebChatVisitor::query()
            ->whereKey($visitor->id)
            ->whereNull('journey')
            ->update(['journey' => JsonText::encode($journey)]);

        if ($written) {
            $visitor->setRawAttributes(['journey' => JsonText::encode($journey)] + $visitor->getAttributes(), true);
        }
    }
}
