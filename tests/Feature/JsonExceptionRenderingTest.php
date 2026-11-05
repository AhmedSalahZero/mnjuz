<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * أخطاء طلبات JSON (axios في الويب، وتطبيق الجوال).
 *
 * كان المعالج يحوّل كل استثناء في طلب JSON إلى 500 «An error occurred» —
 * خطأ التحقّق والسجلّ غير الموجود معاً. فيرى المستخدم «حدث خطأ» ولا يعرف
 * أيّ حقل أخطأ، وفي الإنتاج (debug مطفأ) يضيع السبب من الردّ كلّه.
 */
class JsonExceptionRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/__json-test/validate', function (Request $request) {
            $request->validate(['name' => 'required|string', 'age' => 'required|integer']);

            return response()->json(['ok' => true]);
        });

        Route::get('/__json-test/missing', function () {
            return User::query()->whereKey(-1)->firstOrFail();
        });

        Route::get('/__json-test/boom', function () {
            throw new \RuntimeException('database exploded');
        });

        Route::middleware('web')->post('/__json-test/web-validate', function (Request $request) {
            $request->validate(['name' => 'required']);

            return 'ok';
        });
    }

    public function test_a_validation_error_is_422_with_the_failing_fields(): void
    {
        $this->postJson('/__json-test/validate', ['age' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'age'])
            ->assertJsonPath('errors.name.0', __('validation.required', ['attribute' => 'name']));
    }

    public function test_a_missing_record_is_404_not_500(): void
    {
        $this->getJson('/__json-test/missing')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_an_unexpected_error_keeps_the_500_envelope(): void
    {
        $this->getJson('/__json-test/boom')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('An error occurred'));
    }

    public function test_a_browser_form_still_redirects_back_with_errors(): void
    {
        $this->from('/somewhere')
            ->post('/__json-test/web-validate', [])
            ->assertRedirect('/somewhere')
            ->assertSessionHasErrors('name');
    }
}
