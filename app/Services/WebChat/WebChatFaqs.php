<?php

namespace App\Services\WebChat;

use App\Models\Setting;
use App\Models\WebChatFaq;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * الأسئلة الشائعة: ما يصل الـ widget، وصورها، وكاش القراءة.
 *
 * الـ widget يطلبها مع كل زائر يختار «عندي سؤال»، فتُقرأ من كاشٍ يُمسح مع
 * كل تعديل من صفحة الإعدادات (forget). والمتصفّح يخبّئها دقيقة (كـ /config).
 */
final class WebChatFaqs
{
    public const MAX = 30;
    public const IMAGE_DIRECTORY = 'webchat-faqs';
    private const CACHE_TTL = 600;
    private const LOCAL_PATTERN = '#^public/webchat-faqs/\d+/[a-z0-9]+\.(png|jpe?g|webp)$#';

    /** @return array<int, array{id: int, question_ar: string, question_en: ?string, answer_ar: ?string, answer_en: ?string, image_url: ?string}> */
    public static function publicList(int $organizationId): array
    {
        return Cache::remember(self::cacheKey($organizationId), self::CACHE_TTL, static fn () => WebChatFaq::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (WebChatFaq $faq) => self::present($faq))
            ->values()
            ->all());
    }

    public static function count(int $organizationId): int
    {
        return count(self::publicList($organizationId));
    }

    public static function forget(int $organizationId): void
    {
        Cache::forget(self::cacheKey($organizationId));
    }

    /** لصفحة الإعدادات: كلها، المفعّلة وغيرها، بترتيبها. */
    public static function forDashboard(int $organizationId): array
    {
        return WebChatFaq::query()
            ->where('organization_id', $organizationId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (WebChatFaq $faq) => self::present($faq) + ['is_active' => $faq->is_active])
            ->values()
            ->all();
    }

    public static function present(WebChatFaq $faq): array
    {
        return [
            'id' => (int) $faq->id,
            'question_ar' => (string) $faq->question_ar,
            'question_en' => $faq->question_en,
            'answer_ar' => $faq->answer_ar,
            'answer_en' => $faq->answer_en,
            'image_url' => self::imageUrl($faq),
        ];
    }

    /** المسار المحلّي يُخدم من /media؛ ورابط S3 بـ https وحدها. */
    public static function imageUrl(WebChatFaq $faq): ?string
    {
        if ($faq->image_path && preg_match(self::LOCAL_PATTERN, $faq->image_path)) {
            return WebChatMessagePresenter::publicUrl($faq->image_path);
        }

        $url = trim((string) $faq->image_url);

        return $url !== '' && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    /**
     * صورة إجابة جديدة، في تخزين المنشأة كشعار الـ widget.
     *
     * @return array{image_path: ?string, image_url: ?string}
     */
    public static function storeImage(UploadedFile $file, int $organizationId): array
    {
        $name = strtolower((string) Str::ulid()) . '.' . $file->extension();

        if (Setting::where('key', 'storage_system')->value('value') === 'aws') {
            $path = $file->storeAs(self::IMAGE_DIRECTORY . '/' . $organizationId, $name, 's3');

            return ['image_path' => null, 'image_url' => $path ? Storage::disk('s3')->url($path) : null];
        }

        $path = $file->storeAs('public/' . self::IMAGE_DIRECTORY . '/' . $organizationId, $name, 'local');

        return ['image_path' => $path ?: null, 'image_url' => null];
    }

    /** الصورة المحلّية تُحذف مع سؤالها أو حين تُستبدل. */
    public static function deleteImage(WebChatFaq $faq): void
    {
        if ($faq->image_path && preg_match(self::LOCAL_PATTERN, $faq->image_path)) {
            Storage::disk('local')->delete($faq->image_path);
        }
    }

    private static function cacheKey(int $organizationId): string
    {
        return 'webchat_faqs:' . $organizationId;
    }
}
