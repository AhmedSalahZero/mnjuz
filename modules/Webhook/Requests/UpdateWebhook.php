<?php

namespace Modules\Webhook\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebhook extends FormRequest
{
    public function rules()
    {
        return [
            'url' => 'required|url',
            'events' => 'required|array',
            'events.*' => 'string', // Assuming events are strings
        ];
    }

    public function authorize()
    {
        return true; // Adjust as necessary for your authorization logic
    }
}