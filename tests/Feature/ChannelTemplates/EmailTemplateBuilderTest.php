<?php

namespace Tests\Feature\ChannelTemplates;

use App\Models\CampaignMediaHistory;
use App\Models\ChannelTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Campaigns\CampaignTestCase;

/**
 * قالب البريد بالـ builder: التصميم يُخزَّن بقائمة السماح ونسخته النصّية
 * يولّدها الخادم، والقالب النصّي القديم يُفتح عنصراً واحداً، وصور العناصر
 * تُرفع إلى مكتبة المنشأة وحدها.
 */
class EmailTemplateBuilderTest extends CampaignTestCase
{
    protected function tearDown(): void
    {
        foreach (CampaignMediaHistory::withTrashed()->pluck('path') as $url) {
            Storage::disk('local')->delete(Str::after((string) parse_url($url, PHP_URL_PATH), '/media/'));
        }

        parent::tearDown();
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    private function design(array $blocks = null): array
    {
        return [
            'settings' => ['page_bg' => '#f4f5f7', 'content_bg' => '#ffffff', 'text_color' => '#1f2937', 'font' => 'tahoma', 'direction' => 'rtl'],
            'blocks' => $blocks ?? [
                ['id' => 'h1', 'type' => 'header', 'props' => ['text' => '{organization_name}', 'bg_color' => '#1B5E20']],
                ['id' => 't1', 'type' => 'heading', 'props' => ['text' => 'كل عام والوطن بخير', 'script' => 'x']],
                ['id' => 'b1', 'type' => 'button', 'props' => ['label' => 'احتفل معنا', 'url' => 'https://shop.example/offer']],
            ],
        ];
    }

    public function test_an_email_template_is_saved_with_its_design_and_a_generated_text_version(): void
    {
        $this->asOwner()->post('/templates/channel/email', [
            'name' => 'اليوم الوطني',
            'subject' => 'نحتفل معكم باليوم الوطني',
            'design' => $this->design(),
        ])->assertRedirect('/templates/channel/email')->assertSessionHasNoErrors();

        $template = ChannelTemplate::where('channel', 'email')->first();

        $this->assertSame("{organization_name}\n\nكل عام والوطن بخير\n\nاحتفل معنا: https://shop.example/offer", $template->body);
        $this->assertSame(['header', 'heading', 'button'], array_column($template->design['blocks'], 'type'));
        $this->assertSame('#1b5e20', $template->design['blocks'][0]['props']['bg_color']);
        $this->assertArrayNotHasKey('script', $template->design['blocks'][1]['props']);
        $this->assertNull($template->media);
    }

    public function test_design_errors_point_at_the_element_and_property(): void
    {
        $this->asOwner()->post('/templates/channel/email', [
            'name' => 'خطأ',
            'subject' => 'عنوان',
            'design' => $this->design([
                ['type' => 'text', 'props' => ['text' => 'نص']],
                ['type' => 'button', 'props' => ['label' => 'اضغط', 'url' => 'javascript:alert(1)']],
            ]),
        ])->assertSessionHasErrors('design.blocks.1.props.url');

        $this->asOwner()->post('/templates/channel/email', [
            'name' => 'نوع مجهول', 'subject' => 'عنوان',
            'design' => $this->design([['type' => 'iframe', 'props' => []]]),
        ])->assertSessionHasErrors('design.blocks.0.type');

        $this->asOwner()->post('/templates/channel/email', [
            'name' => 'فارغ', 'subject' => 'عنوان', 'design' => $this->design([]),
        ])->assertSessionHasErrors('design.blocks');

        $this->assertSame(0, ChannelTemplate::count());
    }

    public function test_a_plain_body_is_still_accepted_and_becomes_one_text_element(): void
    {
        $this->asOwner()->post('/templates/channel/email', [
            'name' => 'نصّي', 'subject' => 'عنوان', 'body' => "أهلاً {first_name}\nخصم اليوم",
        ])->assertSessionHasNoErrors();

        $template = ChannelTemplate::first();
        $this->assertSame(['text'], array_column($template->design['blocks'], 'type'));
        $this->assertSame("أهلاً {first_name}\nخصم اليوم", $template->body);
    }

    public function test_an_old_template_without_a_design_opens_in_the_builder_as_text(): void
    {
        $old = ChannelTemplate::create([
            'organization_id' => $this->organization->id, 'channel' => 'email',
            'name' => 'قديم', 'subject' => 'عنوان', 'body' => 'نص قديم',
        ]);

        $this->asOwner()->get('/templates/channel/email/' . $old->uuid)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Templates/Channel/Form')
                ->where('template.design.blocks.0.type', 'text')
                ->where('template.design.blocks.0.props.text', 'نص قديم')
                ->where('organizationName', $this->organization->name)
            );
    }

    public function test_updating_replaces_the_design(): void
    {
        $this->asOwner()->post('/templates/channel/email', ['name' => 'قالب', 'subject' => 'عنوان', 'design' => $this->design()]);
        $template = ChannelTemplate::first();

        $this->asOwner()->post('/templates/channel/email/' . $template->uuid, [
            'name' => 'قالب', 'subject' => 'عنوان جديد',
            'design' => $this->design([['id' => 'x', 'type' => 'text', 'props' => ['text' => 'محتوى جديد']]]),
        ])->assertSessionHasNoErrors();

        $template->refresh();
        $this->assertSame('محتوى جديد', $template->body);
        $this->assertSame('عنوان جديد', $template->subject);
        $this->assertCount(1, $template->design['blocks']);
    }

    public function test_an_image_upload_lands_in_the_organization_library(): void
    {
        $response = $this->asOwner()->postJson('/templates/channel/email/images', [
            'image' => UploadedFile::fake()->image('banner.png', 600, 300),
        ])->assertCreated();

        $this->assertSame('banner.png', $response->json('data.name'));
        $this->assertStringContainsString('/media/', $response->json('data.path'));

        $item = CampaignMediaHistory::first();
        $this->assertSame('IMAGE', $item->media_type);
        $this->assertSame($this->organization->id, (int) $item->organization_id);

        // وتظهر في المكتبة المشتركة، وبالبحث.
        $this->asOwner()->getJson('/campaigns/media-history?type=IMAGE&search=bann')
            ->assertOk()->assertJsonPath('data.0.name', 'banner.png');
        $this->asOwner()->getJson('/campaigns/media-history?type=IMAGE&search=logo')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_jpg_and_png_images_up_to_five_megabytes_are_accepted(): void
    {
        $this->asOwner()->postJson('/templates/channel/email/images', [
            'image' => UploadedFile::fake()->create('vector.svg', 2, 'image/svg+xml'),
        ])->assertJsonValidationErrors('image');

        $this->asOwner()->postJson('/templates/channel/email/images', [
            'image' => UploadedFile::fake()->create('photo.avif', 20, 'image/avif'),
        ])->assertJsonValidationErrors('image');

        $this->asOwner()->postJson('/templates/channel/email/images', [
            'image' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        ])->assertJsonValidationErrors('image');

        $this->assertSame(0, CampaignMediaHistory::count());
    }

    public function test_another_organization_does_not_see_the_library_image(): void
    {
        $this->asOwner()->postJson('/templates/channel/email/images', [
            'image' => UploadedFile::fake()->image('private.png'),
        ])->assertCreated();

        $stranger = User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($stranger);

        $this->actingAs($stranger, 'user')
            ->withSession(['current_organization' => $other->id])
            ->getJson('/campaigns/media-history?type=IMAGE')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
