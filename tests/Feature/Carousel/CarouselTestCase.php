<?php

namespace Tests\Feature\Carousel;

use App\Models\Addon;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\Template;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * أساس اختبارات الـ carousel: منشأة باعتماد واتساب واشتراك، وجهة اتصال،
 * وتخزين محلّي وهمي، وعميل Graph وهمي يردّ بحسب المسار ويسجّل كل طلب.
 *
 * اعتماد واتساب: app_id=1، phone_number_id=2، waba_id=3.
 */
abstract class CarouselTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Organization $organization;
    protected Contact $contact;

    /** @var array<int, array{request: RequestInterface}> */
    protected array $graphRequests = [];

    /** @var array<string, callable(RequestInterface): Response> ردود مخصّصة بحسب جزء من المسار */
    protected array $graphOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'metadata' => json_encode(['whatsapp' => [
                'access_token' => 't', 'app_id' => '1', 'phone_number_id' => '2', 'waba_id' => '3',
            ]]),
        ]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $plan = SubscriptionPlan::create([
            'name' => 'P',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['campaign_limit' => -1, 'message_limit' => -1]),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Sara',
            'last_name' => 'Ali',
            'phone' => '+966500000001',
            'created_by' => $this->owner->id,
        ]);

        Storage::fake('local');
        $this->fakeGraph();
        session(['current_organization' => $this->organization->id]);
    }

    /**
     * Graph وهمي: جلسة الرفع ← upload:Sn، ورفع الملف ← 4::Hn، وإنشاء القالب
     * ← T1، وإرسال الرسالة ← wamid فريد. وما في graphOverrides يتقدّم.
     */
    protected function fakeGraph(): void
    {
        $this->graphRequests = [];
        $counter = 0;

        $stack = HandlerStack::create(function (RequestInterface $request) use (&$counter) {
            $counter++;
            $path = $request->getUri()->getPath();

            foreach ($this->graphOverrides as $fragment => $responder) {
                if (str_contains($path, $fragment)) {
                    return Create::promiseFor($responder($request));
                }
            }

            if (str_ends_with($path, '/1/uploads')) {
                return Create::promiseFor($this->graphResponse(['id' => 'upload:S' . $counter]));
            }

            if (str_contains($path, '/upload:S')) {
                return Create::promiseFor($this->graphResponse(['h' => '4::H' . $counter]));
            }

            if (str_ends_with($path, '/3/message_templates')) {
                return Create::promiseFor($this->graphResponse(['id' => 'T1', 'status' => 'PENDING', 'category' => 'MARKETING']));
            }

            if (str_ends_with($path, '/2/messages')) {
                return Create::promiseFor($this->graphResponse(['messages' => [['id' => 'wamid.CAR-' . Str::random(8) . '-' . $counter]]]));
            }

            return Create::promiseFor($this->graphResponse(['success' => true]));
        });
        $stack->push(Middleware::history($this->graphRequests));

        $this->app->bind(Client::class, fn () => new Client(['handler' => $stack]));
    }

    protected function graphResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return array<int, RequestInterface> الطلبات التي ينتهي مسارها بـ $suffix */
    protected function graphCalls(string $suffix): array
    {
        return array_values(array_map(
            fn ($entry) => $entry['request'],
            array_filter($this->graphRequests, fn ($entry) => str_ends_with($entry['request']->getUri()->getPath(), $suffix))
        ));
    }

    protected function graphJson(RequestInterface $request): array
    {
        return json_decode((string) $request->getBody(), true) ?? [];
    }

    /** حمولة قالب carousel كما ينشئها النظام: بطاقتان، متغيّرات، زرّا QR وURL. */
    protected function carouselPayload(string $name = 'shoes', string $format = 'IMAGE'): array
    {
        $card = fn (string $handle, string $text, string $example) => ['components' => [
            ['type' => 'HEADER', 'format' => $format, 'example' => ['header_handle' => [$handle]]],
            ['type' => 'BODY', 'text' => $text, 'example' => ['body_text' => [[$example]]]],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                ['type' => 'URL', 'text' => 'Open', 'url' => 'https://shop.test/p/{{1}}', 'example' => [$example]],
            ]],
        ]];

        return [
            'name' => $name,
            'language' => 'ar',
            'category' => 'MARKETING',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}', 'example' => ['body_text' => [['Sara']]]],
                ['type' => 'CAROUSEL', 'cards' => [
                    $card('4::a', 'Shoe {{1}}', 'red'),
                    $card('4::b', 'Bag {{1}}', 'blue'),
                ]],
            ],
        ];
    }

    protected function makeCarouselTemplate(bool $withMedia = true, string $format = 'IMAGE'): Template
    {
        $extension = $format === 'VIDEO' ? 'mp4' : 'jpg';

        return Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => 'shoes',
            'language' => 'ar',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'metadata' => json_encode($this->carouselPayload('shoes', $format)),
            'carousel_media' => $withMedia ? json_encode(['format' => $format, 'cards' => [
                ['url' => "https://app.test/media/public/one.$extension", 'location' => 'local', 'chat_media_id' => null, 'name' => "one.$extension", 'mime' => 'x', 'size' => 1],
                ['url' => "https://app.test/media/public/two.$extension", 'location' => 'local', 'chat_media_id' => null, 'name' => "two.$extension", 'mime' => 'x', 'size' => 1],
            ]]) : null,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function makeStandardTemplate(): Template
    {
        return Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => 'standard_promo',
            'language' => 'ar',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'metadata' => json_encode([
                'name' => 'standard_promo',
                'language' => 'ar',
                'category' => 'MARKETING',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hello {{1}}', 'example' => ['header_text' => ['Sara']]],
                    ['type' => 'BODY', 'text' => 'Your code is {{1}}', 'example' => ['body_text' => [['SAVE']]]],
                    ['type' => 'FOOTER', 'text' => 'Mnjz'],
                    ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes']]],
                ],
            ]),
            'created_by' => $this->owner->id,
        ]);
    }

    /** حالة البطاقات كما يرسلها نموذج الإرسال، مع اختيار وسائط كل بطاقة. */
    protected function carouselSendInput(array $headers, string $format = 'IMAGE'): array
    {
        $cards = [];
        foreach ($headers as $index => $header) {
            $cards[] = [
                'header' => $header,
                'body' => ['parameters' => [['type' => 'text', 'selection' => 'static', 'value' => $index === 0 ? 'red' : 'blue']]],
                'buttons' => [
                    ['index' => 0, 'type' => 'QUICK_REPLY', 'parameters' => [['selection' => 'static', 'value' => $index === 0 ? 'card1_buy' : '']]],
                    ['index' => 1, 'type' => 'URL', 'parameters' => [['selection' => 'static', 'value' => $index === 0 ? 'sku-1' : 'sku-2']]],
                ],
            ];
        }

        return ['format' => $format, 'cards' => $cards];
    }

    protected function image(string $name = 'card.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 315);
    }
}
