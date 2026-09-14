<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Rules\UniqueEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * الملف الشخصي للمستخدم من التطبيق.
 *
 * ما يعدّله الويب في صفحة «ملفي الشخصي»: الاسم والبريد والهاتف واللغة، ثم
 * كلمة المرور. والتحقّق هو نفسه (UniqueEmail وتأكيد كلمة المرور القديمة)
 * كي لا يمرّ من التطبيق ما يُرفض من المتصفّح.
 */
class MobileProfileController extends Controller
{
    use MobileApi;

    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->payload($request));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $organization = Organization::find($organizationId);

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'language' => $user->language,
            // صلاحية المنشأة لا صلاحية المنصّة — نفس ما تُرجعه list-teams.
            'role' => $this->role($request),
            'organization' => $organization ? [
                'id' => $organization->id,
                'uuid' => $organization->uuid,
                'name' => $organization->name,
            ] : null,
        ];
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => ['required', 'email', new UniqueEmail($user->id)],
            'phone' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:10',
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $validated = $validator->validated();

        User::where('id', $user->id)->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'language' => $validated['language'] ?? $user->language,
        ]);

        // التحديث تمّ باستعلام لا بالموديل، فنسخة المستخدم في الذاكرة ما
        // زالت قديمة — وهي التي يبني منها الردّ.
        $user->refresh();

        return $this->ok($this->payload($request), __('Profile updated successfully!'));
    }

    /**
     * تغيير كلمة المرور.
     *
     * القديمة تُفحص أوّلاً: بلا ذلك يكفي جهازٌ مفتوح لسرقة الحساب كاملاً.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        if (!Hash::check($request->input('old_password'), $user->password)) {
            return $this->fail(400, __('The provided data is invalid.'), [
                'old_password' => [__('Your old password is incorrect.')],
            ]);
        }

        User::where('id', $user->id)->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return $this->ok(null, __('Password updated successfully!'));
    }
}
