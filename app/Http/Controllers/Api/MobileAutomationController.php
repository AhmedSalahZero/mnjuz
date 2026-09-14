<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\AutoReply;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * الأتمتة الأساسية (الردود الجاهزة) من التطبيق.
 *
 * ردٌّ يُرسل تلقائياً حين تطابق رسالة العميل كلمةً مُحدَّدة: مطابقةً تامّة أو
 * احتواءً. وهي أبسط صور الأتمتة — أمّا المسارات المتشعّبة ففي منشئ المسارات
 * ولها واجهتها.
 *
 * الخدمة في الويب تقرأ المنشأة من الجلسة، ومسارات الـ API بلا جلسة، فتُمرَّر
 * هنا صراحةً في كل استعلام.
 */
class MobileAutomationController extends Controller
{
    use MobileApi;

    /** أنواع الردّ التي يقبلها التطبيق: نصّ فقط في هذه النسخة. */
    private const SUPPORTED_RESPONSE_TYPES = ['text'];

    public function index(Request $request): JsonResponse
    {
        $query = AutoReply::where('organization_id', $this->organizationId($request))
            ->whereNull('deleted_at');

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('trigger', 'like', $term);
            });
        }

        $rows = $query->orderByDesc('id')->paginate($this->perPage($request, 10));

        $rows->getCollection()->transform(fn ($row) => $this->present($row));

        return $this->paginated($rows);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $reply = $this->find($request, $uuid);

        return $reply ? $this->ok($this->present($reply)) : $this->fail(404, __('Data not found'));
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $organizationId = $this->organizationId($request);
        $validated = $validator->validated();

        $reply = new AutoReply();
        $reply->organization_id = $organizationId;
        $reply->created_by = $request->user()->id;
        $reply->created_at = now();
        $this->fill($reply, $validated);
        $reply->save();

        ActivityLogger::log(
            ActivityLogger::AUTO_REPLY_UPDATED,
            $validated['name'],
            'auto_reply',
            null,
            [],
            $organizationId
        );

        return $this->ok($this->present($reply->fresh()), __('Data added successfully!'));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $reply = $this->find($request, $uuid);

        if (!$reply) {
            return $this->fail(404, __('Data not found'));
        }

        $validator = $this->validator($request);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $this->fill($reply, $validated);
        $reply->save();

        ActivityLogger::log(
            ActivityLogger::AUTO_REPLY_UPDATED,
            $validated['name'],
            'auto_reply',
            null,
            [],
            $this->organizationId($request)
        );

        return $this->ok($this->present($reply->fresh()), __('Data updated successfully!'));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $reply = $this->find($request, $uuid);

        if (!$reply) {
            return $this->fail(404, __('Data not found'));
        }

        // حذف ناعم كما في الويب: الردّ يختفي عن القوائم ويبقى أثره.
        $reply->deleted_by = $request->user()->id;
        $reply->deleted_at = now();
        $reply->save();

        ActivityLogger::log(
            ActivityLogger::AUTO_REPLY_UPDATED,
            $reply->name,
            'auto_reply',
            null,
            [],
            $this->organizationId($request)
        );

        return $this->ok(null, __('Data deleted successfully!'));
    }

    private function validator(Request $request)
    {
        return Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'trigger' => 'required|string|max:255',
            'match_criteria' => 'required|in:exact match,contains',
            'response_type' => 'required|in:' . implode(',', self::SUPPORTED_RESPONSE_TYPES),
            'response' => 'required|string|max:4096',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fill(AutoReply $reply, array $data): void
    {
        $reply->name = $data['name'];
        $reply->trigger = $data['trigger'];
        $reply->match_criteria = $data['match_criteria'];
        $reply->metadata = json_encode([
            'type' => $data['response_type'],
            'data' => ['text' => $data['response']],
        ]);
        $reply->updated_at = now();
    }

    private function find(Request $request, string $uuid): ?AutoReply
    {
        return AutoReply::where('uuid', $uuid)
            ->where('organization_id', $this->organizationId($request))
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(AutoReply $reply): array
    {
        $metadata = $reply->metadata ? json_decode($reply->metadata, true) : [];

        return [
            'uuid' => (string) $reply->uuid,
            'name' => $reply->name,
            'trigger' => $reply->trigger,
            'match_criteria' => $reply->match_criteria,
            'response_type' => $metadata['type'] ?? 'text',
            // الردود القديمة قد تحمل صورة أو صوتاً؛ يصل نصّها فارغاً ويبقى
            // النوع ظاهراً كي يعرف التطبيق أنه لا يحرّره.
            'response' => $metadata['data']['text'] ?? null,
            'updated_at' => optional($reply->updated_at)->toDateTimeString(),
        ];
    }
}
