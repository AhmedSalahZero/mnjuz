<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChannelTemplate;
use App\Models\ChannelTemplate;
use App\Services\ActivityLogger;
use App\Services\CampaignService;
use App\Services\ContactPlaceholderService;
use App\Services\Messaging\CampaignChannels;
use App\Services\Messaging\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

/**
 * القوالب المحفوظة لقنوات بلا موافقة (تيليجرام، البريد).
 *
 * جاهزة فور حفظها: لا Meta ولا مراجعة. وكل بحث بالمنشأة والقناة معاً —
 * الـ uuid وحده من رابط لا يفتح قالب منشأة أخرى ولا قالب قناة أخرى.
 * قوالب واتساب في TemplateController كما هي، لم تُمسّ.
 */
class ChannelTemplateController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns)
    {
    }

    public function index(string $channel)
    {
        $organizationId = $this->organizationId();

        $templates = ChannelTemplate::forOrganization($organizationId)
            ->forChannel($channel)
            ->orderBy('name')
            ->get(['uuid', 'name', 'subject', 'body', 'media', 'updated_at'])
            ->map(fn (ChannelTemplate $template) => [
                'uuid' => (string) $template->uuid,
                'name' => $template->name,
                'subject' => $template->subject,
                'excerpt' => mb_substr((string) $template->body, 0, 140),
                'has_media' => !empty($template->media['url'] ?? null),
                'updated_at' => $template->updated_at?->toDateTimeString(),
            ]);

        return Inertia::render('User/Templates/Channel/Index', [
            'title' => __('Message templates'),
            'channel' => $channel,
            'channelLabel' => __(Channel::label($channel)),
            'templates' => $templates,
        ]);
    }

    public function create(string $channel)
    {
        return $this->form($channel, null);
    }

    public function edit(string $channel, string $uuid)
    {
        return $this->form($channel, $this->find($channel, $uuid));
    }

    public function store(StoreChannelTemplate $request, string $channel)
    {
        $organizationId = $this->organizationId();

        // الحقول تُبنى صراحةً: المنشأة من الجلسة، والقناة من المسار.
        $template = ChannelTemplate::create([
            'organization_id' => $organizationId,
            'channel' => $channel,
            'name' => $request->input('name'),
            'subject' => $channel === Channel::EMAIL ? $request->input('subject') : null,
            'body' => $request->input('body'),
            'media' => $this->uploadedMedia($request, $organizationId, $channel),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        ActivityLogger::log(ActivityLogger::TEMPLATE_CREATED, $template->name, 'template');

        return Redirect::to('/templates/channel/' . $channel)->with('status', [
            'type' => 'success',
            'message' => __('Template saved. It is ready to use in campaigns.'),
        ]);
    }

    public function update(StoreChannelTemplate $request, string $channel, string $uuid)
    {
        $template = $this->find($channel, $uuid);
        $organizationId = $this->organizationId();

        $media = $template->media;

        if ($request->hasFile('media')) {
            $media = $this->uploadedMedia($request, $organizationId, $channel);
        } elseif ($request->boolean('remove_media')) {
            $media = null;
        }

        // الملف القديم لا يُحذف: حملة مجدولة نسخت رابطه.
        $template->update([
            'name' => $request->input('name'),
            'subject' => $channel === Channel::EMAIL ? $request->input('subject') : null,
            'body' => $request->input('body'),
            'media' => $channel === Channel::EMAIL ? null : $media,
            'updated_by' => auth()->id(),
        ]);

        return Redirect::to('/templates/channel/' . $channel)->with('status', [
            'type' => 'success',
            'message' => __('Template saved. It is ready to use in campaigns.'),
        ]);
    }

    public function destroy(string $channel, string $uuid)
    {
        $template = $this->find($channel, $uuid);
        $template->delete();

        ActivityLogger::log(ActivityLogger::TEMPLATE_DELETED, $template->name, 'template');

        return Redirect::back()->with('status', [
            'type' => 'success',
            'message' => __('Row deleted successfully!'),
        ]);
    }

    private function form(string $channel, ?ChannelTemplate $template)
    {
        return Inertia::render('User/Templates/Channel/Form', [
            'title' => __('Message templates'),
            'channel' => $channel,
            'channelLabel' => __(Channel::label($channel)),
            'rules' => CampaignChannels::rules($channel),
            'placeholders' => ContactPlaceholderService::optionsForOrganization($this->organizationId()),
            'template' => $template ? [
                'uuid' => (string) $template->uuid,
                'name' => $template->name,
                'subject' => $template->subject,
                'body' => $template->body,
                'media' => $template->media,
            ] : null,
        ]);
    }

    private function find(string $channel, string $uuid): ChannelTemplate
    {
        return ChannelTemplate::forOrganization($this->organizationId())
            ->forChannel($channel)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function uploadedMedia(Request $request, int $organizationId, string $channel): ?array
    {
        if ($channel === Channel::EMAIL || !$request->hasFile('media')) {
            return null;
        }

        return $this->campaigns->storeValidatedChannelMedia($request->file('media'), $organizationId, $channel, 'media');
    }

    private function organizationId(): int
    {
        return (int) session()->get('current_organization');
    }
}
