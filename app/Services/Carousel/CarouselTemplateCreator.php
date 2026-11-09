<?php

namespace App\Services\Carousel;

use App\Models\Template;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إنشاء قالب carousel من صفحة القوالب.
 *
 * الترتيب مقصود: تحقّق كامل أولاً (بلا أي طلب إلى Meta)، ثم رفع وسائط
 * البطاقات، ثم إنشاء القالب، ثم — بعد قبول Meta فقط — حفظ نسخنا من
 * الوسائط وصفّ القالب. فلا تبقى ملفات يتيمة ولا قالب عند Meta بلا صفّ.
 */
class CarouselTemplateCreator
{
    public function __construct(
        private WhatsappService $whatsapp,
        private int $organizationId,
    ) {
    }

    public function create(Request $request): object
    {
        $input = CarouselTemplateValidator::normalize($request->all());
        $errors = app(CarouselTemplateValidator::class)->validate($input);

        if ($errors->isNotEmpty()) {
            return (object) [
                'success' => false,
                'message' => $errors->first(),
                'errors' => $errors->toArray(),
            ];
        }

        // عشر بطاقات تعني عشرين طلباً متتالياً إلى Meta قبل الإنشاء.
        @set_time_limit(300);

        $format = $input['carousel']['format'];
        $files = array_map(
            fn ($card) => $card['header']['example'],
            array_values($input['carousel']['cards'])
        );

        $handles = [];
        foreach ($files as $file) {
            $upload = $this->whatsapp->initiateResumableUploadSession($file, 60);

            if (!($upload->success ?? false) || empty($upload->data->h)) {
                $upload->success = false;
                $upload->message = $upload->message
                    ?? ($upload->data->error->message ?? null)
                    ?? __('Uploading the card media to WhatsApp failed. Please try again.');

                return $upload;
            }

            $handles[] = $upload->data->h;
        }

        $payload = CarouselPayloadBuilder::createPayload($input, $handles);
        $response = $this->whatsapp->postMessageTemplate($payload);

        if (!($response->success ?? false)) {
            return $response;
        }

        $template = new Template();
        $template->organization_id = $this->organizationId;
        $template->meta_id = $response->data->id ?? null;
        $template->name = $payload['name'];
        $template->category = 'MARKETING';
        $template->language = $payload['language'];
        $template->metadata = json_encode($payload);
        $template->carousel_media = json_encode([
            'format' => $format,
            'cards' => array_map(fn (UploadedFile $file) => $this->keepCopy($file, $format), $files),
        ]);
        $template->status = $response->data->status ?? 'PENDING';
        $template->created_by = auth()->id();
        $template->created_at = now();
        $template->updated_at = now();
        $template->save();

        return $response;
    }

    /**
     * نسختنا من وسائط البطاقة، لتكون الافتراضية عند الإرسال. فشلها لا يُسقط
     * قالباً قبلته Meta: البطاقة تُطلب عندها بالرفع وقت الإرسال.
     */
    private function keepCopy(UploadedFile $file, string $format): ?array
    {
        try {
            $media = app(CarouselMediaStorer::class)->store($file, $this->organizationId, auth()->id(), $format, false);

            return [
                'url' => $media['url'],
                'location' => $media['location'],
                'chat_media_id' => $media['chat_media_id'],
                'name' => $media['name'],
                'mime' => $media['mime'],
                'size' => $media['size'],
            ];
        } catch (Throwable $e) {
            Log::warning('Carousel template: keeping a copy of card media failed', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
