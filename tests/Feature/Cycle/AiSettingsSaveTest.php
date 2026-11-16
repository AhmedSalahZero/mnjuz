<?php

namespace Tests\Feature\Cycle;

use App\Models\Addon;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * حفظ إعدادات مساعد الذكاء الاصطناعي يجرّب المفتاح والموديل مع OpenAI.
 *
 * العطل: تاجرٌ اختار o1-mini (أوقفته OpenAI) فحُفظ بلا اعتراض، وصمت المساعد
 * على كل القنوات بلا أثر في أي مكان. الآن ما ترفضه OpenAI صراحةً لا يُحفظ
 * وخطؤها تحت الحقل، وما يتعذّر التحقّق منه يُحفظ مع تنبيه.
 */
class AiSettingsSaveTest extends CycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Addon::factory()->create(['name' => 'AI Assistant', 'status' => 1, 'is_active' => 1]);
        SubscriptionPlan::firstOrFail()->update(['metadata' => json_encode([
            'message_limit' => -1,
            'addons' => ['AI Assistant' => true],
        ])]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    private function save(array $overrides = [])
    {
        return $this->from('/automation/ai')->post('/automation/ai/setup', array_merge([
            'active' => true,
            'api_key' => 'sk-new-key',
            'model' => 'gpt-4o-mini',
            'voice' => 'alloy',
            'allow_audio_response' => false,
            'max_tokens' => null,
            'temperature' => null,
            'ai_chat_form_active' => false,
        ], $overrides));
    }

    private function savedAi(): array
    {
        return json_decode($this->organization->fresh()->metadata, true)['ai'] ?? [];
    }

    private function openAiChecks(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com/v1/chat/completions'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_a_working_key_and_model_are_checked_then_saved(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $this->save()->assertRedirect('/automation/ai')->assertSessionHasNoErrors();

        $this->assertSame('success', session('status')['type']);
        $this->assertSame('sk-new-key', $this->savedAi()['api_key']);
        $this->assertSame('gpt-4o-mini', $this->savedAi()['model']);

        $check = $this->openAiChecks();
        $this->assertCount(1, $check);
        $this->assertSame('Bearer sk-new-key', $check[0]->header('Authorization')[0]);
        $this->assertSame('gpt-4o-mini', $check[0]->data()['model']);
    }

    public function test_a_key_openai_rejects_is_not_saved(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => [
            'message' => 'Incorrect API key provided: sk-new-k***-key.',
            'code' => 'invalid_api_key',
        ]], 401)]);

        $this->save()->assertSessionHasErrors('api_key');

        $this->assertArrayNotHasKey('api_key', $this->savedAi());
        $message = session('errors')->first('api_key');
        $this->assertStringNotContainsString('sk-new', $message, 'the key leaked into the error');
    }

    public function test_a_model_the_account_cannot_use_is_not_saved(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => [
            'message' => 'The model `gpt-4o` does not exist or you do not have access to it.',
            'code' => 'model_not_found',
        ]], 404)]);

        $this->save(['model' => 'gpt-4o'])->assertSessionHasErrors('model');

        $this->assertArrayNotHasKey('model', $this->savedAi());
    }

    /** موديل أوقفته OpenAI مرفوض قبل أي طلب: لم يعد في القائمة. */
    public function test_a_retired_model_is_refused_without_calling_openai(): void
    {
        Http::fake();

        $this->save(['model' => 'o1-mini'])->assertSessionHasErrors('model');

        Http::assertNothingSent();
        $this->assertArrayNotHasKey('model', $this->savedAi());
    }

    /** خانة المفتاح الفارغة تُبقي المحفوظ — والتجربة بالمحفوظ لا بالفراغ. */
    public function test_a_blank_key_checks_and_keeps_the_stored_one(): void
    {
        $metadata = json_decode($this->organization->metadata, true);
        $metadata['ai'] = ['api_key' => 'sk-stored-key', 'model' => 'gpt-4o-mini'];
        $this->organization->update(['metadata' => json_encode($metadata)]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $this->save(['api_key' => ''])->assertSessionHasNoErrors();

        $this->assertSame('Bearer sk-stored-key', $this->openAiChecks()[0]->header('Authorization')[0]);
        $this->assertSame('sk-stored-key', $this->savedAi()['api_key']);
    }

    /** انقطاع الشبكة ليس رفضاً: الإعداد يُحفظ مع تنبيه. */
    public function test_an_unreachable_openai_still_saves_with_a_warning(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out');
        });

        $this->save()->assertSessionHasNoErrors();

        $this->assertSame('warning', session('status')['type']);
        $this->assertSame('sk-new-key', $this->savedAi()['api_key']);
    }

    /** رصيد نفد أو ضغط مؤقّت: الإعداد صحيح والعطل خارجه. */
    public function test_a_quota_problem_saves_with_a_warning(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => [
            'message' => 'You exceeded your current quota.',
            'code' => 'insufficient_quota',
        ]], 429)]);

        $this->save()->assertSessionHasNoErrors();

        $this->assertSame('warning', session('status')['type']);
        $this->assertStringContainsString('quota', session('status')['message']);
        $this->assertSame('sk-new-key', $this->savedAi()['api_key']);
    }

    /** مفتاح المنصّة لا يراه التاجر: خطؤه رسالة عامة، لا خطأ على حقل مخفي. */
    public function test_a_broken_platform_key_is_reported_as_a_message(): void
    {
        Setting::create(['key' => 'enable_api_key_input', 'value' => '0']);
        Setting::create(['key' => 'default_open_ai_key', 'value' => 'sk-platform-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key', 'code' => 'invalid_api_key']], 401)]);

        $this->save(['api_key' => null])->assertSessionHasNoErrors();

        $this->assertSame('error', session('status')['type']);
        $this->assertSame('Bearer sk-platform-key', $this->openAiChecks()[0]->header('Authorization')[0]);
        $this->assertArrayNotHasKey('model', $this->savedAi());
    }
}
