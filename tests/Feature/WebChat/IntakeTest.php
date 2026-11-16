<?php

namespace Tests\Feature\WebChat;

use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessWebChatInboundJob;
use App\Models\Chat;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\WebChatVisitor;
use App\Services\Messaging\ContactResolver;
use App\Services\WebChat\WebChatQuestions;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * أسئلة البداية من جهة الخادم: يحدّدها التاجر، وتُرسل الإجابات عند نهايتها،
 * وتصير جهة اتصال فوراً بكل حقولها — ولو لم يكتب الزائر رسالة.
 */
class IntakeTest extends WebChatTestCase
{
    // ------------------------------------------------- أيّ أسئلة تُسأل

    /** الـ widget الجديد: الاسم والبريد والجوال، إجبارية كلها. */
    public function test_a_new_widget_asks_name_email_and_phone(): void
    {
        $this->widget->delete();
        $this->widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر جديد');

        $questions = $this->getJson($this->url('config'))->assertOk()->json('widget.prechat.questions');

        $this->assertSame(['name', 'email', 'phone'], array_column($questions, 'id'));
        $this->assertSame([true, true, true], array_column($questions, 'required'));
    }

    /** الـ widget القائم يسأل ما كان يسأله: من إعداد نموذجه القديم. */
    public function test_an_existing_widget_keeps_asking_what_it_asked(): void
    {
        $this->setSettings(['prechat' => ['phone' => 'optional', 'email' => 'required', 'phone_label' => 'جوالك']]);

        $questions = $this->getJson($this->url('config'))->json('widget.prechat.questions');

        $this->assertSame(['name', 'phone', 'email'], array_column($questions, 'id'));
        $this->assertSame([true, false, true], array_column($questions, 'required'));
        $this->assertSame('جوالك', $questions[1]['label_ar']);

        $this->setSettings(['prechat' => ['phone' => 'off']]);
        $this->assertSame(['name'], array_column($this->getJson($this->url('config'))->json('widget.prechat.questions'), 'id'));
    }

    public function test_a_custom_field_question_carries_its_field(): void
    {
        $city = $this->field('المدينة', 'select', 'الرياض, جدة');
        $nationalId = $this->field('رقم الهوية', 'input', 'text');
        $gone = $this->field('حقل محذوف', 'input', 'text');
        $gone->update(['deleted_at' => now()]);
        $agree = $this->field('موافق', 'checkbox', null);

        $this->setQuestions([
            ['target' => 'name', 'required' => true],
            ['target' => 'field', 'field' => $city->uuid, 'required' => true],
            ['target' => 'field', 'field' => $nationalId->uuid, 'validation' => 'saudi_id', 'required' => false, 'label_ar' => 'رقم هويتك؟'],
            ['target' => 'field', 'field' => $gone->uuid, 'required' => true],
            ['target' => 'field', 'field' => $agree->uuid, 'required' => true],
        ]);

        $questions = $this->getJson($this->url('config'))->json('widget.prechat.questions');

        $this->assertSame(['name', 'f_' . $city->uuid, 'f_' . $nationalId->uuid], array_column($questions, 'id'), 'deleted and checkbox fields are not asked');
        $this->assertSame('select', $questions[1]['validation']);
        $this->assertSame(['الرياض', 'جدة'], $questions[1]['options']);
        $this->assertSame('المدينة', $questions[1]['label_ar'], 'the field name is the fallback label');
        $this->assertSame('saudi_id', $questions[2]['validation']);
        $this->assertSame('رقم هويتك؟', $questions[2]['label_ar']);
        $this->assertArrayNotHasKey('field', $questions[1], 'the field uuid stays on the server');
    }

    /** حقل واحد لسؤال واحد، وحدٌّ لعدد الأسئلة، وما ليس هدفاً معروفاً يُترك. */
    public function test_saved_questions_are_normalized(): void
    {
        $normalized = WebChatQuestions::normalize([
            ['target' => 'name', 'required' => 1],
            ['target' => 'name', 'required' => false],
            ['target' => 'national_id'],
            ['target' => 'email', 'validation' => 'number'],
            'not a question',
        ]);

        $this->assertSame(['name', 'email'], array_column($normalized, 'id'));
        $this->assertTrue($normalized[0]['required']);
        $this->assertSame('email', $normalized[1]['validation'], 'the email question is always an email');
        $this->assertNull(WebChatQuestions::normalize('not a list'));
    }

