<?php

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\ChatStatusLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Support\JsonText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * محادثة اختبار واحدة تحوي كل نوع رسالة يستقبله تطبيق الموبايل.
 *
 * لا توجد في الإنتاج جهة اتصال واحدة تجمع الأنواع كلها — أندرها (edit / revoke
 * / system / contacts) تقع في محادثات متفرّقة، وأقصى تغطية وجدناها 9 أنواع من
 * 14. هذا الـseeder يبني المحادثة الناقصة محلياً بدل انتظار عميل يرسلها.
 *
 * يُنشئ أيضاً ملفات ميديا حقيقية (صورة، فيديو، صوت، PDF، ملصق) تحت
 * storage/app/public/seed-message-types حتى يفتحها التطبيق فعلاً لا أن يرى
 * «المحتوى غير متاح».
 *
 * التشغيل:
 *   php artisan db:seed --class=ChatMessageTypesSeeder
 *   SEED_ORG_ID=211 SEED_TEST_PHONE=+966500000000 php artisan db:seed --class=ChatMessageTypesSeeder
 *
 * إعادة التشغيل آمنة: يمسح رسائل جهة الاتصال التجريبية ويبنيها من جديد.
 */
class ChatMessageTypesSeeder extends Seeder
{
    private const MEDIA_DIR = 'public/seed-message-types';

    private int $organizationId;
    private ?int $userId = null;
    private Carbon $clock;
    private array $mediaFiles = [];

    public function run(): void
    {
        $this->organizationId = $this->resolveOrganization();
        $this->userId = $this->resolveUser();
        $this->clock = Carbon::now()->utc()->subHours(3);

        $this->generateMediaFiles();

        $contact = $this->resolveContact();
        $this->purge($contact);

        $created = 0;
        foreach ($this->messages() as $message) {
            $this->insertMessage($contact, $message);
            $created++;
        }

        $contact->refresh();

        $this->command->newLine();
        $this->command->info("تم إنشاء {$created} رسالة على جهة الاتصال التجريبية:");
        $this->command->line("  organization_id : {$this->organizationId}");
        $this->command->line("  contact_id      : {$contact->id}");
        $this->command->line("  contact_uuid    : {$contact->uuid}");
        $this->command->line("  phone           : {$contact->phone}");
        $this->command->line("  الداشبورد       : /chats/{$contact->uuid}");
        $this->command->line('  جذر الميديا     : ' . $this->mediaBaseUrl());
        $this->command->newLine();
        $this->command->line('  ملاحظة: reaction مُدرَجة عمداً ويجب ألا تظهر في رد الـAPI،');
        $this->command->line('  و order نوع مجهول يجب أن يقع في الـdefault بلا انهيار.');
    }

    /* ------------------------------------------------------------------ */
    /* التهيئة                                                             */
    /* ------------------------------------------------------------------ */

    private function resolveOrganization(): int
    {
        $fromEnv = (int) env('SEED_ORG_ID');

        if ($fromEnv > 0) {
            if (!Organization::where('id', $fromEnv)->exists()) {
                throw new \RuntimeException("المنشأة {$fromEnv} غير موجودة.");
            }

            return $fromEnv;
        }

        $id = (int) Organization::query()->min('id');

        if ($id <= 0) {
            throw new \RuntimeException('لا توجد أي منشأة في قاعدة البيانات.');
        }

        $this->command->warn("SEED_ORG_ID غير محدّد — سنستخدم أول منشأة ({$id}).");

        return $id;
    }

