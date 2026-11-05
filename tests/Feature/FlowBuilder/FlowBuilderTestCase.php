<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\Addon;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Models\Flow;
use Tests\TestCase;

/**
 * أساس اختبارات منشئ المسارات: منشأة بمالكها واشتراكها ووحدة Flow builder
 * مفعّلة، وجهة اتصال، وعميل Graph وهمي يسجّل كل ما يُرسَل إلى واتساب.
 *
 * المسار يُبنى كما يحفظه المحرّر: كل حافة تحمل نسختي عقدتيها (sourceNode و
 * targetNode)، والمنفّذ يقرأ منهما لا من مصفوفة العقد.
 */
abstract class FlowBuilderTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Organization $organization;
    protected Contact $contact;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    protected array $graphRequests = [];

    /**
     * ترحيلات الوحدة قبل بدء المعاملة: DDL داخلها يُغلقها ضمناً في MySQL.
     */
    protected function refreshTestDatabase(): void
    {
        if (!RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[ConsoleKernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        if (!Schema::hasTable('flow_user_data') || !Schema::hasColumn('flow_user_data', 'unmatched_replies')) {
            $this->artisan('migrate', ['--path' => 'modules/FlowBuilder/Database/Migrations']);
            $this->app[ConsoleKernel::class]->setArtisan(null);
        }

        $this->beginDatabaseTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = $this->makeOrganization($this->owner);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Addon::factory()->create(['name' => 'Flow builder', 'status' => 1, 'is_active' => 1]);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Maitha',
            'phone' => '+966500000001',
            'created_by' => $this->owner->id,
        ]);

        $this->fakeGraph();
        session(['current_organization' => $this->organization->id]);
    }

    protected function makeOrganization(User $owner): Organization
    {
        $organization = Organization::factory()->create([
            'created_by' => $owner->id,
            'metadata' => json_encode(['whatsapp' => [
                'access_token' => 't', 'app_id' => '1', 'phone_number_id' => '2', 'waba_id' => '3',
            ]]),
        ]);

        Team::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $organization->id,
            'role' => 'owner',
            'created_by' => $owner->id,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'P',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['addons' => ['Flow builder' => true], 'message_limit' => -1]),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        return $organization;
    }

    /** عميل Graph يردّ بنجاح على كل طلب ويحفظه في graphRequests. */
    protected function fakeGraph(): void
    {
        $this->graphRequests = [];
        $counter = 0;

        $stack = HandlerStack::create(function () use (&$counter) {
            $counter++;

            return Create::promiseFor(new Response(200, [], json_encode([
                'messages' => [['id' => 'wamid.FLOW-' . $counter]],
            ])));
        });
        $stack->push(Middleware::history($this->graphRequests));

        $this->app->bind(Client::class, fn () => new Client(['handler' => $stack]));
    }

    /**
     * عميل Graph يردّ على طلبات إرسال الرسائل بالترتيب المعطى.
     *
     * كل عنصر: 'ok' للنجاح، أو كود خطأ Meta (int) فيُردّ 400 بجسم الخطأ كما
     * تُرسله Meta، أو 'connect' لانقطاع الشبكة. ما بعد القائمة نجاح، والطلبات
     * التي ليست رسائل (بلا type) تنجح ولا تستهلك من القائمة.
     *
     * @param array<int, string|int> $outcomes
     */
    protected function fakeGraphSequence(array $outcomes): void
    {
        $this->graphRequests = [];
        $index = 0;

        $stack = HandlerStack::create(function (\Psr\Http\Message\RequestInterface $request) use (&$index, $outcomes) {
            $body = json_decode((string) $request->getBody(), true);
            $outcome = is_array($body) && isset($body['type']) ? ($outcomes[$index++] ?? 'ok') : 'ok';

            if ($outcome === 'connect') {
                return Create::rejectionFor(new \GuzzleHttp\Exception\ConnectException('cURL error 28: timed out', $request));
            }

            if (is_int($outcome)) {
                return Create::promiseFor(new Response(400, [], json_encode(['error' => [
                    'message' => "(#{$outcome}) Something went wrong",
                    'type' => 'OAuthException',
                    'code' => $outcome,
                    'fbtrace_id' => 'TRACE-' . $index,
                ]])));
            }

            // فريد بين النداءات: wam_id فريد في الجدول، والاختبار قد يستدعي هذا مرّات.
            return Create::promiseFor(new Response(200, [], json_encode([
                'messages' => [['id' => 'wamid.SEQ-' . Str::random(10) . '-' . $index]],
            ])));
        });
        $stack->push(Middleware::history($this->graphRequests));

        $this->app->bind(Client::class, fn () => new Client(['handler' => $stack]));
    }

    /** @return array<int, array> أجسام الرسائل المرسلة إلى واتساب، بالترتيب. */
    protected function sentMessages(): array
    {
        return array_values(array_filter(array_map(
            fn (array $entry) => json_decode((string) $entry['request']->getBody(), true),
            $this->graphRequests
        ), fn ($body) => is_array($body) && isset($body['type'])));
    }

    /** نصوص ما أُرسل: متن الرسالة النصّية أو متن الرسالة التفاعلية. */
    protected function sentBodies(): array
    {
        return array_map(
            fn (array $message) => $message['text']['body']
                ?? $message['interactive']['body']['text']
                ?? $message['image']['caption']
                ?? null,
            $this->sentMessages()
        );
    }

    // ------------------------------------------------- بناء المسار

    protected function startNode(string $trigger = 'keywords', ?string $keywords = 'start', ?int $timeout = null): array
    {
        return [
            'id' => '1',
            'type' => 'start',
            'label' => 'start',
            'position' => ['x' => 0, 'y' => 0],
            'data' => ['title' => 'start', 'metadata' => ['fields' => [
                'type' => $trigger,
                'keywords' => $trigger === 'keywords' ? $keywords : null,
                'timeout' => $timeout,
            ]]],
        ];
    }

    protected function textNode(string $id, string $body): array
    {
        return $this->messageNode($id, 'text', ['type' => 'text', 'body' => $body]);
    }

    protected function buttonsNode(string $id, string $body, array $buttons, string $footer = ''): array
    {
        $keys = ['button1', 'button2', 'button3'];
        $fields = ['type' => 'interactive buttons', 'buttonType' => 'buttons', 'body' => $body, 'footer' => $footer, 'headerType' => 'none', 'buttons' => []];

        foreach (array_values($buttons) as $i => $title) {
            $fields['buttons'][$keys[$i]] = $title;
        }

        return $this->messageNode($id, 'buttons', $fields);
    }

    /** @param array<int, string[]> $sections عناوين الصفوف لكل قسم. */
    protected function listNode(string $id, string $body, array $sections, string $footer = ''): array
    {
        return $this->messageNode($id, 'list', [
            'type' => 'interactive list',
            'body' => $body,
            'footer' => $footer,
            'headerType' => 'none',
            'buttonLabel' => 'القائمة',
            'sections' => array_map(fn (array $rows) => [
                'title' => 'قسم',
                'rows' => array_map(fn (string $title) => ['title' => $title, 'description' => ''], $rows),
            ], $sections),
        ]);
    }

    protected function actionNode(string $id, string $actionType, array $config = [], bool $active = true): array
    {
        return [
            'id' => $id,
            'type' => 'action',
            'label' => "{$actionType} action",
            'position' => ['x' => 0, 'y' => 0],
            'data' => [
                'title' => $actionType,
                'actionType' => $actionType,
                'config' => $config === [] ? new \stdClass() : $config,
                'is_active' => $active,
            ],
        ];
    }

    private function messageNode(string $id, string $type, array $fields): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'label' => "{$type} node",
            'position' => ['x' => 0, 'y' => 0],
            'data' => ['title' => $type, 'metadata' => ['fields' => $fields]],
        ];
    }

    /**
     * @param array<int, array> $nodes
     * @param array<int, array{0: string, 1: string, 2?: string|null}> $links [source, target, sourceHandle]
     */
    protected function makeFlow(array $nodes, array $links, array $attributes = []): Flow
    {
        $byId = [];
        foreach ($nodes as $node) {
            $byId[$node['id']] = $node;
        }

        $edges = array_map(fn (array $link) => [
            'id' => 'vueflow__edge-' . $link[0] . ($link[2] ?? '') . '-' . $link[1],
            'type' => 'default',
            'source' => $link[0],
            'target' => $link[1],
            'sourceHandle' => $link[2] ?? null,
            'targetHandle' => null,
            'sourceNode' => $byId[$link[0]],
            'targetNode' => $byId[$link[1]],
        ], $links);

        $start = $nodes[0]['data']['metadata']['fields'] ?? [];

        return Flow::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'flow-' . Str::random(5),
            'status' => 'active',
            'trigger' => $start['type'] ?? null,
            'keywords' => $start['keywords'] ?? null,
            'metadata' => json_encode(['nodes' => $nodes, 'edges' => $edges, 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]], JSON_UNESCAPED_UNICODE),
        ], $attributes));
    }

    /**
     * رسالة واردة تمرّ بالمسار الحقيقي: AutoReplyService يقرّر من يُجيب
     * (الردود الآلية، الـ flow، المساعد الذكي) بترتيب المنشأة.
     */
    protected function receive(string $body, bool $isNewContact = false): Chat
    {
        $chat = $this->inbound($body);
        app(\App\Services\AutoReplyService::class)->checkAutoReply($chat, $isNewContact);

        return $chat;
    }

    /** ضغطة زرّ ردّ: واتساب يرسل عنوان الزرّ في button_reply. */
    protected function receiveButton(string $title): Chat
    {
        $chat = Chat::create([
            'organization_id' => $this->contact->organization_id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'interactive', 'interactive' => [
                'type' => 'button_reply',
                'button_reply' => ['id' => 'button1', 'title' => $title],
            ]]),
            'status' => 'delivered',
        ]);
        app(\App\Services\AutoReplyService::class)->checkAutoReply($chat, false);

        return $chat;
    }

    /** ردّ آلي بسيط: يُرسل $reply حين تحوي الرسالة $trigger. */
    protected function basicReply(string $trigger, string $reply): void
    {
        \App\Models\AutoReply::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $trigger,
            'trigger' => $trigger,
            'match_criteria' => 'contains',
            'metadata' => json_encode(['type' => 'text', 'data' => ['text' => $reply]]),
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);
    }

    protected function flowSession(): ?\Modules\FlowBuilder\Models\FlowUserData
    {
        return \Modules\FlowBuilder\Models\FlowUserData::where('contact_id', $this->contact->id)->first();
    }

    protected function inbound(string $body, ?Contact $contact = null): Chat
    {
        $contact ??= $this->contact;

        return Chat::create([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => $body]]),
            'status' => 'delivered',
        ]);
    }
}
