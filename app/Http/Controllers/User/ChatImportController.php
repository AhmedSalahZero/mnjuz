<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Jobs\ImportChatHistoryJob;
use App\Jobs\RollbackChatImportJob;
use App\Jobs\ValidateChatImportJob;
use App\Models\Addon;
use App\Models\ChatImport;
use App\Models\Team;
use App\Services\ActivityLogger;
use App\Services\Chat\ChunkedUploadService;
use App\Services\ChatImport\ChatImportException;
use App\Services\ChatImport\ChatImportService;
use App\Support\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * استيراد سجلّ المحادثات من منصّة أخرى.
 *
 * الرفع على قطع (Cloudflare يقطع أي طلب تجاوز ١٢٥ ثانية، وملف من خمسين
 * ميغابايت على شبكة بطيئة يتجاوزها)، ثم فحص الأعمدة في الطلب نفسه، ثم
 * الطابور: فحص كامل، فكتابة على شرائح. الصفحة تسأل عن الحالة كل ثانيتين.
 */
class ChatImportController extends Controller
{
    /** أكبر قطعة يقبلها الخادم بالكيلوبايت — العميل يرسل خمسة ميغابايت. */
    private const CHUNK_MAX_KB = 6 * 1024;

    public function index(): Response
    {
        $organizationId = $this->authorizeImport();

        $imports = ChatImport::with('user')
            ->forOrganization($organizationId)
            ->latest('id')
            ->limit(20)
            ->get();

        return Inertia::render('User/Settings/ChatImport', [
            'title' => __('Import conversations'),
            'modules' => Addon::get(),
            'imports' => $imports->map(fn (ChatImport $import) => $import->toStatusPayload(false))->values(),
            'latest' => $imports->first()?->toStatusPayload(),
            'countries' => $this->countries(),
            'limits' => [
                'max_file_mb' => (int) config('chat_import.max_file_mb', 200),
                'max_rows' => (int) config('chat_import.max_rows', 1_000_000),
            ],
        ]);
    }

