<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\ChannelTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * صور تيليجرام: sendPhoto يرفض AVIF وأخواتها («failed to get HTTP URL
 * content» — حملة Waz يوم 17/11/2026). فتُحوَّل JPG عند الرفع، في القالب
 * وفي الحملة، وقالبٌ حُفظت صورته قبل ذلك لا تُنشأ منه حملة ستفشل.
 */
class TelegramImageConversionTest extends CampaignTestCase
{
    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        ChannelAccount::create([
            'organization_id' => $this->organization->id, 'channel' => 'telegram', 'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'tok'],
            'status' => ChannelAccount::STATUS_ACTIVE, 'created_by' => 0,
        ]);

        $this->customer('', 'سالم', ['channel' => 'telegram', 'channel_identifier' => '7771', 'phone' => null]);
    }

    protected function tearDown(): void
    {
        $urls = ChannelTemplate::withTrashed()->get()->pluck('media.url')
            ->merge(Campaign::all()->map(fn (Campaign $campaign) => $campaign->messagePayload()['media']['url'] ?? null))
            ->filter();

        foreach ($urls as $url) {
            Storage::disk('local')->delete(Str::after((string) parse_url($url, PHP_URL_PATH), '/media/'));
        }

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    /** صورة AVIF حقيقية بقناة شفافية، أو تخطّي الاختبار إن لم يدعمها Imagick هنا. */
    private function avifUpload(string $name = 'offer.avif'): UploadedFile
    {
        if (!class_exists(\Imagick::class) || \Imagick::queryFormats('AVIF') === []) {
            $this->markTestSkipped('Imagick without AVIF support on this machine.');
        }

        $image = new \Imagick();
        $image->newImage(40, 30, new \ImagickPixel('rgba(255,0,0,0.5)'));
        $image->setImageFormat('avif');

        $path = tempnam(sys_get_temp_dir(), 'avif');
        file_put_contents($path, $image->getImageBlob());
        $image->clear();
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, 'image/avif', null, true);
    }

    private function storedBytes(array $media): string
    {
        return (string) Storage::disk('local')->get(Str::after((string) parse_url($media['url'], PHP_URL_PATH), '/media/'));
    }

    public function test_an_avif_image_in_a_telegram_template_is_stored_as_jpg(): void
    {
        $upload = $this->avifUpload();

        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'عرض', 'body' => 'نص', 'media' => $upload,
        ])->assertSessionHasNoErrors();

        $media = ChannelTemplate::first()->media;
        $this->assertSame('image/jpeg', $media['mime_type']);
        $this->assertSame('image', $media['type']);
        $this->assertSame('offer.jpg', $media['name']);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $this->storedBytes($media));
        $this->assertSame(strlen($this->storedBytes($media)), $media['size']);
    }

    public function test_an_avif_image_in_a_telegram_campaign_is_sent_as_jpg(): void
    {
        $this->createFromWeb([
            'name' => 'حملة بصورة',
            'channel' => 'telegram',
            'message_text' => 'أهلاً {first_name}',
            'message_media' => $this->avifUpload('banner.avif'),
            'contacts' => 'all',
            'skip_schedule' => true,
        ])->assertRedirect();

        $media = Campaign::latest('id')->first()->messagePayload()['media'];
        $this->assertSame('image/jpeg', $media['mime_type']);
        $this->assertSame('banner.jpg', $media['name']);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $this->storedBytes($media));
    }

    public function test_jpg_and_png_images_are_kept_as_they_are(): void
    {
        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'بصورة PNG', 'body' => 'نص', 'media' => UploadedFile::fake()->image('offer.png'),
        ])->assertSessionHasNoErrors();

        $media = ChannelTemplate::first()->media;
        $this->assertSame('image/png', $media['mime_type']);
        $this->assertSame('offer.png', $media['name']);
    }

    public function test_an_image_that_cannot_be_converted_is_refused_with_a_reason(): void
    {
        $broken = UploadedFile::fake()->createWithContent('broken.avif', 'not really an image');

        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'تالفة', 'body' => 'نص', 'media' => $broken,
        ])->assertSessionHasErrors('media');

        $this->assertSame(0, ChannelTemplate::count());
    }

    public function test_an_image_type_outside_the_conversion_list_is_refused(): void
    {
        $icon = UploadedFile::fake()->create('logo.ico', 1, 'image/x-icon');

        $this->asOwner()->post('/templates/channel/telegram', [
            'name' => 'أيقونة', 'body' => 'نص', 'media' => $icon,
        ])->assertSessionHasErrors('media');

        $this->assertSame(0, ChannelTemplate::count());
    }

    /** قالب «قالب للتجربة» على الإنتاج: صورته AVIF من قبل التحويل. */
    public function test_a_campaign_from_a_template_with_an_avif_image_is_refused_before_it_is_created(): void
    {
        $template = ChannelTemplate::create([
            'organization_id' => $this->organization->id,
            'channel' => 'telegram',
            'name' => 'قالب للتجربة',
            'body' => 'مرحبا {first_name}',
            'media' => [
                'url' => 'https://example.com/uploads/offer.avif', 'name' => 'offer.avif', 'type' => 'image',
                'mime_type' => 'image/avif', 'size' => 16289, 'location' => 'amazon',
            ],
        ]);

        $this->createFromWeb([
            'name' => 'حملة من القالب',
            'channel' => 'telegram',
            'channel_template' => (string) $template->uuid,
            'contacts' => 'all',
            'skip_schedule' => true,
        ])->assertJsonValidationErrors('channel_template');

        $this->assertSame(0, Campaign::where('channel', 'telegram')->count());
    }

    public function test_a_campaign_from_a_template_with_a_jpg_image_is_created(): void
    {
        $template = ChannelTemplate::create([
            'organization_id' => $this->organization->id,
            'channel' => 'telegram',
            'name' => 'قالب JPG',
            'body' => 'مرحبا {first_name}',
            'media' => [
                'url' => 'https://example.com/uploads/offer.jpg', 'name' => 'offer.jpg', 'type' => 'image',
                'mime_type' => 'image/jpeg', 'size' => 1000, 'location' => 'amazon',
            ],
        ]);

        $this->createFromWeb([
            'name' => 'حملة من القالب',
            'channel' => 'telegram',
            'channel_template' => (string) $template->uuid,
            'contacts' => 'all',
            'skip_schedule' => true,
        ])->assertRedirect();

        $this->assertSame(1, Campaign::where('channel', 'telegram')->count());
    }
}
