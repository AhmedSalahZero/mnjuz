<?php

namespace Tests\Feature\Channels;

use App\Http\Resources\ContactListResource;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمة المحادثات: الفلتر حسب المنصّة، والقناة في ردّ القائمة.
 *
 * التصفية تجري في قاعدة البيانات لا في المتصفّح — وهذا مقصود: منشأة فيها
 * عشرات الآلاف من المحادثات لا يصحّ أن تُحمَّل كلّها ثم تُخفى.
 */
class ChatListChannelFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);
    }

    /** بلا فلتر تظهر كل القنوات. */
    public function test_without_a_filter_every_channel_is_listed(): void
    {
        $this->contactWithChat(Channel::WHATSAPP, '+966500000001');
        $this->contactWithChat(Channel::TELEGRAM, null, 'tg-1');
        $this->contactWithChat(Channel::MESSENGER, null, 'PSID-1');

        $rows = (new Contact)->contactsWithChatsOptimized($this->organization->id);

        $this->assertCount(3, $rows);
    }

    /** ومع الفلتر تظهر قناة واحدة. */
    public function test_a_filter_narrows_the_list_to_one_channel(): void
    {
        $this->contactWithChat(Channel::WHATSAPP, '+966500000001');
        $this->contactWithChat(Channel::TELEGRAM, null, 'tg-1');
        $this->contactWithChat(Channel::TELEGRAM, null, 'tg-2');

        $rows = (new Contact)->contactsWithChatsOptimized(
            $this->organization->id,
            null, false, null, 'desc', 'owner', true, false, null, 1, null,
            Channel::TELEGRAM
        );

        $this->assertCount(2, $rows);
        $this->assertSame([Channel::TELEGRAM, Channel::TELEGRAM], $rows->pluck('channel')->all());
    }

    /**
     * وفلتر واتساب يُظهر جهات الاتصال القائمة — وهي التي لم تُذكر قناتها
     * قطّ عند إنشائها. هذا ما يُثبّت أن القيمة الافتراضية تعمل.
     */
    public function test_filtering_by_whatsapp_finds_legacy_contacts(): void
    {
        $this->contactWithChat(Channel::WHATSAPP, '+966500000001');
        $this->contactWithChat(Channel::TELEGRAM, null, 'tg-1');

        $rows = (new Contact)->contactsWithChatsOptimized(
            $this->organization->id,
            null, false, null, 'desc', 'owner', true, false, null, 1, null,
            Channel::WHATSAPP
        );

        $this->assertCount(1, $rows);
        $this->assertSame('+966500000001', $rows->first()->phone);
    }

    /** وقناة بلا محادثات تُرجع قائمة فارغة لا خطأً. */
    public function test_a_channel_with_no_conversations_returns_an_empty_list(): void
    {
        $this->contactWithChat(Channel::WHATSAPP, '+966500000001');

        $rows = (new Contact)->contactsWithChatsOptimized(
            $this->organization->id,
            null, false, null, 'desc', 'owner', true, false, null, 1, null,
            Channel::TIKTOK
        );

        $this->assertCount(0, $rows);
    }

    /** القناة تصل الواجهة في ردّ القائمة — وعليها تُرسَم الأيقونة. */
    public function test_the_list_resource_exposes_the_channel(): void
    {
        $contact = $this->contactWithChat(Channel::TELEGRAM, null, 'tg-9');

        $payload = (new ContactListResource($contact))->toArray(request());

        $this->assertSame(Channel::TELEGRAM, $payload['channel']);
    }

    /** وجهة الاتصال القائمة تصل بقناة whatsapp لا بقيمة فارغة. */
    public function test_a_legacy_contact_is_exposed_as_whatsapp(): void
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966500000002',
            'created_by' => 0,
        ]);

        $payload = (new ContactListResource($contact->fresh()))->toArray(request());

        $this->assertSame(Channel::WHATSAPP, $payload['channel']);
    }

    /**
     * القنوات المربوطة تُحسب من الحسابات الفعّالة — والفلتر لا يظهر في
     * الواجهة إلّا حين تتجاوز الواحدة.
     */
    public function test_connected_channels_drive_whether_the_filter_shows(): void
    {
        $manager = app(\App\Services\Messaging\ChannelManager::class);
        $organizationId = (int) $this->organization->id;

        $this->assertCount(0, $manager->connectedChannels($organizationId));

        ChannelAccount::create([
            'organization_id' => $organizationId,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-x',
            'credentials' => ['bot_token' => 't'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $this->assertSame([Channel::TELEGRAM], $manager->connectedChannels($organizationId));

        // الحساب المعطّل لا يُحسب — فلا يظهر فلتر لقناة لا تعمل.
        ChannelAccount::create([
            'organization_id' => $organizationId,
            'channel' => Channel::TIKTOK,
            'external_id' => 'tt-x',
            'credentials' => ['access_token' => 'a'],
            'status' => ChannelAccount::STATUS_DISABLED,
            'created_by' => 0,
        ]);

        $this->assertSame([Channel::TELEGRAM], $manager->connectedChannels($organizationId));
    }

    private function contactWithChat(string $channel, ?string $phone, ?string $identifier = null): Contact
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => $channel,
            'channel_identifier' => $identifier,
            'phone' => $phone,
            'first_name' => 'عميل',
            'created_by' => 0,
        ]);

        Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => ($identifier ?? $phone) . '-msg',
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => '{"type":"text","text":{"body":"مرحبا"}}',
            'status' => 'delivered',
            'channel' => $channel,
            'created_at' => now(),
        ]);

        return $contact->fresh();
    }
}