    // ------------------------------------------------- الإجابات تصير جهة اتصال

    public function test_finishing_the_questions_creates_the_contact_with_every_answer(): void
    {
        $city = $this->field('المدينة', 'select', 'الرياض, جدة');
        $nationalId = $this->field('رقم الهوية', 'input', 'text');
        $this->setQuestions([
            ['target' => 'name', 'required' => true],
            ['target' => 'email', 'required' => true],
            ['target' => 'phone', 'required' => true],
            ['target' => 'field', 'field' => $city->uuid, 'required' => true],
            ['target' => 'field', 'field' => $nationalId->uuid, 'validation' => 'saudi_id', 'required' => true],
        ]);

        $response = $this->postJson($this->url('sessions'), $this->intake([
            'name' => 'سارة العتيبي',
            'email' => 'Sara@Example.com',
            'phone' => '٠٥٠١٢٣٤٥٦٧',
            'f_' . $city->uuid => 'جدة',
            'f_' . $nationalId->uuid => '١٠٠٠٠٠٠٠٠٨',
        ]))->assertCreated();

        $visitor = WebChatVisitor::where('uuid', $response->json('visitor.id'))->sole();
        $contact = Contact::findOrFail($visitor->contact_id);

        $this->assertSame(['سارة', 'العتيبي'], [$contact->first_name, $contact->last_name]);
        $this->assertNull($contact->phone, 'the WhatsApp identity column stays empty');
        $this->assertSame('+966 50 123 4567', $contact->formatted_phone);
        $this->assertSame('sara@example.com', $contact->email);
        $metadata = json_decode($contact->metadata, true);
        $this->assertSame('جدة', $metadata['المدينة']);
        $this->assertSame('1000000008', $metadata['رقم الهوية']);
        $this->assertSame('+966501234567', $visitor->phone);

        $this->assertSame(0, Chat::where('contact_id', $contact->id)->count(), 'no conversation until the visitor writes');
        $this->assertSame(0, ChatTicket::where('contact_id', $contact->id)->count());
    }

    public function test_wrong_answers_come_back_by_question_in_the_widget_language(): void
    {
        $city = $this->field('المدينة', 'select', 'الرياض, جدة');
        $this->setQuestions([
            ['target' => 'name', 'required' => true],
            ['target' => 'phone', 'required' => true],
            ['target' => 'field', 'field' => $city->uuid, 'required' => true],
        ]);

        $response = $this->postJson($this->url('sessions') . '?lang=ar', $this->intake([
            'name' => 'سارة',
            'phone' => '0112345678',
            'f_' . $city->uuid => 'الدمام',
        ]), ['Accept-Language' => 'en-US,en;q=0.9'])->assertStatus(422);

        $this->assertSame(['phone', 'f_' . $city->uuid], array_keys($response->json('errors')));
        $this->assertSame('يبدو أنه رقم هاتف أرضي. أدخل رقم جوال.', $response->json('errors.phone.0'));
        $this->assertSame(0, WebChatVisitor::count());
        $this->assertSame(0, Contact::where('channel', 'webchat')->count());
    }

    public function test_skipped_optional_questions_and_unknown_answers_are_fine(): void
    {
        $this->setQuestions([
            ['target' => 'name', 'required' => true],
            ['target' => 'email', 'required' => false],
        ]);

        $response = $this->postJson($this->url('sessions'), $this->intake([
            'name' => 'سارة',
            'email' => '',
            'national_id' => '1000000008',
        ]))->assertCreated();

        $visitor = WebChatVisitor::where('uuid', $response->json('visitor.id'))->sole();
        $this->assertNull($visitor->email);
        $this->assertNull(json_decode((string) Contact::find($visitor->contact_id)->metadata, true)['national_id'] ?? null);
    }

