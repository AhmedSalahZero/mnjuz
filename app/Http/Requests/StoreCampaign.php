<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\CampaignLimit;

class StoreCampaign extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required',
            'template' => 'required',
            'contacts' => 'required',
        ];

        if ($this->isMethod('post')) {
            $rules['name'] = ['required', new CampaignLimit];
        } else {
            $rules['name'] = ['required'];
        }
    
        if (!$this->input('skip_schedule')) {
            $rules['time'] = 'required';
        }

        // Check for header.format and header.parameters[0].value
        $headerParams = $this->input('header.parameters');

        if(!empty($headerParams)){
            $format = $this->input('header.format');

            // Rules for image format
            if ($format === 'TEXT') {
                $rules['header.parameters.0.value'] = 'required|max:60'; // Max 60 characters
            }

            // Rules for image format
            if ($format === 'IMAGE') {
                $rules['header.parameters.0.value'] = 'required|image|mimes:png,jpg|max:5120'; // Max 5MB
            }

            // Rules for video format
            if ($format === 'VIDEO') {
                $rules['header.parameters.0.value'] = 'required|file|mimes:mp4|max:16384'; // Max 5MB
            }

            // Rules for document format
            if ($format === 'DOCUMENT') {
                $rules['header.parameters.0.value'] = 'required|file|mimes:pdf,txt,ppt,doc,xls,docx,pptx,xlsx|max:102400'; // Max 100MB
            }
        }

        // Check for body.parameters[0].value
        $bodyParams = $this->input('body.parameters');
        $bodyParamValue = $this->input('body.parameters.0.value');

        if(!empty($bodyParams)){
            $rules['body.parameters.0.value'] = 'required|max:1028';
        }


        // Check each button for specific validation rules
        $buttons = $this->input('buttons', []);

        foreach ($buttons as $index => $button) {
            $buttonType = $button['type'];
            
            switch ($buttonType) {
                case 'QUICK_REPLY':
                    $rules["buttons.$index.parameters.0.value"] = 'required|max:25'; // Adjust max as needed
                    break;
                case 'COPY_CODE':
                    $rules["buttons.$index.parameters.0.value"] = 'required|max:15'; // Adjust max as needed
                    break;
                case 'URL':
                    // Check if URL has parameters
                    if (!empty($button['parameters'])) {
                        $rules["buttons.$index.parameters.0.value"] = 'required|url|max:2000'; // Adjust max as needed
                    }
                    break;
                // Add more cases for other button types as needed
            }
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'name.required' => 'The name field is required.',
            'template.required' => 'The template field is required.',
            'contacts.required' => 'The contacts field is required.',
            'header.parameters.0.value.required' => 'This field is required.',
            'header.parameters.0.value.max' => 'The value must not exceed :max characters.',
            'header.parameters.0.value.image' => 'The value must be an image (PNG or JPG) and should not exceed 5MB.',
            'header.parameters.0.value.video' => 'The value must be a video (MP4) and should not exceed 16MB.',
            'body.parameters.0.value.required' => 'This field is required.',
            'body.parameters.0.value.max' => 'The value must not exceed :max characters.',
            'buttons.*.parameters.*.value.required' => 'This field is required.',
            'buttons.*.parameters.*.value.max' => 'The value must not exceed :max characters.',
            'buttons.*.parameters.*.value.url' => 'This value is not a valid url',
            // Add other custom messages as needed
        ];
    }
}
