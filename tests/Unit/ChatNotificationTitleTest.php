<?php

namespace Tests\Unit;

use App\Services\Chat\ChatBroadcastPayloadBuilder;
use App\Services\Chat\ChatNotificationContentBuilder;
use Tests\TestCase;

/**
 * عنوان الإشعار: الاسم، ثم الرقم، ثم @username — عميل تيليجرام بلا اسم ولا
 * رقم كان يصل «Unknown».
 */
class ChatNotificationTitleTest extends TestCase
{
    private ChatNotificationContentBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ChatNotificationContentBuilder(new ChatBroadcastPayloadBuilder());
    }

    public function test_the_name_comes_first(): void
    {
        $this->assertSame('سارة', $this->builder->buildTitle([
            'contact_full_name' => 'سارة',
            'formatted_phone_number' => '+966 50 123 4567',
            'channel_username' => 'sara',
        ]));
    }

    public function test_the_number_comes_before_the_username(): void
    {
        $this->assertSame('+966 50 123 4567', $this->builder->buildTitle([
            'formatted_phone_number' => '+966 50 123 4567',
            'channel_username' => 'sara',
        ]));
    }

    public function test_a_contact_without_name_or_number_shows_its_username(): void
    {
        $this->assertSame('@khaled_tg', $this->builder->buildTitle([
            'contact_full_name' => null,
            'phone' => null,
            'formatted_phone_number' => null,
            'channel_username' => 'khaled_tg',
        ]));
    }

    public function test_nothing_known_is_still_unknown(): void
    {
        $this->assertSame(__('Unknown'), $this->builder->buildTitle(['channel_username' => null]));
    }
}
