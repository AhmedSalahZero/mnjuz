<?php

namespace App\Services\Messaging;

use App\Models\Contact;

/**
 * القنوات التي تعمل عليها الأتمتة (الردود الآلية، والـ flows، والمساعد الذكي).
 *
 * سؤالان منفصلان:
 *
 *  ١. هل يصل الردّ الآلي هذه القناة أصلاً؟ (allowsChannel) واتساب دائماً، وغيرها
 *     بحسب config/messaging.php. يُسأل قبل أن يبدأ أي شيء: قبل إطلاق مهمّة الردّ
 *     الآلي، وقبل أن يدفع المساعد الذكي لـ OpenAI ثمن ردٍّ لن يُرسَل.
 *  ٢. هل يخصّ هذا الردُّ أو الـ flow هذه القناة؟ (covers) التاجر يختار لكلٍّ منها
 *     قنواته: ترحيب الموقع غير ترحيب واتساب.
 */
final class AutomationChannels
{
    /**
     * القنوات التي كانت الأتمتة تعمل عليها قبل اختيار القنوات لكل ردّ: واتساب
     * والمحادثة المباشرة (القيمة الافتراضية السابقة لـ MESSAGING_AUTOMATION_CHANNELS).
     * بها تُعبّأ الردود والـ flows القائمة فلا يتغيّر ما يعمل اليوم، ولا يبدأ
     * ردٌّ كُتب لواتساب يجيب على تيليجرام من تلقاء نفسه.
     */
    public const LEGACY = [Channel::WHATSAPP, Channel::WEBCHAT];

    public static function allows(?Contact $contact): bool
    {
        return self::allowsChannel($contact?->channel);
    }

    public static function allowsChannel(?string $channel): bool
    {
        $channel = $channel ?: Channel::DEFAULT;

        if ($channel === Channel::WHATSAPP) {
            return true;
        }

        return in_array($channel, (array) config('messaging.automation_channels', [Channel::WEBCHAT]), true);
    }

    /**
     * المساعد الذكي ورسالة خارج أوقات العمل: لا اختيار قنوات لهما بعد، فيبقيان
     * حيث كانا يعملان (LEGACY) — وإن فُتحت قناة للردود والـ flows. فتيليجرام
     * تصله ردود التاجر وflows-ه التي اختارها له، لا مساعدٌ يدفع لـ OpenAI ولا
     * رسالة ساعات عمل لم يكتبها التاجر لهذه القناة.
     */
    public static function assistantAllows(?string $channel): bool
    {
        $channel = $channel ?: Channel::DEFAULT;

        return in_array($channel, self::LEGACY, true) && self::allowsChannel($channel);
    }

    /**
     * القنوات التي يختار منها التاجر، بترتيب الواجهة.
     *
     * @return array<int, string>
     */
    public static function available(): array
    {
        return array_values(array_filter(Channel::all(), [self::class, 'allowsChannel']));
    }

    /**
     * قنوات ردٍّ يُنشأ بلا اختيار (واجهة قديمة مفتوحة قبل التحديث، أو نسخة
     * تطبيق لا تعرف القنوات): ما كانت الأتمتة تعمل عليه قبل الاختيار، كالردود
     * التي عبّأها الترحيل — فلا يبدأ ردٌّ جديد يجيب على تيليجرام دون أن يُطلب.
     *
     * @return array<int, string>
     */
    public static function defaults(): array
    {
        return array_values(array_intersect(self::available(), self::LEGACY));
    }

    /**
     * خيارات الواجهة: [{value, label}] بأسماء مترجمة.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (string $channel) => ['value' => $channel, 'label' => self::label($channel)],
            self::available()
        );
    }

    public static function label(string $channel): string
    {
        return __(Channel::label($channel));
    }

    /**
     * ما اختاره التاجر بعد تنقيته: قنوات معروفة بلا تكرار، بترتيب الواجهة.
     * قناةٌ أُطفئت أتمتتها تبقى إن كانت محفوظة، فتعود حين تُشغَّل.
     *
     * @return array<int, string>
     */
    public static function normalize($channels): array
    {
        $channels = array_map('strval', array_filter((array) $channels, 'is_scalar'));

        return array_values(array_intersect(Channel::all(), $channels));
    }

    /**
     * القنوات كما تُعرض: null (كل القنوات) تُعرض قائمةَ المتاح كلّه.
     *
     * @return array<int, string>
     */
    public static function effective($channels): array
    {
        if (is_string($channels)) {
            $channels = json_decode($channels, true);
        }

        return $channels === null ? self::available() : self::normalize($channels);
    }

    /**
     * هل يخصّ الردُّ هذه القناة؟ null = ردٌّ بلا اختيار (أُنشئ قبل الميزة من
     * مسارٍ لم يُعبّأ): يعمل على كل قناة كما كان.
     */
    public static function covers($channels, ?string $channel): bool
    {
        if ($channels === null) {
            return true;
        }

        if (is_string($channels)) {
            $channels = json_decode($channels, true);
        }

        return is_array($channels) && in_array($channel ?: Channel::DEFAULT, $channels, true);
    }
}
