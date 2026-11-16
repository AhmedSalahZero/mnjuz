<?php

namespace App\Http\Requests;

use App\Models\ChannelTemplate;
use App\Services\Messaging\CampaignChannels;
use App\Services\Messaging\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * قالب محفوظ لقناة بلا موافقة. القناة من المسار لا من الطلب.
 *
 * تيليجرام: نصّ ومرفق اختياري — والنصّ تحت مرفق تعليقٌ حدّه 1024 حرفاً.
 * البريد: موضوع ومتن بلا مرفقات.
 */
class StoreChannelTemplate extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $channel = (string) $this->route('channel');
        $organizationId = (int) session()->get('current_organization');
        $current = $this->currentTemplate();

        $rules = [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('channel_templates', 'name')
                    ->where('organization_id', $organizationId)
                    ->where('channel', $channel)
                    ->whereNull('deleted_at')
                    ->ignore($current?->id),
            ],
        ];

        if ($channel === Channel::EMAIL) {
            return $rules + [
                'subject' => ['required', 'string', 'max:' . CampaignChannels::EMAIL_SUBJECT_MAX, 'not_regex:/[\x00-\x1F\x7F]/'],
                'body' => ['required', 'string', 'max:' . CampaignChannels::EMAIL_TEXT_MAX],
                'media' => ['prohibited'],
            ];
        }

        return $rules + [
            'body' => ['required', 'string', 'max:' . CampaignChannels::textLimit($channel, $this->willHaveMedia($current))],
            'media' => ['nullable', 'file', function ($attribute, $file, $fail) {
                $extension = strtolower((string) $file->getClientOriginalExtension());

                if (in_array($extension, CampaignChannels::BLOCKED_ATTACHMENT_EXTENSIONS, true)) {
                    $fail(__('This file type cannot be sent as an attachment.'));
                }
            }],
            'remove_media' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('A template with this name already exists for this channel.'),
            'subject.not_regex' => __('The subject must be on one line.'),
            'body.max' => $this->willHaveMedia($this->currentTemplate()) && $this->route('channel') === Channel::TELEGRAM
                ? __('With an attachment Telegram allows up to :max characters.', ['max' => CampaignChannels::TELEGRAM_CAPTION_MAX])
                : __('The message may not be longer than :max characters.', ['max' => CampaignChannels::textLimit((string) $this->route('channel'))]),
            'media.prohibited' => __('Email campaigns cannot carry attachments. Link the file in the message instead.'),
        ];
    }

    /** القالب الذي يُعدَّل (مقيّد بالمنشأة والقناة)، أو null عند الإنشاء. */
    public function currentTemplate(): ?ChannelTemplate
    {
        $uuid = $this->route('uuid');

        if (!$uuid) {
            return null;
        }

        return ChannelTemplate::forOrganization((int) session()->get('current_organization'))
            ->forChannel((string) $this->route('channel'))
            ->where('uuid', $uuid)
            ->first();
    }

    /** سيحمل القالب مرفقاً بعد الحفظ؟ (ملف جديد، أو القديم ما لم يُحذف) */
    private function willHaveMedia(?ChannelTemplate $current): bool
    {
        if ($this->hasFile('media')) {
            return true;
        }

        return $current !== null && !empty($current->media['url'] ?? null) && !$this->boolean('remove_media');
    }
}
