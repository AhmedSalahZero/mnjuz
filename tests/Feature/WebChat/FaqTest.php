<?php

namespace Tests\Feature\WebChat;

use App\Models\Addon;
use App\Models\Chat;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Models\WebChatFaq;
use App\Services\ChatService;
use App\Support\OrganizationRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * الأسئلة الشائعة: يكتبها التاجر، ويقرؤها الزائر في الـ widget بلا محادثة،
 * وأوّل رسالة منه تحمل ما قرأه ليراه الموظّف.
 */
class FaqTest extends WebChatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Setting::updateOrCreate(['key' => 'storage_system'], ['value' => 'local']);
        Addon::factory()->create(['name' => 'Google Authenticator']);
    }

    // ------------------------------------------------- ما يصل الـ widget

    public function test_the_widget_gets_this_organizations_active_faqs_in_order(): void
    {
        $second = $this->faq(['question_ar' => 'مواعيد العمل؟', 'position' => 2]);
        $first = $this->faq(['question_ar' => 'كم مدة التوصيل؟', 'question_en' => 'Delivery time?', 'answer_ar' => 'يومان', 'position' => 1]);
        $this->faq(['question_ar' => 'مخفي', 'is_active' => false]);
        $this->faq(['question_ar' => 'منشأة أخرى', 'organization_id' => Organization::factory()->create()->id]);

        $response = $this->getJson($this->url('faqs'))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertHeader('Vary', 'Origin');

        $this->assertSame([$first->id, $second->id], array_column($response->json('faqs'), 'id'));
        $this->assertSame(
            ['id' => $first->id, 'question_ar' => 'كم مدة التوصيل؟', 'question_en' => 'Delivery time?', 'answer_ar' => 'يومان', 'answer_en' => null, 'image_url' => null],
            $response->json('faqs.0')
        );
        $this->getJson($this->url('config'))->assertJsonPath('faqs', 2);
    }

    public function test_reading_faqs_opens_no_conversation(): void
    {
        $this->faq();
        [, $visitor] = $this->openSession();

        $this->getJson($this->url('faqs'))->assertOk();

        $this->assertSame(0, Chat::where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, ChatTicket::count());
        $this->assertNull($visitor->fresh()->journey);
    }

    // ------------------------------------------------- ما فعله الزائر

    /** أوّل رسالة تحمل ما قرأه: يُحفظ مرّة واحدة، وبنصّ الخادم لا بنصّ المتصفّح. */
    public function test_the_first_message_records_the_journey_once(): void
    {
        $delivery = $this->faq(['question_ar' => 'كم مدة التوصيل؟']);
        $hours = $this->faq(['question_ar' => 'مواعيد العمل؟']);
        $foreign = $this->faq(['question_ar' => 'ليس لنا', 'organization_id' => Organization::factory()->create()->id]);
        [$token, $visitor] = $this->openSession();

        $this->send($token, ['text' => 'لم أجد جوابي', 'context' => [
            'menu' => 'question', 'faqs' => [$delivery->id, $foreign->id, $hours->id], 'not_found' => true,
        ]])->assertStatus(202);
        $this->send($token, ['text' => 'ثانية', 'context' => ['menu' => 'problem', 'faqs' => [], 'not_found' => false]])->assertStatus(202);

        $this->assertSame([
            'menu' => 'question',
            'faqs_viewed' => [
                ['id' => $delivery->id, 'question' => 'كم مدة التوصيل؟'],
                ['id' => $hours->id, 'question' => 'مواعيد العمل؟'],
            ],
            'faq_not_found' => true,
        ], $visitor->fresh()->journey);
    }

    /** ملفٌّ أوّلاً: الحقول نفسها في الـ multipart (FormData). */
    public function test_a_file_as_the_first_message_records_the_journey(): void
    {
        $faq = $this->faq(['question_ar' => 'كم مدة التوصيل؟']);
        [$token, $visitor] = $this->openSession();

        $this->post($this->url('uploads'), [
            'client_message_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->image('a.png', 10, 10),
            'context' => ['menu' => 'question', 'faqs' => [(string) $faq->id], 'not_found' => '1'],
        ], ['X-Webchat-Token' => $token, 'Accept' => 'application/json'])->assertStatus(202);

        $this->assertSame([
            'menu' => 'question',
            'faqs_viewed' => [['id' => $faq->id, 'question' => 'كم مدة التوصيل؟']],
            'faq_not_found' => true,
        ], $visitor->fresh()->journey);
    }

    public function test_a_bad_context_is_refused(): void
    {
        [$token] = $this->openSession();

        $this->send($token, ['text' => 'مرحبا', 'context' => ['menu' => 'hack']])->assertStatus(422);
    }

    public function test_the_agent_sees_the_journey_on_the_visitor_card(): void
    {
        $faq = $this->faq(['question_ar' => 'كم مدة التوصيل؟']);
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'مرحبا', 'context' => ['menu' => 'question', 'faqs' => [$faq->id], 'not_found' => true]]);

        $payload = ChatService::contactPayloadForChatView(Contact::find($visitor->fresh()->contact_id));

        $this->assertSame('question', $payload['webchat_visitor']['journey']['menu']);
        $this->assertSame('كم مدة التوصيل؟', $payload['webchat_visitor']['journey']['faqs_viewed'][0]['question']);
    }

    // ------------------------------------------------- صفحة الإعدادات

    public function test_the_merchant_adds_a_faq_with_an_image_and_the_widget_sees_it(): void
    {
        $this->actAsOwner();
        $this->getJson($this->url('faqs'))->assertJsonCount(0, 'faqs');

        $this->post('/settings/webchat/faqs', [
            'question_ar' => 'ما طرق الدفع؟',
            'question_en' => 'Payment methods?',
            'answer_ar' => 'مدى وفيزا',
            'image' => UploadedFile::fake()->image('cards.png', 200, 100),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $faq = WebChatFaq::sole();
        Storage::disk('local')->assertExists($faq->image_path);
        $this->assertStringStartsWith('public/webchat-faqs/' . $this->organization->id . '/', $faq->image_path);

        $widgetFaq = $this->getJson($this->url('faqs'))->json('faqs.0');
        $this->assertSame('ما طرق الدفع؟', $widgetFaq['question_ar']);
        $this->assertStringContainsString('/media/', (string) $widgetFaq['image_url']);
    }

    public function test_an_edit_reaches_the_widget_at_once(): void
    {
        $this->actAsOwner();
        $faq = $this->faq(['question_ar' => 'قديم', 'answer_ar' => 'قديم']);
        $this->getJson($this->url('faqs'))->assertJsonPath('faqs.0.question_ar', 'قديم');

        $this->post("/settings/webchat/faqs/{$faq->id}", ['question_ar' => 'جديد', 'answer_ar' => 'جديد'])->assertSessionHasNoErrors();

        $this->getJson($this->url('faqs'))->assertJsonPath('faqs.0.question_ar', 'جديد');
    }

    public function test_a_faq_needs_an_answer_or_an_image_and_no_svg(): void
    {
        $this->actAsOwner();

        $this->post('/settings/webchat/faqs', ['question_ar' => 'بلا إجابة'])->assertSessionHasErrors('answer_ar');
        $this->post('/settings/webchat/faqs', [
            'question_ar' => 'صورة خطرة',
            'image' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml'),
        ])->assertSessionHasErrors('image');
        $this->post('/settings/webchat/faqs', ['answer_ar' => 'بلا سؤال'])->assertSessionHasErrors('question_ar');

        $this->assertSame(0, WebChatFaq::count());
    }

    public function test_at_most_thirty_faqs(): void
    {
        $this->actAsOwner();
        foreach (range(1, 30) as $i) {
            $this->faq(['question_ar' => "سؤال $i"]);
        }

        $this->post('/settings/webchat/faqs', ['question_ar' => 'الحادي والثلاثون', 'answer_ar' => 'x'])->assertSessionHasErrors('question_ar');
    }

    public function test_removing_the_image_or_the_faq_deletes_the_file(): void
    {
        $this->actAsOwner();
        $this->post('/settings/webchat/faqs', ['question_ar' => 'س', 'answer_ar' => 'ج', 'image' => UploadedFile::fake()->image('a.png')]);
        $faq = WebChatFaq::sole();
        $path = $faq->image_path;

        $this->post("/settings/webchat/faqs/{$faq->id}", ['question_ar' => 'س', 'answer_ar' => 'ج', 'remove_image' => true])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($faq->fresh()->image_path);

        $this->post("/settings/webchat/faqs/{$faq->id}", ['question_ar' => 'س', 'answer_ar' => 'ج', 'image' => UploadedFile::fake()->image('b.png')]);
        $path = $faq->fresh()->image_path;
        $this->delete("/settings/webchat/faqs/{$faq->id}")->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, WebChatFaq::count());
    }

    /** تعديلٌ بلا حالة التفعيل لا يُعيد سؤالاً معطّلاً للظهور. */
    public function test_an_edit_without_the_active_flag_keeps_it(): void
    {
        $this->actAsOwner();
        $faq = $this->faq(['is_active' => false]);

        $this->post("/settings/webchat/faqs/{$faq->id}", ['question_ar' => 'معدّل', 'answer_ar' => 'ج'])->assertSessionHasNoErrors();

        $this->assertFalse($faq->fresh()->is_active);
    }

    public function test_reordering_follows_the_merchant_and_ignores_foreign_ids(): void
    {
        $this->actAsOwner();
        $a = $this->faq(['position' => 1]);
        $b = $this->faq(['position' => 2]);
        $foreign = $this->faq(['organization_id' => Organization::factory()->create()->id, 'position' => 9]);

        $this->post('/settings/webchat/faqs/reorder', ['ids' => [$b->id, $foreign->id, $a->id]])->assertRedirect();

        $this->assertSame([1, 3], [$b->fresh()->position, $a->fresh()->position]);
        $this->assertSame(9, $foreign->fresh()->position);
    }

    public function test_another_organizations_faq_cannot_be_touched_and_agents_are_refused(): void
    {
        $foreign = $this->faq(['organization_id' => Organization::factory()->create()->id]);
        $this->actAsOwner();

        $this->post("/settings/webchat/faqs/{$foreign->id}", ['question_ar' => 'x', 'answer_ar' => 'y'])->assertNotFound();
        $this->delete("/settings/webchat/faqs/{$foreign->id}")->assertNotFound();

        $agent = User::factory()->create(['role' => 'user']);
        Team::create(['user_id' => $agent->id, 'organization_id' => $this->organization->id, 'role' => OrganizationRole::AGENT, 'status' => 'active', 'created_by' => $agent->id]);
        $this->actingAs($agent);

        $this->post('/settings/webchat/faqs', ['question_ar' => 'x', 'answer_ar' => 'y'])->assertForbidden();
    }

    public function test_the_settings_page_lists_the_faqs(): void
    {
        $this->actAsOwner();
        $this->faq(['question_ar' => 'مفعّل']);
        $this->faq(['question_ar' => 'معطّل', 'is_active' => false]);

        $response = $this->get('/settings/webchat')->assertOk();
        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $faqs = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props']['widget']['faqs'];

        $this->assertSame(['مفعّل', 'معطّل'], array_column($faqs, 'question_ar'));
        $this->assertSame([true, false], array_column($faqs, 'is_active'));
    }

    // ------------------------------------------------- أدوات

    private function faq(array $attributes = []): WebChatFaq
    {
        return WebChatFaq::create($attributes + [
            'organization_id' => $this->organization->id,
            'position' => WebChatFaq::count() + 1,
            'is_active' => true,
            'question_ar' => 'سؤال',
            'answer_ar' => 'إجابة',
        ]);
    }
}
