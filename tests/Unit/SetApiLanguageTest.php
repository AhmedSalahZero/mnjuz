<?php

namespace Tests\Unit;

use App\Http\Middleware\SetApiLanguage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * لغة ردود الـ API. المتصفّح يرسل Accept-Language دائماً («en-US,en;q=0.9»)،
 * والوسيط كان يقرؤه قبل ?lang= فلا يصل ?lang= أبداً — وأخطاء الـ widget
 * تعود بالإنجليزية حتى للزائر الذي يقرأ بالعربية.
 */
class SetApiLanguageTest extends TestCase
{
    private function localeFor(array $query, ?string $header): string
    {
        // في الرابط لا في الجسم: الـ widget يضيف ?lang= إلى عنوان الطلب.
        $request = Request::create('/api/webchat/v1/w/key/sessions' . ($query ? '?' . http_build_query($query) : ''), 'POST');
        $header === null
            ? $request->headers->remove('Accept-Language')
            : $request->headers->set('Accept-Language', $header);

        (new SetApiLanguage())->handle($request, fn () => response('ok'));

        return App::getLocale();
    }

    public function test_the_lang_query_wins_over_the_browser_header(): void
    {
        $this->assertSame('ar', $this->localeFor(['lang' => 'ar'], 'en-US,en;q=0.9'));
        $this->assertSame('en', $this->localeFor(['lang' => 'en'], 'ar'));
    }

    /** بلا ?lang= كل شيء كما كان: الترويسة المطابقة تماماً وإلّا الافتراضية. */
    public function test_without_the_query_nothing_changes(): void
    {
        $this->assertSame('ar', $this->localeFor([], 'ar'));
        $this->assertSame(config('app.locale'), $this->localeFor([], 'ar-SA,ar;q=0.9'));
        $this->assertSame(config('app.locale'), $this->localeFor([], null));
    }

    public function test_an_unsupported_query_falls_back_like_before(): void
    {
        $this->assertSame('ar', $this->localeFor(['lang' => 'fr'], 'ar'));
        $this->assertSame(config('app.locale'), $this->localeFor(['lang' => 'fr'], 'fr-FR'));
    }
}
