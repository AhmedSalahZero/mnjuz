<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * إصلاح metadata المحفوظة بـ [] (JSON array) بدلاً من {} (JSON object)
 * للأنواع: image, video, audio, document, sticker.
 *
 * السبب: minimalMediaBlock كانت تُرجع array_intersect_key فارغة []
 * عندما لا يوجد أي مفتاح مسموح (caption/voice/filename) في الـ block.
 * json_encode([]) = "[]" بدل "{}" فيتم حفظها كـ list.
 */
return new class extends Migration
{
    private array $fixes = [
        'image'    => '{"caption": null}',
        'video'    => '{"caption": null}',
        'audio'    => '{"voice": null}',
        'document' => '{"filename": null}',
        'sticker'  => '{}',
    ];

    public function up(): void
    {
        foreach ($this->fixes as $mediaType => $replacement) {
            DB::statement("
                UPDATE chats
                SET metadata = JSON_SET(metadata, '$.{$mediaType}', CAST(? AS JSON))
                WHERE JSON_EXTRACT(metadata, '$.type')    = ?
                  AND JSON_TYPE(JSON_EXTRACT(metadata, '$.{$mediaType}')) = 'ARRAY'
            ", [$replacement, $mediaType]);
        }
    }

    public function down(): void
    {
        // لا يمكن التراجع لأننا لا نعرف أي منها كانت فارغة فعلاً
    }
};
