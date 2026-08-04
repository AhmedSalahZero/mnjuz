<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:2000',
            // موعد مستقبلي فقط — حجز موعد مضى بلا معنى.
            'start' => 'required|date|after:now',
            'reason' => ['required', Rule::in(array_keys(config('waz.meetings.colors', [])))],
        ];
    }
}
