<?php

namespace Tests\Feature;

use App\Events\ContactAiAssistanceUpdated;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * إشعار الشاشات المفتوحة حين يتغيّر مساعد الذكاء الاصطناعي لمحادثة.
 *
 * الخادم يغيّر الحالة بنفسه (كلمة بدء أو إيقاف يكتبها العميل، تفعيل تلقائي)،
 * والصفحة المفتوحة تعرض ما حُمّلت به. الحدث يحمل ثلاثة حقول، ويصل لمن يرى
 * المحادثة وحده — بقواعد الرؤية نفسها لرسائلها — وإلا تسرّب لموظف حالةُ محادثةٍ
 * ليست له.
 *
 * دورة التغيير الكاملة (مفتاح، كلمات، تفعيل تلقائي) في
 * Cycle/AiAssistantCycleTest. وهنا الحدث نفسه ومستلموه وربطه بالواجهة.
 */
class ContactAiAssistanceEventTest extends CycleTestCase
{
    private function team(string $role): User
    {
        $user = User::factory()->create(['role' => 'user']);
        Team::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $this->organization->id,
            'role' => $role,
            'created_by' => $this->owner->id,
        ]);

        return $user;
    }

    /** تذاكر مفعّلة والموظفون لا يرون كل المحادثات. */
    private function restrictAgentsToAssigned(): void
    {
        $metadata = json_decode($this->organization->metadata, true);
        $metadata['tickets'] = ['active' => true, 'allow_agents_to_view_all_chats' => false];
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    /** @return list<string> أسماء القنوات التي سيُبثّ عليها الحدث. */
    private function channelsFor(Contact $contact): array
    {
        $event = new ContactAiAssistanceUpdated($this->organization->id, $contact->id, (string) $contact->uuid, true);

        return array_map(fn ($channel) => $channel->name, $event->broadcastOn());
    }

    private function channel(User $user): string
    {
        return 'presence-chats.ch' . $this->organization->id . '.' . $user->id;
    }

    // ------------------------------------------------- الشكل

    public function test_it_is_broadcast_on_the_high_priority_queue(): void
    {
        $event = new ContactAiAssistanceUpdated(1, 2, 'uuid', true);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame('high', $event->queue);
    }

    public function test_the_payload_is_the_three_fields_the_screen_needs(): void
    {
        $event = new ContactAiAssistanceUpdated(5, 42, 'abc-123', false);

        $this->assertSame([
            'contact_id' => 42,
            'contact_uuid' => 'abc-123',
            'ai_assistance_enabled' => false,
        ], $event->broadcastWith());
    }

    /** المنطقي يصل منطقياً: الواجهة ترفض غيره، فلا يقلب نصٌّ المفتاح. */
    public function test_the_flag_is_sent_as_a_real_boolean(): void
    {
        $on = (new ContactAiAssistanceUpdated(1, 1, 'u', true))->broadcastWith();
        $off = (new ContactAiAssistanceUpdated(1, 1, 'u', false))->broadcastWith();

        $this->assertSame(true, $on['ai_assistance_enabled']);
        $this->assertSame(false, $off['ai_assistance_enabled']);
    }

    // ------------------------------------------------- المستلمون

    /** بلا تذاكر: كل أعضاء الفريق يرون كل المحادثات فيصلهم الحدث. */
    public function test_everyone_on_the_team_gets_it_when_chats_are_shared(): void
    {
        $agent = $this->team('agent');
        $manager = $this->team('manager');
        $contact = $this->contact();

        $channels = $this->channelsFor($contact);

        foreach ([$this->owner, $agent, $manager] as $user) {
            $this->assertContains($this->channel($user), $channels, "المستخدم {$user->id} لم يصله الحدث.");
        }
    }

    public function test_the_channels_are_presence_channels(): void
    {
        $event = new ContactAiAssistanceUpdated($this->organization->id, $this->contact()->id, 'u', true);

        $this->assertNotEmpty($event->broadcastOn());
        foreach ($event->broadcastOn() as $channel) {
            $this->assertInstanceOf(PresenceChannel::class, $channel);
        }
    }

    /** تذاكر مفعّلة وموظف مقيَّد: المالك والموظف المسند فقط. */
    public function test_a_restricted_agent_gets_it_only_for_his_own_conversations(): void
    {
        $this->restrictAgentsToAssigned();
        $assigned = $this->team('agent');
        $other = $this->team('agent');
        $contact = $this->contact();
        ChatTicket::create([
            'contact_id' => $contact->id,
            'assigned_to' => $assigned->id,
            'status' => 'open',
            'is_latest' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $channels = $this->channelsFor($contact);

        $this->assertContains($this->channel($this->owner), $channels, 'المالك يرى كل شيء.');
        $this->assertContains($this->channel($assigned), $channels, 'الموظف المسند يرى محادثته.');
        $this->assertNotContains($this->channel($other), $channels, 'تسرّبت حالة محادثة لموظف غير مسند إليها.');
    }

    public function test_an_unassigned_conversation_reaches_no_restricted_agent(): void
    {
        $this->restrictAgentsToAssigned();
        $agent = $this->team('agent');
        $contact = $this->contact();

        $channels = $this->channelsFor($contact);

        $this->assertNotContains($this->channel($agent), $channels);
        $this->assertContains($this->channel($this->owner), $channels);
    }

    /** لا يعبر الحدث إلى منظمة أخرى. */
    public function test_it_never_crosses_into_another_organization(): void
    {
        $stranger = User::factory()->create(['role' => 'user']);
        $other = \App\Models\Organization::factory()->create(['created_by' => $stranger->id]);
        Team::factory()->create(['user_id' => $stranger->id, 'organization_id' => $other->id, 'role' => 'owner', 'created_by' => $stranger->id]);

        $channels = $this->channelsFor($this->contact());

        foreach ($channels as $name) {
            $this->assertStringStartsWith('presence-chats.ch' . $this->organization->id . '.', $name);
        }
        $this->assertNotContains('presence-chats.ch' . $other->id . '.' . $stranger->id, $channels);
    }

    public function test_an_unknown_organization_broadcasts_to_nobody_and_does_not_throw(): void
    {
        $event = new ContactAiAssistanceUpdated(999999, 1, 'u', true);

        $this->assertSame([], $event->broadcastOn());
    }

    // ------------------------------------------------- Contact::setAiAssistanceEnabled

    public function test_a_change_is_saved_and_announced_once(): void
    {
        Event::fake([ContactAiAssistanceUpdated::class]);
        $contact = $this->contact();

        $this->assertTrue($contact->setAiAssistanceEnabled(true));

        $this->assertSame(1, (int) $contact->fresh()->ai_assistance_enabled);
        Event::assertDispatchedTimes(ContactAiAssistanceUpdated::class, 1);
    }

    public function test_no_change_means_no_write_and_no_event(): void
    {
        Event::fake([ContactAiAssistanceUpdated::class]);
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->assertFalse($contact->setAiAssistanceEnabled(true));

        Event::assertNotDispatched(ContactAiAssistanceUpdated::class);
    }

    public function test_turning_it_off_is_announced(): void
    {
        Event::fake([ContactAiAssistanceUpdated::class]);
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->assertTrue($contact->setAiAssistanceEnabled(false));

        $this->assertSame(0, (int) $contact->fresh()->ai_assistance_enabled);
        Event::assertDispatched(ContactAiAssistanceUpdated::class, fn ($e) => $e->enabled === false && $e->contactId === $contact->id);
    }

    /** النموذج المحمَّل بأعمدة مختارة (قائمة المحادثات) لا يحمل العلم: يُعامَل مطفأً. */
    public function test_a_model_loaded_without_the_column_is_treated_as_off(): void
    {
        Event::fake([ContactAiAssistanceUpdated::class]);
        $contact = $this->contact();
        $partial = Contact::query()->select(['id', 'uuid', 'organization_id'])->find($contact->id);

        $this->assertFalse($partial->setAiAssistanceEnabled(false), 'مطفأ أصلاً: لا تغيّر.');
        $this->assertTrue($partial->setAiAssistanceEnabled(true));
        $this->assertSame(1, (int) $contact->fresh()->ai_assistance_enabled);
    }

    // ------------------------------------------------- الواجهة

    private function source(string $path): string
    {
        $this->assertFileExists(base_path($path));

        return file_get_contents(base_path($path));
    }

    public function test_the_update_logic_holds(): void
    {
        exec('node --version 2>/dev/null', $output, $status);
        if ($status !== 0) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/contact-updates.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('OK', implode("\n", $out));
    }

    public function test_the_echo_channel_listens_for_the_event(): void
    {
        $echo = $this->source('resources/js/echo.js');

        $this->assertStringContainsString("channel.listen('ContactAiAssistanceUpdated'", $echo);
        $this->assertStringContainsString('subscribeContactUpdates', $echo);
        // التوقيع القديم باقٍ: نقاط الاستدعاء تعتمد عليه (tests/js/echo-call-sites.mjs)
        $this->assertMatchesRegularExpression('/export function getOrJoinChatChannel\(\s*organizationId,\s*userId,\s*broadcast\s*\)/', $echo);
    }

    /** المشتركون في قناة موجودة سلفاً يحصلون على الواجهة الجديدة أيضاً. */
    public function test_a_second_subscriber_on_the_same_channel_gets_both_apis(): void
    {
        $echo = $this->source('resources/js/echo.js');

        $this->assertStringContainsString('return channelApi(sharedChannelEntries.get(name));', $echo);
        $this->assertStringContainsString('return channelApi(entry);', $echo);
    }

    public function test_the_open_conversation_page_applies_it_and_cleans_up(): void
    {
        $index = $this->source('resources/js/Pages/User/Chat/Index.vue');

        $this->assertStringContainsString("import { applyAiAssistanceUpdate } from '@/Composables/contactUpdates'", $index);
        $this->assertStringContainsString('subscribeContactUpdates((event) => {', $index);
        $this->assertStringContainsString('applyAiAssistanceUpdate(contact.value, event)', $index);
        $this->assertStringContainsString('unsubscribeContactUpdates.value()', $index, 'الاشتراك لا يُلغى عند مغادرة الصفحة.');
    }

    /** الترويسة تتبع الحقل: تحديثه من الحدث يُحرّك المفتاح (الـ watch من الإصلاح السابق). */
    public function test_the_header_follows_the_field(): void
    {
        $header = $this->source('resources/js/Components/ChatComponents/ChatHeader.vue');

        $this->assertStringContainsString('props.contact?.ai_assistance_enabled', $header);
        $this->assertStringContainsString('watch(', $header);
    }
}
