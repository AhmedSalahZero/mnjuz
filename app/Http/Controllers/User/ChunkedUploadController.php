<?php

namespace App\Http\Controllers\User;

use App\Helpers\ChatMediaUploadHelper;
use App\Http\Controllers\Controller;
use App\Jobs\SendMediaJob;
use App\Models\Contact;
use App\Services\Chat\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * استقبال المرفقات على قطع.
 *
 * كل قطعة طلبٌ قصير مستقلّ، فلا يقترب أي طلب من مهلة Cloudflare (١٢٥ ثانية)
 * مهما كبر الملف أو بطؤت الشبكة. وحين تصل القطعة الأخيرة تُدمَج وتُسلَّم إلى
 * نفس مسار الإرسال الذي يستعمله الرفع العادي — فلا يتفرّع سلوك الإرسال.
 */
class ChunkedUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'max:64'],
            'index' => ['required', 'integer', 'min:0', 'max:' . (ChunkedUploadService::MAX_CHUNKS - 1)],
            'total' => ['required', 'integer', 'min:1', 'max:' . ChunkedUploadService::MAX_CHUNKS],
            'chunk' => ['required', 'file'],
            'contact_uuid' => ['required', 'string'],
            'file_name' => ['required', 'string', 'max:255'],
            'file_type' => ['required', 'string', 'in:image,video,audio,document'],
            'caption' => ['nullable', 'string'],
            'temp_message_id' => ['nullable', 'string', 'max:64'],
            'message_uuid' => ['nullable', 'string', 'max:64'],
        ]);

        $organizationId = (int) session()->get('current_organization');
        $userId = (int) auth()->id();

        if ($organizationId <= 0) {
            return response()->json(['success' => false, 'message' => __('No organization selected.')], 422);
        }

        // جهة الاتصال تُتحقَّق قبل قبول أي بايت: رفعٌ إلى محادثة لا يملكها
        // المستخدم يملأ القرص ولا يصل أحداً.
        $contact = Contact::where('uuid', $validated['contact_uuid'])
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->first();

        if (!$contact) {
            return response()->json(['success' => false, 'message' => __('Contact not found.')], 404);
        }

        $directory = ChunkedUploadService::directoryFor($organizationId, $userId, $validated['upload_id']);

        ChunkedUploadService::storeChunk($directory, (int) $validated['index'], $request->file('chunk'));

        $total = (int) $validated['total'];
        $received = ChunkedUploadService::receivedCount($directory, $total);

        if ($received < $total) {
            return response()->json([
                'success' => true,
                'completed' => false,
                'received' => $received,
                'total' => $total,
            ]);
        }

        return $this->finish($validated, $organizationId, $userId, $directory, $total);
    }

    /** إلغاء رفعٍ لم يكتمل — يُحرّر القرص فوراً بدل انتظار التنظيف المجدوَل. */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['upload_id' => ['required', 'string', 'max:64']]);

        ChunkedUploadService::discard(ChunkedUploadService::directoryFor(
            (int) session()->get('current_organization'),
            (int) auth()->id(),
            $validated['upload_id']
        ));

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------- الدمج

    /** @param array<string, mixed> $validated */
    private function finish(array $validated, int $organizationId, int $userId, string $directory, int $total): JsonResponse
    {
        $extension = strtolower((string) pathinfo($validated['file_name'], PATHINFO_EXTENSION));
        $path = ChunkedUploadService::assemble($directory, $total, $extension);

        if ($path === null) {
            Log::error('Chunked upload assembly failed', [
                'organization_id' => $organizationId,
                'upload_id' => $validated['upload_id'],
                'total' => $total,
            ]);

            return response()->json(['success' => false, 'message' => __('Upload could not be completed.')], 500);
        }

        // الحدّ يُفحص بعد الدمج: القطع تمرّ فرادى، والملف المجمَّع هو ما يُرسَل.
        $size = filesize(\Illuminate\Support\Facades\Storage::disk('local')->path($path));
        $limit = ChatMediaUploadHelper::maxUploadBytesForType($validated['file_type']);

        if ($size > $limit) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);

            return response()->json([
                'success' => false,
                'message' => __('File is larger than the :size limit.', [
                    'size' => round($limit / (1024 * 1024)) . ' MB',
                ]),
            ], 422);
        }

        SendMediaJob::dispatch(
            $organizationId,
            $validated['contact_uuid'],
            $validated['file_type'],
            $validated['file_name'],
            $path,
            $userId,
            $validated['temp_message_id'] ?? null,
            $validated['message_uuid'] ?? null,
            $validated['caption'] ?? null
        )->onQueue('high');

        return response()->json(['success' => true, 'completed' => true, 'received' => $total, 'total' => $total]);
    }
}
