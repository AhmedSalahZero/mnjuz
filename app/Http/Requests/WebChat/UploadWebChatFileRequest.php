<?php

namespace App\Http\Requests\WebChat;

use App\Services\WebChat\WebChatUploads;
use Illuminate\Validation\Validator;

/**
 * ملف من الزائر. النوع يُحدَّد من محتوى الملف (finfo) لا من اسمه ولا من
 * ترويسة المتصفّح — وقائمة مسموحة صريحة: لا SVG ولا HTML ولا ما يُنفَّذ،
 * لأن الملف يُخدم لاحقاً من نطاقنا.
 */
class UploadWebChatFileRequest extends WebChatRequest
{
    private ?array $classified = null;

    public function rules(): array
    {
        return [
            'client_message_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:' . (int) ceil(WebChatUploads::maxBytes() / 1024)],
            'caption' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('file')) {
                return;
            }

            $classified = WebChatUploads::classify($this->file('file'));

            if ($classified === null) {
                $validator->errors()->add('file', __('This file type is not supported.'));

                return;
            }

            if ($classified['size'] > WebChatUploads::limitFor($classified['type'])) {
                $validator->errors()->add('file', __('The file is too large.'));

                return;
            }

            $this->classified = $classified;
        });
    }

    /** @return array{type: string, mime_type: string, extension: string, size: int, file_name: string} */
    public function classified(): array
    {
        return $this->classified;
    }

    public function caption(): ?string
    {
        $caption = self::cleanText($this->input('caption'), 1024);

        return $caption === '' ? null : $caption;
    }

    public function clientMessageId(): string
    {
        return strtolower((string) $this->input('client_message_id'));
    }
}