    /**
     * موظّف من نفس المنشأة ليحمل الصادرُ اسمَ مُرسِل (value.user.full_name).
     * غيابه ليس خطأ — الصادر بلا user_id يصل بـuser = null.
     */
    private function resolveUser(): ?int
    {
        return (int) Team::where('organization_id', $this->organizationId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('user_id') ?: null;
    }

    private function resolveContact(): Contact
    {
        $phone = (string) (env('SEED_TEST_PHONE') ?: '+966500000000');

        $contact = Contact::where('organization_id', $this->organizationId)
            ->where('phone', $phone)
            ->first();

        if ($contact) {
            return $contact;
        }

        return Contact::create([
            'organization_id' => $this->organizationId,
            'first_name'      => 'اختبار',
            'last_name'       => 'أنواع الرسائل',
            'phone'           => $phone,
            'formatted_phone' => $phone,
            'created_by'      => $this->userId ?? 0,
            'created_at'      => Carbon::now()->utc(),
            'updated_at'      => Carbon::now()->utc(),
        ]);
    }

    /** إعادة التشغيل تبني من الصفر بدل أن تُكدّس نسخة فوق نسخة. */
    private function purge(Contact $contact): void
    {
        $chatIds = Chat::where('contact_id', $contact->id)->pluck('id')->all();

        if ($chatIds !== []) {
            $mediaIds = Chat::whereIn('id', $chatIds)->pluck('media_id')->filter()->unique()->all();

            ChatStatusLog::whereIn('chat_id', $chatIds)->delete();
            DB::table('chats')->whereIn('id', $chatIds)->delete();

            if ($mediaIds !== []) {
                ChatMedia::whereIn('id', $mediaIds)->delete();
            }
        }

        ChatLog::where('contact_id', $contact->id)->delete();
    }

    /* ------------------------------------------------------------------ */
    /* الملفات                                                             */
    /* ------------------------------------------------------------------ */

    private function generateMediaFiles(): void
    {
        $dir = storage_path('app/' . self::MEDIA_DIR);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->mediaFiles = [
            'image'    => $this->makeImage($dir . '/photo.jpg'),
            'sticker'  => $this->makeSticker($dir . '/sticker.webp'),
            'document' => $this->makePdf($dir . '/offer.pdf'),
            'video'    => $this->makeVideo($dir . '/clip.mp4'),
            'audio'    => $this->makeAudio($dir . '/voice.ogg'),
        ];

        foreach ($this->mediaFiles as $type => $path) {
            if ($path === null) {
                $this->command->warn("تعذّر توليد ملف {$type} — ستُنشأ الرسالة برابط لملف غير موجود (اختبار «المحتوى غير متاح»).");
            }
        }
    }

    private function makeImage(string $path): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $img = imagecreatetruecolor(640, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 16, 118, 96));
        imagestring($img, 5, 180, 190, 'MNJZ TEST IMAGE', imagecolorallocate($img, 255, 255, 255));
        imagejpeg($img, $path, 85);
        imagedestroy($img);

