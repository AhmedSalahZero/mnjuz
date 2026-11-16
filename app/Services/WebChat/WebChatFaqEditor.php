<?php

namespace App\Services\WebChat;

use App\Models\WebChatFaq;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إضافة الأسئلة الشائعة وتعديلها وحذفها وترتيبها. كل تغيير يمسح كاش ما يصل
 * الـ widget (WebChatFaqs::forget).
 *
 * منقول من WebChatFaqController كما هو ليمرّ الويب والتطبيق من طريق واحد.
 * الصلاحية والإضافة على من يستدعي.
 */
class WebChatFaqEditor
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'question_ar' => ['required', 'string', 'max:150'],
            'question_en' => ['nullable', 'string', 'max:150'],
            'answer_ar' => ['nullable', 'string', 'max:1000'],
            'answer_en' => ['nullable', 'string', 'max:1000'],
            // لا SVG: يُخدم من نطاقنا ويحمل سكربتاً.
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, array<int, string>> */
    public function reorderRules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:' . WebChatFaqs::MAX],
            'ids.*' => ['integer'],
        ];
    }

    public function isFull(int $organizationId): bool
    {
        return WebChatFaq::where('organization_id', $organizationId)->count() >= WebChatFaqs::MAX;
    }

    /**
     * ما لا تقوله القواعد: حدّ العدد، وإجابةٌ نصّاً أو صورةً — سؤال بلا
     * إجابة لا يُفيد الزائر.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function check(array $input, bool $hasNewImage, ?WebChatFaq $faq, bool $full): void
    {
        $errors = [];

        if ($full) {
            $errors['question_ar'] = __('Up to :max frequently asked questions.', ['max' => WebChatFaqs::MAX]);
        }

        $removeImage = filter_var($input['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hasText = filled($input['answer_ar'] ?? null) || filled($input['answer_en'] ?? null);
        $keepsImage = $faq !== null && !$removeImage && WebChatFaqs::imageUrl($faq) !== null;

        if (!$hasText && !$hasNewImage && !$keepsImage) {
            $errors['answer_ar'] = __('Write an answer or add an image.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param  array<string, mixed>  $data  ما مرّ بالقواعد. */
    public function create(int $organizationId, array $data, ?UploadedFile $image, ?int $userId): WebChatFaq
    {
        $faq = new WebChatFaq([
            'organization_id' => $organizationId,
            'position' => (int) WebChatFaq::where('organization_id', $organizationId)->max('position') + 1,
            'created_by' => $userId,
        ]);
        $faq->fill($this->attributes($data, null));

        if ($image) {
            $faq->fill(WebChatFaqs::storeImage($image, $organizationId));
        }

        $faq->save();
        WebChatFaqs::forget($organizationId);

        return $faq;
    }

    /** @param  array<string, mixed>  $data  ما مرّ بالقواعد. */
    public function update(WebChatFaq $faq, array $data, ?UploadedFile $image, bool $removeImage): WebChatFaq
    {
        $faq->fill($this->attributes($data, $faq));

        // صورة جديدة تحلّ محلّ القديمة، و«إزالة» تحذفها — والملف المحلّي معها.
        if ($image || $removeImage) {
            WebChatFaqs::deleteImage($faq);
            $faq->fill(['image_path' => null, 'image_url' => null]);
        }

        if ($image) {
            $faq->fill(WebChatFaqs::storeImage($image, (int) $faq->organization_id));
        }

        $faq->save();
        WebChatFaqs::forget((int) $faq->organization_id);

        return $faq;
    }

    public function destroy(WebChatFaq $faq): void
    {
        WebChatFaqs::deleteImage($faq);
        $faq->delete();
        WebChatFaqs::forget((int) $faq->organization_id);
    }

    /**
     * الترتيب الجديد كما سحبه التاجر: معرّفات أسئلته بالترتيب.
     *
     * @param  array<int, int>  $ids
     */
    public function reorder(int $organizationId, array $ids): void
    {
        DB::transaction(function () use ($organizationId, $ids) {
            foreach (array_values($ids) as $position => $id) {
                // أسئلة المنشأة وحدها: معرّفٌ من غيرها لا يُمسّ.
                WebChatFaq::where('organization_id', $organizationId)->whereKey($id)->update(['position' => $position + 1]);
            }
        });

        WebChatFaqs::forget($organizationId);
    }

    /** is_active الغائب: الجديد مفعّل، والمعدَّل يبقى كما كان. */
    private function attributes(array $data, ?WebChatFaq $faq): array
    {
        $clean = static fn ($value) => ($value = trim((string) $value)) === '' ? null : $value;

        return [
            'question_ar' => trim((string) $data['question_ar']),
            'question_en' => $clean($data['question_en'] ?? null),
            'answer_ar' => $clean($data['answer_ar'] ?? null),
            'answer_en' => $clean($data['answer_en'] ?? null),
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : ($faq?->is_active ?? true),
        ];
    }
}
