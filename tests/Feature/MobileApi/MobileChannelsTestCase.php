<?php

namespace Tests\Feature\MobileApi;

use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Support\OrganizationRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * أدوات مشتركة لاختبارات القنوات في تطبيق الجوال: حساب منصّة، وجهة اتصال
 * على كل قناة، ورسالة بسجلّها في chat_logs (المزامنة تبدأ منه)، وتذكرة.
 */
abstract class MobileChannelsTestCase extends MobileApiTestCase
{
    /**
     * نقطتا المزامنة تستدعيان set_time_limit(120) — مقصودٌ في الخادم. لكن
     * الاختبار يجري في عمليّة PHPUnit نفسها، فيبقى الحدّ ساري المفعول على
     * بقيّة المجموعة فيقتلها بعد دقيقتين. نُعيده بلا حدّ كما يبدأ سطر الأوامر.
     */
    protected function tearDown(): void
    {
        set_time_limit(0);

        parent::tearDown();
    }

    protected function telegramAccount(array $attributes = []): ChannelAccount
    {
        return ChannelAccount::create(array_merge([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => (string) random_int(100000, 999999),
            'name' => 'بوت الدعم',
            'username' => 'support_bot',
            'credentials' => ['bot_token' => '777001:AAF-token', 'webhook_secret' => 'hook-secret'],
            'status' => ChannelAccount::STATUS_ACTIVE,
        ], $attributes))->fresh();
    }

    protected function whatsappContact(array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'ندى',
            'last_name' => 'العتيبي',
            'phone' => '+966500000' . random_int(100, 999),
            'created_by' => $this->owner->id,
        ], $attributes))->fresh();
    }

    protected function channelContact(string $channel, array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'channel' => $channel,
            'channel_identifier' => (string) random_int(1000000, 9999999),
            'first_name' => 'خالد',
            'metadata' => json_encode(['channel_username' => 'khaled_tg', 'channel_display_name' => 'Khaled']),
            'created_by' => 0,
        ], $attributes))->fresh();
    }

    /** رسالة بسجلّها: المزامنة لا ترى إلا ما له سطر في chat_logs. */
    protected function message(Contact $contact, array $attributes = []): Chat
    {
        $chat = Chat::create(array_merge([
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'wam_id' => 'wamid.' . Str::random(10),
            'type' => 'inbound',
            'status' => 'delivered',
            'channel' => $contact->channel ?: Channel::WHATSAPP,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحبا']], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subMinute(),
        ], $attributes));

        ChatLog::create([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => $chat->getRawOriginal('created_at'),
        ]);

        Contact::where('id', $contact->id)->update(['latest_chat_created_at' => $chat->getRawOriginal('created_at')]);

        return $chat;
    }

    /** تذكرة مباشرة في الجدول: لا مراقبين ولا إسناد آلي يغيّران ما نختبره. */
    protected function ticket(Contact $contact, array $attributes = []): int
    {
        return (int) DB::table('chat_tickets')->insertGetId(array_merge([
            'contact_id' => $contact->id,
            'assigned_to' => null,
            'status' => 'open',
            'is_latest' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    protected function agent(): User
    {
        return $this->member(OrganizationRole::AGENT);
    }

    /** @param array<string, mixed> $tickets */
    protected function ticketSettings(array $tickets): void
    {
        $metadata = json_decode((string) $this->organization->fresh()->metadata, true) ?: [];
        $metadata['tickets'] = array_merge([
            'active' => true,
            'auto_assignment' => false,
            'reassign_reopened_chats' => false,
            'allow_agents_to_view_all_chats' => true,
            'encrypt_contacts_for_agents' => false,
        ], $tickets);
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    protected function connectWhatsapp(): void
    {
        $metadata = json_decode((string) $this->organization->fresh()->metadata, true) ?: [];
        $metadata['whatsapp'] = [
            'access_token' => 'token',
            'app_id' => '1',
            'phone_number_id' => '2',
            'waba_id' => '3',
        ];
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }
}
