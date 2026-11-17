<?php

namespace App\Services\Email;

use App\Http\Controllers\EmailUnsubscribeController;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\ContactPlaceholderService;
use Illuminate\Support\Facades\App;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * رسالة حملة البريد لمستلم واحد.
 *
 *  - المتغيّرات ({first_name}…) تُستبدل أوّلاً، ثم يُهرَّب النصّ كلّه:
 *    نصّ التاجر وحقول جهات الاتصال المستوردة لا تصير HTML أبداً. ولا
 *    تحويل للروابط إلى <a>: رابطٌ في اسم جهة اتصال كان سيصير رابطاً قابلاً
 *    للنقر من دومين التاجر الحقيقي.
 *  - الموضوع واسم المرسل بلا محارف تحكّم بعد الاستبدال (لا حقن ترويسات).
 *  - نسخة نصّية مع HTML، ورابط إلغاء الاشتراك في التذييل وفي الترويسة.
 */
class CampaignEmailBuilder
{
    public function build(Campaign $campaign, Contact $contact, ChannelAccount $account, string $to): Email
    {
        $locale = (string) ($campaign->payloadValue('locale') ?: config('app.locale'));
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            $organization = Organization::find($campaign->organization_id);
            $payload = $campaign->messagePayload();
            $design = self::designOf($campaign);
            $resolve = $this->resolver($campaign, $contact, $design !== null);

            $subject = self::singleLine($resolve((string) $campaign->payloadValue('subject', '')), 255);
            $text = $resolve((string) $payload['text']);
            $fromName = self::singleLine((string) $account->name, 100);
            $unsubscribeUrl = EmailUnsubscribeController::urlFor($organization, $to, $locale);

            $html = $design !== null
                ? app(EmailDesignRenderer::class)->render($design, $resolve, [
                    'locale' => $locale,
                    'subject' => $subject,
                    'fromName' => $fromName,
                    'unsubscribeUrl' => $unsubscribeUrl,
                ])
                : view('emails.campaign', [
                    'locale' => $locale,
                    'subject' => $subject,
                    'bodyHtml' => self::bodyHtml($text),
                    'fromName' => $fromName,
                    'unsubscribeUrl' => $unsubscribeUrl,
                ])->render();

            $plain = $text . "\n\n—\n" . __('You received this email from :name.', ['name' => $fromName])
                . "\n" . __('Unsubscribe') . ': ' . $unsubscribeUrl;

            $email = (new Email())
                ->from(new Address((string) $account->username, $fromName))
                ->to($to)
                ->subject($subject)
                ->text($plain)
                ->html($html);

            if ($replyTo = $account->setting('reply_to')) {
                $email->replyTo((string) $replyTo);
            }

            // RFC 8058: زرّ «إلغاء الاشتراك» عند Gmail وYahoo — شرطهما للمرسلين بكثرة.
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            return $email;
        } finally {
            App::setLocale($previous);
        }
    }

    /** نصّ مهرَّب بفقراته — بلا HTML من التاجر ولا روابط مُولَّدة. */
    public static function bodyHtml(string $text): string
    {
        return nl2br(e($text), false);
    }

    /** سطر واحد بلا محارف تحكّم، بطول محدود. */
    public static function singleLine(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return mb_substr(trim(preg_replace('/\s{2,}/u', ' ', $value) ?? ''), 0, $max);
    }

    /** تصميم الـ builder المنسوخ في الحملة، أو null لحملة نصّية (القديمة والجوال). */
    public static function designOf(Campaign $campaign): ?array
    {
        $design = $campaign->payloadValue('design');

        return is_array($design) && !empty($design['blocks']) ? $design : null;
    }

    /**
     * استبدال المتغيّرات لهذا المستلم. الحملة المصمَّمة فيها نصوص كثيرة
     * (عنصر لكل نصّ وزرّ ورابط)، فتُجلب القيم مرّة واحدة؛ والنصّية تبقى
     * على مسارها كما كان.
     *
     * @return \Closure(string): string
     */
    private function resolver(Campaign $campaign, Contact $contact, bool $designed): \Closure
    {
        if (!$designed) {
            return fn (string $text): string => $this->placeholders($campaign, $contact, $text);
        }

        $values = ContactPlaceholderService::valuesFor((int) $campaign->organization_id, (string) $contact->uuid);

        return static fn (string $text): string => $values === null || $text === '' || !str_contains($text, '{')
            ? $text
            : ContactPlaceholderService::replaceWith($values, $text);
    }

    private function placeholders(Campaign $campaign, Contact $contact, string $text): string
    {
        if ($text === '' || !str_contains($text, '{')) {
            return $text;
        }

        return ContactPlaceholderService::replace((int) $campaign->organization_id, (string) $contact->uuid, $text);
    }
}
