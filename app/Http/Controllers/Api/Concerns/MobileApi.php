<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ما تشترك فيه نقاط التطبيق: المنشأة، والصلاحية، وشكل الردّ.
 *
 * المنشأة تأتي من `current_mobile_organization_id` لا من الجلسة: مسارات
 * الـ API بلا جلسة أصلاً (مجموعة api في الـ Kernel لا تُشغّل StartSession)،
 * فكل خدمة تقرأ session()->get('current_organization') تعود بفراغ. لذلك
 * تُمرَّر المنشأة صراحةً في كل استعلام هنا.
 *
 * وشكل الردّ واحد في كل النقاط — statusCode وsuccess وmessage وdata — لأن
 * التطبيق يفرّع عليه.
 */
trait MobileApi
{
    protected function organizationId(Request $request): int
    {
        return (int) ($request->user()->current_mobile_organization_id ?? 0);
    }

    /** صلاحية المستخدم داخل هذه المنشأة: owner أو manager أو agent. */
    protected function role(Request $request): string
    {
        $role = $request->user()->getRoleNameForOrganization($this->organizationId($request));

        return $role !== '' ? $role : OrganizationRole::OWNER;
    }

    protected function isPrivileged(Request $request): bool
    {
        return OrganizationRole::isPrivileged($this->role($request));
    }

    protected function ok($data = null, ?string $message = null): JsonResponse
    {
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], 200);
    }

    protected function fail(int $status, string $message, ?array $errors = null): JsonResponse
    {
        $payload = [
            'statusCode' => $status,
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    protected function forbidden(?string $message = null): JsonResponse
    {
        return $this->fail(403, $message ?: __('You are not allowed to access this page.'));
    }

    /** ترقيم موحّد: التطبيق يقرأ نفس المفاتيح في كل قائمة. */
    protected function paginated($paginator, ?string $message = null): JsonResponse
    {
        return $this->ok([
            'items' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], $message);
    }

    /** حجم صفحة آمن: طلب ألف صفّ يُسقط الردّ عند التطبيق لا عندنا. */
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        $value = (int) $request->input('per_page', $default);

        return max(1, min($value, $max));
    }
}
