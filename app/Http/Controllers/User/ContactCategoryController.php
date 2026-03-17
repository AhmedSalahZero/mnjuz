<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreContactCategory;
use App\Models\ContactCategory;
use App\Http\Resources\ContactCategoryResource;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class ContactCategoryController extends BaseController
{
    private function getCurrentOrganizationId()
    {
        return session()->get('current_organization');
    }

    private function isFeatureEnabled(): bool
    {
        return SubscriptionService::isSubscriptionFeatureEnabled(
            (string) $this->getCurrentOrganizationId(),
            'contact_categories_enabled'
        );
    }

    public function index(Request $request, $uuid = null)
    {
        if (!$this->isFeatureEnabled()) {
            return redirect('/contacts')->with('status', [
                'type' => 'info',
                'message' => __('Contact Categories are not available in your plan.'),
            ]);
        }

        $organizationId = $this->getCurrentOrganizationId();
        $categoryModel = new ContactCategory;
        $searchTerm = $request->query('search');
        $uuid = $request->query('id') ?: $uuid;

        $rows = $categoryModel->getAll($organizationId, $searchTerm ?? '');
        $rowCount = $categoryModel->countAll($organizationId);
        $category = $uuid ? $categoryModel->getRow($uuid, $organizationId) : null;

        return Inertia::render('User/Contact/Category', [
            'title' => __('Contact Categories'),
            'rows' => ContactCategoryResource::collection($rows),
            'rowCount' => $rowCount,
            'category' => $category,
            'filters' => $request->all(),
            'contactCategoriesEnabled' => true,
        ]);
    }

    public function store(StoreContactCategory $request)
    {
        if (!$this->isFeatureEnabled()) {
            return response()->json(['success' => false, 'message' => __('Contact Categories are not available in your plan.')], 403);
        }

        $category = new ContactCategory();
        $category->organization_id = $this->getCurrentOrganizationId();
        $category->name = $request->name;
        $category->save();

        return response()->json([
            'success' => true,
            'message' => __('Contact category added successfully'),
            'data' => $category,
        ]);
    }

    public function update(StoreContactCategory $request, $uuid)
    {
        if (!$this->isFeatureEnabled()) {
            return response()->json(['success' => false, 'message' => __('Contact Categories are not available in your plan.')], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->messages()->get('*')]);
        }

        $category = ContactCategory::where('uuid', $uuid)
            ->where('organization_id', $this->getCurrentOrganizationId())
            ->firstOrFail();
        $category->name = $request->name;
        $category->save();

        return response()->json([
            'success' => true,
            'message' => __('Contact category updated successfully'),
            'data' => $category,
        ]);
    }

    public function delete(Request $request)
    {
        if (!$this->isFeatureEnabled()) {
            return redirect('/contacts')->with('status', ['type' => 'error', 'message' => __('Contact Categories are not available in your plan.')]);
        }

        $uuids = $request->input('uuids', []);
        $organizationId = $this->getCurrentOrganizationId();

        if (empty($uuids)) {
            ContactCategory::where('organization_id', $organizationId)->get()->each(function ($cat) {
                $cat->contacts()->detach();
            });
            ContactCategory::where('organization_id', $organizationId)->delete();
        } else {
            $categories = ContactCategory::whereIn('uuid', $uuids)->where('organization_id', $organizationId)->get();
            foreach ($categories as $cat) {
                $cat->contacts()->detach();
            }
            ContactCategory::whereIn('uuid', $uuids)->where('organization_id', $organizationId)->delete();
        }

        return redirect('/contact-categories')->with('status', [
            'type' => 'success',
            'message' => __('Category(ies) deleted successfully'),
        ]);
    }
}