        return file_exists($path) ? $path : null;
    }

    private function makeSticker(string $path): ?string
    {
        if (!function_exists('imagewebp')) {
            return null;
        }

        $img = imagecreatetruecolor(256, 256);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledellipse($img, 128, 128, 200, 200, imagecolorallocate($img, 250, 204, 21));
        imagestring($img, 5, 100, 120, ':-)', imagecolorallocate($img, 0, 0, 0));
        imagewebp($img, $path);
        imagedestroy($img);

        return file_exists($path) ? $path : null;
    }

    /** PDF مكتوب باليد: أصغر ملف صالح، بلا أي اعتماد خارجي. */
    private function makePdf(string $path): ?string
    {
        $stream = "BT /F1 18 Tf 30 120 Td (MNJZ test document) Tj ET";

        $objects = [
            "<</Type/Catalog/Pages 2 0 R>>",
            "<</Type/Pages/Kids[3 0 R]/Count 1>>",
            "<</Type/Page/Parent 2 0 R/MediaBox[0 0 320 200]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>",
            "<</Length " . strlen($stream) . ">>stream\n" . $stream . "\nendstream",
            "<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj" . $body . "endobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer<</Size " . (count($objects) + 1) . "/Root 1 0 R>>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        file_put_contents($path, $pdf);

        return file_exists($path) ? $path : null;
    }

    private function makeVideo(string $path): ?string
    {
        return $this->ffmpeg([
            '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=15:duration=3',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac',
            '-movflags', '+faststart', $path,
        ], $path);
    }

    private function makeAudio(string $path): ?string
    {
        return $this->ffmpeg([
            '-f', 'lavfi', '-i', 'sine=frequency=520:duration=4',
            '-c:a', 'libopus', $path,
        ], $path);
    }

    private function ffmpeg(array $arguments, string $path): ?string
    {
        $binary = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

        if ($binary === '') {
            return null;
        }

        $command = escapeshellcmd($binary) . ' -y -loglevel error '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';

        shell_exec($command);

        return file_exists($path) ? $path : null;
    }

    /**
     * جذر الروابط. APP_URL قد يكون مضبوطاً على نطاق الإنتاج في بيئة الجهاز،
     * فنسمح بتجاوزه صراحةً كي لا تشير ملفات الاختبار إلى خادم آخر.
     */
    private function mediaBaseUrl(): string
    {
        return rtrim((string) (env('SEED_MEDIA_BASE_URL') ?: config('app.url')), '/');
    }

    private function mediaUrl(string $fileName): string
    {
        return $this->mediaBaseUrl() . '/media/' . self::MEDIA_DIR . '/' . $fileName;
    }

    /* ------------------------------------------------------------------ */
    /* الرسائل                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * كل عنصر: dir · meta · media · status · logs · deleted · is_read
     * الترتيب زمني — الرسالة الأولى أقدمها.
     */
    private function messages(): array
    {
        return [
            // ---------- text ----------
            [
                'dir'  => 'inbound',
                'meta' => ['type' => 'text', 'text' => ['body' => 'السلام عليكم، عندي استفسار عن الطلب.']],
            ],
            [
                'dir'    => 'outbound',
                'meta'   => ['type' => 'text', 'text' => ['body' => 'وعليكم السلام، تفضّل كيف أقدر أساعدك؟']],
                'status' => 'read',
                'logs'   => [['status' => 'sent'], ['status' => 'delivered'], ['status' => 'read']],
            ],
            [
                // قالب صادر: header + footer + أزرار — كلها تحت type=text
                'dir'  => 'outbound',
                'meta' => [
                    'type'    => 'text',
                    'header'  => ['text' => 'تأكيد الطلب'],
                    'text'    => ['body' => 'طلبك رقم 12345 قيد التجهيز.', 'footer' => 'منجز'],
                    'buttons' => [
                        ['type' => 'URL', 'text' => 'تتبّع الطلب', 'value' => 'https://example.com/track/12345'],
                        ['type' => 'QUICK_REPLY', 'text' => 'إلغاء الطلب', 'value' => null],
                        ['type' => 'PHONE_NUMBER', 'text' => 'اتصل بنا', 'value' => '+966500000001'],
                        ['type' => 'COPY_CODE', 'text' => 'نسخ الكود', 'value' => 'MNJZ2026'],
                    ],
                ],
                'status' => 'delivered',
            ],
            [
                // طلب الموقع الصادر: يُخزَّن text مع علامة إضافية
                'dir'    => 'outbound',
                'meta'   => ['type' => 'text', 'text' => ['body' => 'شاركنا موقعك من فضلك'], 'location_request' => true],
                'status' => 'delivered',
            ],

            // ---------- image ----------
            [
                'dir'   => 'inbound',
                'meta'  => ['type' => 'image', 'image' => ['caption' => 'الفاتورة المرفقة']],
                'media' => ['file' => 'photo.jpg', 'name' => 'N/A', 'type' => 'image/jpeg'],
            ],
            [
                'dir'    => 'outbound',
                'meta'   => ['id' => 'wamid.SEED.IMG', 'type' => 'image', 'image' => ['mime_type' => 'image/jpeg', 'caption' => 'صورة المنتج']],
                'media'  => ['file' => 'photo.jpg', 'name' => 'photo.jpg', 'type' => 'image/jpeg'],
                'status' => 'sent',
                'logs'   => [['status' => 'sent']],
            ],
            [
                // media = null: التحميل لم يكتمل بعد — يجب أن يعرض «المحتوى غير متاح» لا أن ينهار
                'dir'  => 'inbound',
                'meta' => ['type' => 'image', 'image' => ['caption' => null]],
            ],

            // ---------- video ----------
            [
                'dir'   => 'inbound',
                'meta'  => ['type' => 'video', 'video' => ['caption' => 'شاهد المشكلة في الفيديو']],
                'media' => ['file' => 'clip.mp4', 'name' => 'N/A', 'type' => 'video/mp4'],
            ],
            [
                'dir'    => 'outbound',
                'meta'   => [
                    'type'                   => 'video',
                    'video'                  => ['caption' => null],
                    'transcode_retry_status' => 'retrying',
                    'transcode_retry_count'  => 1,
                ],
                'media'  => ['file' => 'clip.mp4', 'name' => 'clip.mp4', 'type' => 'video/mp4'],
                'status' => 'failed',
                'logs'   => [['status' => 'failed', 'errors' => [['code' => 131053, 'title' => 'Media upload error']]]],
            ],

            // ---------- audio ----------
            [
                'dir'   => 'inbound',
                'meta'  => ['type' => 'audio', 'audio' => ['voice' => true]],
                'media' => ['file' => 'voice.ogg', 'name' => 'N/A', 'type' => 'audio/ogg'],
            ],
            [
                'dir'    => 'outbound',
                'meta'   => ['id' => 'wamid.SEED.AUD', 'type' => 'audio', 'audio' => ['mime_type' => 'audio/ogg']],
                'media'  => ['file' => 'voice.ogg', 'name' => 'voice.ogg', 'type' => 'audio/ogg'],
                'status' => 'delivered',
            ],

            // ---------- document ----------
            [
                'dir'   => 'inbound',
                'meta'  => ['type' => 'document', 'document' => ['filename' => 'invoice.pdf']],
                'media' => ['file' => 'offer.pdf', 'name' => 'invoice.pdf', 'type' => 'application/pdf'],
            ],
            [
                'dir'    => 'outbound',
                'meta'   => ['id' => 'wamid.SEED.DOC', 'type' => 'document', 'document' => ['mime_type' => 'application/pdf', 'caption' => 'العرض المطلوب']],
                'media'  => ['file' => 'offer.pdf', 'name' => 'offer.pdf', 'type' => 'application/pdf'],
                'status' => 'delivered',
            ],

            // ---------- sticker ----------
            [
                'dir'   => 'inbound',
                'meta'  => ['type' => 'sticker', 'sticker' => null],
                'media' => ['file' => 'sticker.webp', 'name' => 'N/A', 'type' => 'image/webp'],
            ],

            // ---------- location ----------
            [
                'dir'  => 'inbound',
                'meta' => [
                    'type'     => 'location',
                    'location' => [
                        'latitude'  => 21.485811,
                        'longitude' => 39.192505,
                        'name'      => 'فرع الروضة',
                        'address'   => 'الروضة، جدة',
                        'url'       => 'https://maps.google.com/?q=21.485811,39.192505',
                    ],
                    'context'  => ['id' => 'wamid.SEED.LOCREQ'],
                ],
            ],
            [
                'dir'    => 'outbound',
                'meta'   => ['type' => 'location', 'location' => ['latitude' => 24.774265, 'longitude' => 46.738586]],
                'status' => 'delivered',
            ],

            // ---------- contacts ----------
            [
                'dir'  => 'inbound',
                'meta' => [
                    'type'     => 'contacts',
                    'contacts' => [
                        [
                            'name'      => [
                                'formatted_name' => 'محمد أحمد',
                                'first_name'     => 'محمد',
                                'last_name'      => 'أحمد',
                                'middle_name'    => null,
                                'prefix'         => null,
                                'suffix'         => null,
                            ],
                            'phones'    => [['phone' => '+966551112233', 'wa_id' => '966551112233', 'type' => 'CELL']],
                            'emails'    => [['email' => 'm@example.com', 'type' => 'WORK']],
                            'org'       => ['company' => 'شركة النور', 'department' => null, 'title' => null],
                            'addresses' => [],
                            'urls'      => [],
                            'birthday'  => null,
                        ],
                        [
                            // جهة ناقصة عمداً: بلا formatted_name ولا org
                            'name'   => ['first_name' => 'سالم', 'last_name' => null],
                            'phones' => [['wa_id' => '966554445566']],
                        ],
                    ],
                ],
            ],
            [
                // مصفوفة فارغة يحوّلها الـAPI إلى null
                'dir'  => 'inbound',
                'meta' => ['type' => 'contacts', 'contacts' => []],
            ],

            // ---------- interactive ----------
            [
                'dir'  => 'inbound',
                'meta' => [
                    'type'        => 'interactive',
                    'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'btn_yes', 'title' => 'أوافق']],
                ],
            ],
            [
                'dir'  => 'inbound',
                'meta' => [
                    'type'        => 'interactive',
                    'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'opt_2', 'title' => 'فرع جدة', 'description' => 'التوصيل خلال ساعتين']],
                ],
            ],
            [
                // قائمة بلا وصف — الوصف اختياري
                'dir'  => 'inbound',
                'meta' => [
                    'type'        => 'interactive',
                    'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'opt_3', 'title' => 'فرع الرياض']],
                ],
            ],
            [
                // نوع تفاعلي غير معروف — يجب أن يعرض فقاعة عامّة لا أن ينهار
                'dir'  => 'inbound',
                'meta' => ['type' => 'interactive', 'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'body' => 'sent']]],
            ],

            // ---------- button ----------
            [
                'dir'  => 'inbound',
                'meta' => ['type' => 'button', 'button' => ['text' => 'نعم', 'payload' => 'YES']],
            ],

            // ---------- system / edit / revoke / unsupported ----------
            [
                'dir'  => 'inbound',
                'meta' => [
                    'type'   => 'system',
                    'system' => [
                        'body'  => 'تم تغيير الرقم إلى +966500000009',
                        'type'  => 'user_changed_number',
                        'wa_id' => '966500000009',
                    ],
                ],
            ],
            [
                'dir'  => 'inbound',
                'meta' => [
                    'type' => 'edit',
                    'edit' => [
                        'original_message_id' => 'wamid.SEED.ORIGINAL',
                        'message'             => ['type' => 'text', 'text' => ['body' => 'النصّ بعد التعديل']],
                    ],
                ],
            ],
            [
                'dir'  => 'inbound',
                'meta' => ['type' => 'revoke', 'revoke' => ['original_message_id' => 'wamid.SEED.ORIGINAL']],
            ],
            [
                'dir'  => 'inbound',
                'meta' => [
                    'type'   => 'unsupported',
                    'errors' => [[
                        'code'       => 131051,
                        'title'      => 'Message type not supported',
                        'error_data' => ['details' => 'Message type is not currently supported.'],
                    ]],
                ],
            ],
            [
                // errors غائبة عمداً — الداشبورد ينكسر عليها، والتطبيق يجب أن يحرسها
                'dir'  => 'inbound',
                'meta' => ['type' => 'unsupported'],
            ],

            // ---------- حالات حافّة ----------
            [
                // يجب ألا تصل التطبيق إطلاقاً (مستبعَدة من استعلامات الـAPI والبثّ)
                'dir'  => 'inbound',
                'meta' => ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.SEED.ORIGINAL', 'emoji' => '👍']],
            ],
            [
                // نوع مجهول — يجب أن يقع في الـdefault
                'dir'  => 'inbound',
                'meta' => ['type' => 'order', 'order' => ['catalog_id' => '123', 'product_items' => []]],
            ],
            [
                // رسالة محذوفة من داخل النظام: deleted_at غير فارغ
                'dir'     => 'outbound',
                'meta'    => ['type' => 'text', 'text' => ['body' => 'رسالة محذوفة من الداشبورد']],
                'status'  => 'delivered',
                'deleted' => true,
            ],
            [
                // آخر رسالة غير مقروءة — لاختبار unread_messages_count
                'dir'     => 'inbound',
                'meta'    => ['type' => 'text', 'text' => ['body' => 'آخر رسالة غير مقروءة']],
                'is_read' => 0,
            ],
        ];
    }

    private function insertMessage(Contact $contact, array $message): void
    {
        $this->clock = $this->clock->copy()->addMinutes(2);
        $createdAt = $this->clock->toDateTimeString();

        $mediaId = null;
        if (!empty($message['media'])) {
            $mediaId = ChatMedia::create([
                'name'       => $message['media']['name'],
                'path'       => $this->mediaUrl($message['media']['file']),
                'location'   => 'local',
                'type'       => $message['media']['type'],
                'size'       => (string) $this->fileSize($message['media']['file']),
                'created_at' => $createdAt,
            ])->id;
        }

        $isInbound = $message['dir'] === 'inbound';

        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'contact_id'      => $contact->id,
            'user_id'         => $isInbound ? null : $this->userId,
            'type'            => $message['dir'],
            'metadata'        => JsonText::encode($message['meta']),
            'media_id'        => $mediaId,
            'wam_id'          => 'wamid.SEED.' . strtoupper(bin2hex(random_bytes(6))),
            'status'          => $message['status'] ?? ($isInbound ? 'delivered' : 'sent'),
            'is_read'         => $isInbound ? ($message['is_read'] ?? 1) : 1,
            'deleted_at'      => !empty($message['deleted']) ? $createdAt : null,
            'created_at'      => $createdAt,
        ]);

        // نسخ محليّة من القاعدة قد تحمل chat_status_logs لرسائل غير موجودة
        // (chat_id يتجاوز أكبر id في chats)، فيرث الصفُّ الجديد سجلّاتٍ ليست
        // له وتظهر حالة رسالة أخرى على رسالتنا. نُنظّف معرّفه قبل الكتابة.
        ChatStatusLog::where('chat_id', $chat->id)->delete();

        foreach ($message['logs'] ?? [] as $index => $log) {
            ChatStatusLog::create([
                'chat_id'    => $chat->id,
                'metadata'   => JsonText::encode(array_merge(['id' => $chat->wam_id], $log)),
                'created_at' => $this->clock->copy()->addSeconds($index + 1)->toDateTimeString(),
            ]);
        }

        // سجلّ المحادثة هو ما تقرؤه مزامنة الموبايل — بدونه لا تظهر الرسالة.
        ChatLog::create([
            'contact_id'  => $contact->id,
            'entity_type' => 'chat',
            'entity_id'   => $chat->id,
            'created_at'  => $createdAt,
            'updated_at'  => $createdAt,
        ]);
    }

    private function fileSize(string $fileName): int
    {
        $path = storage_path('app/' . self::MEDIA_DIR . '/' . $fileName);

        return is_file($path) ? (int) filesize($path) : 0;
    }
}
