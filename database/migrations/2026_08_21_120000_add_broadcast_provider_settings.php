<?php

use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * صفوف إعداد مزوّد البثّ.
 *
 * broadcast_provider يبدأ بـpusher: نشر هذا الترحيل لا يُبدّل شيئاً، والتبديل
 * قرار لاحق مستقلّ. وبيانات Reverb تُحفظ معه لا بدلاً منه، فالعودة بين
 * المزوّدين تغييرُ قيمةٍ واحدة بلا إدخال مفاتيح.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $rows = [
        BroadcastProvider::SETTING_KEY => BroadcastProvider::PUSHER,
        'reverb_app_id' => '',
        'reverb_app_key' => '',
        'reverb_app_secret' => '',
        'reverb_host' => '',
        'reverb_port' => '443',
        'reverb_scheme' => 'https',
    ];

    public function up(): void
    {
        foreach ($this->rows as $key => $value) {
            // لا نلمس صفّاً موجوداً: قد يكون المشغّل ضبطه قبل الترحيل.
            if (!DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys($this->rows))->delete();
    }
};
