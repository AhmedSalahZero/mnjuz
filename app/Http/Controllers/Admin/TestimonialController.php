<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreTestimonial;
use App\Models\Review;
use App\Services\TestimonialService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule; 
use Inertia\Inertia;
use Helper;
use Session;
use Validator;

class TestimonialController extends BaseController
{
    public function __construct(TestimonialService $testimonialService)
    {
        $this->testimonialService = $testimonialService;
    }

    public function index(Request $request){
        return Inertia::render('Admin/Testimonial/Index', [
            'title' => __('Reviews'),
            'rows' => $this->testimonialService->get($request), 
            'filters' => $request->all()
        ]);
    }

    public function store(StoreTestimonial $request)
    {
        $this->testimonialService->store($request);

        return redirect('/admin/testimonials')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Review added successfully!')
            ]
        );
    }

    public function show($id)
    {
        $row = Review::where('id', $id)->first();
        return response()->json(['success' => true, 'item'=> $row]);
    }

    public function update(StoreTestimonial $request, $id)
    {
        $this->testimonialService->store($request, $id);

        return redirect('/admin/testimonials')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Review updated successfully!')
            ]
        );
    }

    public function destroy($id)
    {
        $this->testimonialService->delete($id);

        return redirect('/admin/testimonials')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Review deleted successfully!')
            ]
        );
    }
}