    public function template(): StreamedResponse
    {
        $this->authorizeImport();

        $rows = [
            ['phone', 'name', 'sent_at', 'direction', 'text'],
            ['966501234567', 'Sara Ahmed', '2026-05-23T19:29:38+03:00', 'in', 'السلام عليكم، أريد حجز موعد'],
            ['966501234567', 'Sara Ahmed', '2026-05-23T19:30:05+03:00', 'out', "أهلاً سارة 👋\nالمواعيد المتاحة اليوم: 6 مساءً أو 8 مساءً"],
            ['966501234567', 'Sara Ahmed', '2026-05-23 19:31:10', 'in', '8 مساءً'],
            ['201001234567', 'Omar', '2026-05-24T10:02:00+02:00', 'in', '[image] صورة الوصفة'],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');
            // BOM: بدونه يفتح Excel الملف بترميز النظام فتظهر العربية رموزاً.
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '');
            }
            fclose($out);
        }, 'chat_history_template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function uploadChunk(Request $request): JsonResponse
    {
        $organizationId = $this->authorizeImport();
        $maxBytes = (int) config('chat_import.max_file_mb', 200) * 1024 * 1024;

        $validated = $request->validate([
            'upload_id' => ['required', 'string', 'max:64'],
            'index' => ['required', 'integer', 'min:0', 'max:' . (ChunkedUploadService::MAX_CHUNKS - 1)],
            'total' => ['required', 'integer', 'min:1', 'max:' . ChunkedUploadService::MAX_CHUNKS],
            'chunk' => ['required', 'file', 'max:' . self::CHUNK_MAX_KB],
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1'],
            'default_country' => ['nullable', 'string', 'size:2'],
        ]);

        if (!preg_match('/\.(csv|txt)$/i', $validated['file_name'])) {
            return $this->error(__('Only CSV files are accepted.'), 422);
        }

        // الحجم يُرفض من أوّل قطعة: انتظار الدمج كان يعني رفع مئات الميغابايت
        // ثم رفضها.
        if ((int) $validated['file_size'] > $maxBytes) {
            return $this->error($this->tooLargeMessage(), 422);
        }

        $country = isset($validated['default_country']) ? strtoupper($validated['default_country']) : null;
        if ($country !== null && !in_array($country, PhoneNumberUtil::getInstance()->getSupportedRegions(), true)) {
            return $this->error(__('Unknown country.'), 422);
        }

        $uploadKey = ChunkedUploadService::sanitizeId('ci-' . $validated['upload_id']);

        // إعادة إرسال القطعة الأخيرة بعد انقطاع: الاستيراد أُنشئ فعلاً.
        $existing = ChatImport::forOrganization($organizationId)->where('upload_id', $uploadKey)->first();
        if ($existing) {
            return response()->json(['success' => true, 'completed' => true, 'import' => $existing->toStatusPayload()]);
        }

        if ($this->hasActiveImport($organizationId)) {
            return $this->error(__('Another import is in progress. Wait for it to finish first.'), 409);
        }

        $userId = (int) auth()->id();
        $directory = ChunkedUploadService::directoryFor($organizationId, $userId, $uploadKey);
        ChunkedUploadService::storeChunk($directory, (int) $validated['index'], $request->file('chunk'));

        $total = (int) $validated['total'];
        $received = ChunkedUploadService::receivedCount($directory, $total);

        if ($received < $total) {
            return response()->json(['success' => true, 'completed' => false, 'received' => $received, 'total' => $total]);
        }

        $path = ChunkedUploadService::assemble($directory, $total, 'csv', ChatImportService::directory($organizationId));
        if ($path === null) {
            return $this->error(__('Upload could not be completed.'), 500);
        }

        $disk = Storage::disk('local');
        $size = (int) $disk->size($path);

        if ($size > $maxBytes) {
            $disk->delete($path);

            return $this->error($this->tooLargeMessage(), 422);
        }

        try {
            $ignored = ChatImportService::inspectHeader($disk->path($path));
        } catch (ChatImportException $e) {
            $disk->delete($path);

            return $this->error($e->getMessage(), 422);
        }

        $import = ChatImport::create([
            'upload_id' => $uploadKey,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'original_name' => mb_substr(basename($validated['file_name']), 0, 255),
            'file_path' => $path,
            'file_size' => $size,
            'default_country' => $country,
            'timezone' => ChatImportService::timezoneFor($organizationId),
            'locale' => app()->getLocale(),
            'status' => ChatImport::PENDING_VALIDATION,
            'ignored_columns' => $ignored,
        ]);

        ChatImportService::log($import->load('user'), ActivityLogger::CHAT_IMPORT_STARTED);

        ValidateChatImportJob::dispatch($import->id);

        return response()->json([
            'success' => true,
            'completed' => true,
            'import' => $import->refresh()->toStatusPayload(),
        ], 201);
    }

    public function discardUpload(Request $request): JsonResponse
    {
        $organizationId = $this->authorizeImport();
        $validated = $request->validate(['upload_id' => ['required', 'string', 'max:64']]);

        ChunkedUploadService::discard(ChunkedUploadService::directoryFor(
            $organizationId,
            (int) auth()->id(),
            ChunkedUploadService::sanitizeId('ci-' . $validated['upload_id'])
        ));

        return response()->json(['success' => true]);
    }

    public function status(string $uuid): JsonResponse
    {
        return response()->json($this->find($uuid)->toStatusPayload());
    }

    public function errors(string $uuid)
    {
        $import = $this->find($uuid);

        if (!$import->errors_file_path || !Storage::disk('local')->exists($import->errors_file_path)) {
            abort(404);
        }

        $name = pathinfo($import->original_name, PATHINFO_FILENAME) . '-errors.csv';

        return Storage::disk('local')->download($import->errors_file_path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function cancel(string $uuid): JsonResponse
    {
        $import = $this->find($uuid);

        if (!$import->canCancel()) {
            return $this->error(__('This import can no longer be cancelled.'), 422);
        }

        // ما يعمل الآن على الطابور يُطلب منه التوقّف فيتوقّف بين دفعتين؛ وما
        // ينتظر دوره يُلغى مباشرةً فيجد وظيفته الحالة ملغاة وتنتهي.
        $running = in_array($import->status, [ChatImport::VALIDATING, ChatImport::IMPORTING], true);
        $next = $running ? ChatImport::CANCELLING : ChatImport::CANCELLED;

        $updated = ChatImport::whereKey($import->id)->where('status', $import->status)->update([
            'status' => $next,
            'finished_at' => $running ? null : now(),
            'updated_at' => now(),
        ]);

        if (!$updated) {
            return $this->error(__('The import status changed. Refresh the page and try again.'), 409);
        }

        $import->refresh();

        if (!$running && $import->started_at === null) {
            ChatImportService::deleteUploadedFile($import);
        }

        return response()->json($import->toStatusPayload());
    }

    public function resume(string $uuid): JsonResponse
    {
        $import = $this->find($uuid);

        if (!$import->canResume() || !ChatImportService::fileExists($import)) {
            return $this->error(__('This import cannot be resumed.'), 422);
        }

        if ($this->hasActiveImport((int) $import->organization_id)) {
            return $this->error(__('Another import is in progress. Wait for it to finish first.'), 409);
        }

        $import->forceFill(['status' => ChatImport::QUEUED, 'error_message' => null, 'finished_at' => null])->save();

        ImportChatHistoryJob::dispatch($import->id);

        return response()->json($import->refresh()->toStatusPayload());
    }

    public function rollback(string $uuid): JsonResponse
    {
        $import = $this->find($uuid);

        if (!$import->canRollback()) {
            return $this->error(__('There is nothing to roll back for this import.'), 422);
        }

        if ($this->hasActiveImport((int) $import->organization_id)) {
            return $this->error(__('Another import is in progress. Wait for it to finish first.'), 409);
        }

        $import->forceFill(['status' => ChatImport::ROLLING_BACK, 'error_message' => null, 'finished_at' => null])->save();

        RollbackChatImportJob::dispatch($import->id);

        return response()->json($import->refresh()->toStatusPayload());
    }

    // ------------------------------------------------------------------

    /**
     * المالك والمدير وحدهم. المسار خارج بادئات الموظّف أصلاً، والفحص هنا
     * حارسٌ ثانٍ لا يعتمد على قائمة في وسيط آخر.
     */
    private function authorizeImport(): int
    {
        $organizationId = (int) session()->get('current_organization');
        $user = auth()->user();

        abort_if($organizationId <= 0 || !$user, 403);

        if ($user->role === 'user') {
            $role = Team::where('organization_id', $organizationId)->where('user_id', $user->id)->value('role');
            abort_unless(OrganizationRole::isPrivileged($role), 403, __('You do not have permission to access this section.'));
        }

        return $organizationId;
    }

    private function find(string $uuid): ChatImport
    {
        $organizationId = $this->authorizeImport();

        // abort لا firstOrFail: معالج الأخطاء يُرجع ModelNotFoundException في
        // طلبات JSON كـ 500، والصفحة تحتاج أن تعرف أن الاستيراد غير موجود.
        $import = ChatImport::with('user')->forOrganization($organizationId)->where('uuid', $uuid)->first();
        abort_if($import === null, 404);

        return $import;
    }

    private function hasActiveImport(int $organizationId): bool
    {
        return ChatImport::forOrganization($organizationId)->active()->exists();
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    private function tooLargeMessage(): string
    {
        return __('File is larger than the :size limit.', ['size' => config('chat_import.max_file_mb', 200) . ' MB']);
    }

    /** @return array<int, array{code: string, name: string}> الدول بأسمائها بلغة المستخدم */
    private function countries(): array
    {
        $locale = app()->getLocale();
        $util = PhoneNumberUtil::getInstance();
        $countries = [];

        foreach ($util->getSupportedRegions() as $code) {
            $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $code, $locale) : $code;
            $countries[] = [
                'code' => $code,
                'name' => ($name ?: $code) . ' (+' . $util->getCountryCodeForRegion($code) . ')',
            ];
        }

        $collator = class_exists(\Collator::class) ? new \Collator($locale) : null;
        usort($countries, fn ($a, $b) => $collator ? $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));

        return $countries;
    }
}
