<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قالب واتساب.
 *
 * SoftDeletes هنا لأن العمود `deleted_at` موجود منذ إنشاء الجدول والحذف
 * يكتبه، لكن الموديل لم يكن يعرفه — فكل استعلام كان عليه أن يستثني المحذوف
 * بنفسه، ونسيَته تسعة مواضع: إرسال رسالة المصادقة، وإنشاء حملة، وإرسال قالب
 * من المحادثة، وغيرها. فكان يُرسَل بقالب محذوف بينما تقول شاشة الإعدادات
 * إنه غير موجود.
 *
 * والمواضع التي تحتاج الصفّ المحذوف — مطابقة ما يصل من Meta بما عندنا —
 * تطلبه صراحةً بـ withTrashed()، وهي وحدها ما يجوز له ذلك.
 */
class Template extends Model {
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    public $timestamps = false;
    protected $dates = ['deleted_at'];
}
