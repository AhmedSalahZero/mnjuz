<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * تسليم ملفات الوسائط المخزّنة محلياً.
 *
 * كان اسم الملف يُلصق بمسار التخزين كما يصل من العنوان:
 *
 *     $path = storage_path('app/' . $filename);
 *
 * والمسار مفتوح للعموم، ونمطه `.*` يقبل الشرطات والنقاط. فطلبٌ مثل
 * `/media/..%2f..%2f.env` كان يُخرج المسار من مجلّد التخزين إلى جذر المشروع
 * ويُسلّم ملف البيئة كاملاً: كلمة مرور القاعدة، وAPP_KEY، وتوكنات الدفع
 * والرسائل. وقد رُصدت محاولة فعلية في الإنتاج بتاريخ 11 سبتمبر 2026.
 *
 * نجا ذلك الطلب بالصدفة وحدها: `../` مجلّد لا ملف، وfile_exists تقبل
 * المجلّدات بينما response()->file تشترط ملفاً — فانتهى باستثناء 500 بدل
 * تسليم محتوى.
 *
 * الحلّ ثلاث طبقات:
 *   ١. رفض ما لا يكون اسم ملف صالحاً (صعود، مسار مطلق، بايت صفري).
 *   ٢. حلّ المسار الحقيقي بـ realpath — فتنكشف كل حيلة ترميز أو رابط رمزي.
 *   ٣. اشتراط أن يقع الناتج داخل `storage/app/public` وحده، وهو المجلّد
 *      الوحيد الذي تُكتب فيه الوسائط المخدومة. فما جاوره — ملفات العملاء
 *      المستوردة والملفات المؤقّتة — خارج المتناول ولو عُرف اسمه.
 *
 * وكل رفض يردّ 404 بنفس نصّ «غير موجود»: لا نفرّق بين ملف مفقود ومحاولة
 * تسلّل، كي لا يستدلّ الفاحص على شيء.
 */
class FileController extends Controller
{
    public function show(Request $request, $filename)
    {
        $path = $this->resolvePath((string) $filename, $request);

        if ($path === null) {
            return $this->notFound();
        }

        $mime = @mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=86400',
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * المسار الحقيقي للملف المطلوب، أو null إن لم يكن تسليمه جائزاً.
     */
    private function resolvePath(string $filename, Request $request): ?string
    {
        if ($filename === '' || str_contains($filename, "\0")) {
            return null;
        }

        // الشرطة الخلفية مسار صالح على بعض الأنظمة، فتُوحَّد قبل الفحص.
        $filename = str_replace('\\', '/', $filename);

        // المسار المطلق يتجاوز مجلّد التخزين كلّه.
        if (str_starts_with($filename, '/')) {
            $this->logRejection($filename, $request, 'absolute path');

            return null;
        }

        foreach (explode('/', $filename) as $segment) {
            if ($segment === '..') {
                $this->logRejection($filename, $request, 'traversal');

                return null;
            }
        }

        $allowed = realpath(storage_path('app/public'));

        if ($allowed === false) {
            return null;
        }

        $full = realpath(storage_path('app/' . $filename));

        if ($full === false || !is_file($full)) {
            return null;
        }

        // الحارس الأخير: بعد حلّ الروابط الرمزية، هل الملف داخل المسموح؟
        if (!str_starts_with($full, $allowed . DIRECTORY_SEPARATOR)) {
            $this->logRejection($filename, $request, 'outside public directory');

            return null;
        }

        return $full;
    }

    private function notFound()
    {
        return response(__('File not found'), 404)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * الرفض يُسجَّل تحذيراً لا خطأً: هو سلوك متوقَّع من الفاحصات، ولا يستحقّ
     * تنبيهاً. وفائدته أن يكشف مساراً مشروعاً أغفلناه إن ظهر في السجلّ.
     */
    private function logRejection(string $filename, Request $request, string $reason): void
    {
        Log::warning('Blocked media request', [
            'reason' => $reason,
            'filename' => mb_substr($filename, 0, 200),
            'ip' => $request->ip(),
        ]);
    }
}
