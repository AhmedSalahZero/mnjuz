<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Controllers\User\Concerns\AuthorizesWebChatSettings;
use App\Models\WebChatFaq;
use App\Services\WebChat\WebChatFaqEditor;
use Illuminate\Http\Request;

/**
 * الأسئلة الشائعة في صفحة إعدادات المحادثة المباشرة: إضافة وتعديل وحذف
 * وترتيب. القواعد والحفظ في WebChatFaqEditor — التطبيق يمرّ منها كذلك.
 */
class WebChatFaqController extends Controller
{
    use AuthorizesWebChatSettings;

    public function __construct(private readonly WebChatFaqEditor $faqs)
    {
    }

    public function store(Request $request)
    {
        $organizationId = $this->webChatOrganizationId();

        $data = $this->validated($request, null, $this->faqs->isFull($organizationId));
        $this->faqs->create($organizationId, $data, $request->file('image'), auth()->id());

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    public function update(Request $request, int $id)
    {
        $organizationId = $this->webChatOrganizationId();
        $faq = WebChatFaq::where('organization_id', $organizationId)->findOrFail($id);

        $data = $this->validated($request, $faq);
        $this->faqs->update($faq, $data, $request->file('image'), $request->boolean('remove_image'));

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    public function destroy(int $id)
    {
        $organizationId = $this->webChatOrganizationId();
        $faq = WebChatFaq::where('organization_id', $organizationId)->findOrFail($id);

        $this->faqs->destroy($faq);

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    /** الترتيب الجديد كما سحبه التاجر: معرّفات أسئلته بالترتيب. */
    public function reorder(Request $request)
    {
        $organizationId = $this->webChatOrganizationId();
        $ids = $request->validate($this->faqs->reorderRules())['ids'];

        $this->faqs->reorder($organizationId, $ids);

        return back()->with('status', ['type' => 'success', 'message' => __('Settings updated successfully')]);
    }

    private function validated(Request $request, ?WebChatFaq $faq, bool $full = false): array
    {
        $data = $request->validate($this->faqs->rules());

        $this->faqs->check($request->all(), $request->hasFile('image'), $faq, $full);

        return $data;
    }
}
