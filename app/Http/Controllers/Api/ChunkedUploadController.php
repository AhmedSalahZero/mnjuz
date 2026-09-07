<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ChatMediaUploadHelper;
use App\Helpers\MessagingWindowHelper;
use App\Helpers\WhatsappConnectionHelper;
use App\Http\Controllers\Controller;
use App\Jobs\SendMediaJob;
use App\Models\Contact;
use App\Services\Chat\ChunkedUploadService;
use App\Services\ContactService;
use App\Services\PhoneService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * رفع مرفقات التطبيق على قطع.
 *
 * نظيرة User\ChunkedUploadController للجوال. الطلب الواحد الحامل للملف كلّه
 * يموت مرّتين على شبكة الجوال: عند post_max_size، وقبله عند مهلة الوكيل
 * الأمامي (١٢٥ ثانية) — فينقطع الرفع بلا رسالة ويتجمّد الشريط في منتصفه.
 * القطعة الصغيرة تجعل الزمن يُقاس بالقطعة لا بالملف.
 *
 * والفرق الوحيد عن نظيرتها أن المنشأة تأتي من `current_mobile_organization_id`
 * لا من الجلسة، وجهة الاتصال من رقم الهاتف لا من uuid — فالتطبيق لا يملك
 * جلسة ولا يعرف الـ uuid قبل أن يُنشأ العميل.
 */
class ChunkedUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $organizationId = (int) ($request->user()->current_mobile_organization_id ?? 0);

        if ($organizationId <= 0) {
            return self::fail(422, __('No organization selected.'));
        }

        $validator = Validator::make($request->all(), [
            'upload_id' => ['required', 'string', 'max:64'],
            'index' => ['required', 'integer', 'min:0', 'max:' . (ChunkedUploadService::MAX_CHUNKS - 1)],
            'total' => ['required', 'integer', 'min:1', 'max:' . ChunkedUploadService::MAX_CHUNKS],
            'chunk' => ['required', 'file'],
            'phone' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!PhoneService::isValid($value)) {
                    $fail('The phone number is not valid.');
                }
            }],
            'file_name' => ['required', 'string', 'max:255'],
            'file_type' => ['nullable', 'string', 'in:image,video,audio,document,gif'],
            'caption' => ['nullable', 'string'],
            'msg_uuid' => ['nullable', 'string', 'max:64'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors(),
            ], 400);
        }

        $validated = $validator->validated();

        // النوع من الامتداد حين لا يُرسله التطبيق — ونفس الجدول الذي يحكم
        // الرفع العادي، فلا يُقبل هنا ما يُرفض هناك.
        $extension = strtolower((string) pathinfo($validated['file_name'], PATHINFO_EXTENSION));
        $fileType = $validated['file_type'] ?? ChatMediaUploadHelper::typeForExtension($extension);

        if ($fileType === null || ChatMediaUploadHelper::typeForExtension($extension) === null) {
            return self::fail(400, __('This file type is not supported.'));
        }

        // الفحوص كلّها قبل تخزين أي بايت: رفعُ ستين ميغابايت ثم اكتشافُ أن
        // النافذة مغلقة يُهدر شبكة العميل وقرصنا معاً بلا رسالة تصل أحداً.
        if (!SubscriptionService::isSubscriptionActive($organizationId)) {
            return self::fail(403, __('Please renew or subscribe to a plan to continue!'));
        }

        if ($error = WhatsappConnectionHelper::errorFor($organizationId)) {
            return self::fail(403, $error);
        }

        $contact = $this->resolveContact($request, $organizationId);

        if (!$contact) {
            return self::fail(400, __('The phone number is not valid.'));
        }

        if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return MessagingWindowHelper::closedWindowApiJsonResponse();
        }

        $directory = ChunkedUploadService::directoryFor(
            $organizationId,
            (int) $request->user()->id,
            $validated['upload_id']
        );

        ChunkedUploadService::storeChunk($directory, (int) $validated['index'], $request->file('chunk'));

        $total = (int) $validated['total'];
        $received = ChunkedUploadService::receivedCount($directory, $total);

        if ($received < $total) {
            return response()->json([
                'statusCode' => 200,
                'success' => true,
                'message' => null,
                'data' => [
                    'completed' => false,
                    'received' => $received,
                    'total' => $total,
                ],
            ], 200);
        }

        return $this->finish($validated, $fileType, $extension, $contact, $organizationId, (int) $request->user()->id, $directory, $total);
    }

    /** إلغاء رفعٍ لم يكتمل — يُحرّر القرص فوراً بدل انتظار التنظيف المجدوَل. */
    public function destroy(Request $request): JsonResponse
    {
        $organizationId = (int) ($request->user()->current_mobile_organization_id ?? 0);

        $validator = Validator::make($request->all(), [
            'upload_id' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statusCode' => 400,
                'success' => false,
                'message' => __('The provided data is invalid.'),
                'errors' => $validator->errors(),
            ], 400);
        }

        ChunkedUploadService::discard(ChunkedUploadService::directoryFor(
            $organizationId,
            (int) $request->user()->id,
            $validator->validated()['upload_id']
        ));

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => null,
            'data' => ['discarded' => true],
        ], 200);
    }

    // ------------------------------------------------------- الدمج

    /** @param array<string, mixed> $validated */
    private function finish(
        array $validated,
        string $fileType,
        string $extension,
        Contact $contact,
        int $organizationId,
        int $userId,
        string $directory,
        int $total
    ): JsonResponse {
        $path = ChunkedUploadService::assemble($directory, $total, $extension);

        if ($path === null) {
            Log::error('Mobile chunked upload assembly failed', [
                'organization_id' => $organizationId,
                'upload_id' => $validated['upload_id'],
                'total' => $total,
            ]);

            return self::fail(500, __('Upload could not be completed.'));
        }

        // الحدّ يُفحص بعد الدمج، وبحدّ النوع وحده: القطع تمرّ فرادى فلا يبلغ
        // أيٌّ منها حدّ PHP، وقياس المجمَّع به كان يُبطل الغرض من التجزئة.
        $size = (int) filesize(Storage::disk('local')->path($path));
        $limit = ChatMediaUploadHelper::maxAssembledBytesForType($fileType);

        if ($size > $limit) {
            Storage::disk('local')->delete($path);

            return self::fail(400, __('File is larger than the :size limit.', [
                'size' => round($limit / (1024 * 1024)) . ' MB',
            ]));
        }

        SendMediaJob::dispatch(
            $organizationId,
            $contact->uuid,
            $fileType,
            $validated['file_name'],
            $path,
            $userId,
            null,
            $validated['msg_uuid'] ?? null,
            $validated['caption'] ?? null
        )->onQueue('high');

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => __('Message sent successfully'),
            'data' => [
                'completed' => true,
                'queued' => true,
                'received' => $total,
                'total' => $total,
                'contact_id' => $contact->id,
                'contact_uuid' => (string) $contact->uuid,
                'phone' => $contact->phone,
            ],
        ], 200);
    }

    private function resolveContact(Request $request, int $organizationId): ?Contact
    {
        try {
            $contactService = new ContactService($organizationId);

            return $contactService->findOrCreateByPhone($request->phone, array_filter([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
            ], fn ($value) => $value !== null && $value !== ''));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function fail(int $status, string $message): JsonResponse
    {
        return response()->json([
            'statusCode' => $status,
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