    /** بلا أسئلة أو بلا إجابة: لا جهة اتصال فارغة. */
    public function test_a_session_without_any_answer_creates_no_contact(): void
    {
        $this->setQuestions([]);

        $response = $this->postJson($this->url('sessions'), $this->intake([]))->assertCreated();

        $this->assertNull(WebChatVisitor::where('uuid', $response->json('visitor.id'))->value('contact_id'));
        $this->assertSame(0, Contact::where('channel', 'webchat')->count());
    }

    // ------------------------------------------------- أوّل رسالة

    /**
     * الجهة أُنشئت عند نهاية الأسئلة، فأوّل رسالة تلحق بها — ومسار الترحيب
     * (new_contact) يعاملها جديدة: هي أوّل محادثة.
     */
    public function test_the_first_message_joins_the_same_contact_as_a_new_one(): void
    {
        $this->setQuestions([['target' => 'name', 'required' => true], ['target' => 'phone', 'required' => true]]);
        $token = $this->postJson($this->url('sessions'), $this->intake(['name' => 'سارة', 'phone' => '0501234567']))
            ->assertCreated()->json('token');

        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);
        $this->send($token, ['text' => 'هل أنتم متاحون؟'])->assertStatus(202);

        $this->assertSame(1, Contact::where('channel', 'webchat')->count());
        $flags = [];
        Bus::assertDispatched(ProcessAutoReplyJob::class, function (ProcessAutoReplyJob $job) use (&$flags) {
            $flags[] = (fn () => $this->isNewContact)->call($job);

            return true;
        });
        $this->assertSame([true, false], $flags);
    }

    /** فشل إنشاء الجهة لا يُسقط الجلسة: أوّل رسالة تُنشئها كما كانت. */
    public function test_a_failed_capture_still_opens_the_session(): void
    {
        $this->setQuestions([['target' => 'name', 'required' => true]]);
        $this->app->bind(ContactResolver::class, fn () => new class extends ContactResolver {
            public function resolveProfile(...$arguments): array
            {
                throw new \RuntimeException('database hiccup');
            }
        });

        $token = $this->postJson($this->url('sessions'), $this->intake(['name' => 'سارة']))->assertCreated()->json('token');
        $this->assertSame(0, Contact::where('channel', 'webchat')->count());

        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);
        $this->assertSame(1, Contact::where('channel', 'webchat')->count());
    }

    /** إجابات الأسئلة (رقم الهوية مثلاً) لا تذهب إلى webhook التاجر. */
    public function test_the_merchant_webhook_payload_is_unchanged(): void
    {
        Bus::fake([ProcessWebChatInboundJob::class, ProcessAutoReplyJob::class]);
        $nationalId = $this->field('رقم الهوية', 'input', 'text');
        $this->setQuestions([
            ['target' => 'name', 'required' => true],
            ['target' => 'field', 'field' => $nationalId->uuid, 'validation' => 'saudi_id', 'required' => true],
        ]);
        $token = $this->postJson($this->url('sessions'), $this->intake([
            'name' => 'سارة', 'f_' . $nationalId->uuid => '1000000008',
        ]))->json('token');

        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);

        Bus::assertDispatched(ProcessWebChatInboundJob::class, function (ProcessWebChatInboundJob $job) {
            $raw = (fn () => $this->payload)->call($job)['raw'];

            return array_keys($raw['visitor']) === ['id', 'name']
                && !str_contains(json_encode($raw), '1000000008');
        });
    }

    // ------------------------------------------------- أدوات

    /** ما يرسله الـ widget عند نهاية الأسئلة. */
    private function intake(array $answers, array $countries = []): array
    {
        return [
            'answers' => $answers,
            'countries' => $countries,
            'elapsed_ms' => 4000,
            'page_url' => 'https://shop.example.com/',
        ];
    }

    private function setQuestions(array $questions): void
    {
        $this->setSettings(['prechat' => ['questions' => $questions]]);
        $this->assertNotNull(WebChatSettings::for($this->widget->fresh())['prechat']['questions']);
    }

    private function field(string $name, string $type, ?string $value): ContactField
    {
        return ContactField::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'position' => ContactField::count() + 1,
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'required' => 0,
        ]);
    }
}
