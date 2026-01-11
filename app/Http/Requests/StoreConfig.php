<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConfig extends FormRequest
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
        $rules = [];
        
        if ($this->type == 'general') {
            $rules['company_name'] = 'required';
            $rules['head_scripts'] = 'nullable|string|max:5000';
            $rules['head_styles'] = 'nullable|string|max:5000';
            $rules['body_scripts'] = 'nullable|string|max:5000';
            $rules['meta_tags'] = 'nullable|string|max:2000';

            if($this->recaptcha_active == 1){
                $rules['recaptcha_site_key'] = 'required';
                $rules['recaptcha_secret_key'] = 'required';
            }
        }

        if ($this->type == 'timezone') {
            $rules['timezone'] = 'required';
            $rules['currency'] = 'required';
            $rules['date_format'] = 'required';
            $rules['time_format'] = 'required';
        }

        if ($this->type == 'broadcast') {
            $rules['broadcast_driver'] = 'required';
            $rules['pusher_app_key'] = 'required';
            $rules['pusher_app_id'] = 'required';
            $rules['pusher_app_secret'] = 'required';
            $rules['pusher_app_cluster'] = 'required';
        }

        if ($this->type == 'socials') {
            if($this->allow_facebook_login){
                $rules['facebook_login.client_id'] = 'required';
                $rules['facebook_login.client_secret'] = 'required';
            }

            if($this->allow_google_login){
                $rules['google_login.client_id'] = 'required';
                $rules['google_login.client_secret'] = 'required';
            }
        }

        if ($this->type == 'subscription') {
            $rules['trial_period'] = 'required|numeric|gte:0';
            $rules['trial_limits.users'] = 'required|numeric|gte:-1';
            $rules['trial_limits.messages'] = 'required|numeric|gte:-1';
            $rules['trial_limits.campaigns'] = 'required|numeric|gte:-1';
            $rules['trial_limits.contacts'] = 'required|numeric|gte:-1';
            $rules['trial_limits.automated_replies'] = 'required|numeric|gte:-1';
        }

        if ($this->type == 'email') {
            $rules['mail_config.driver'] = 'required';
            $rules['mail_config.from_name'] = 'required';
            $rules['mail_config.from_address'] = 'required';
            $rules['mail_config.reply_to_name'] = 'required';
            $rules['mail_config.reply_to_address'] = 'required';

            if($this->mail_config['driver'] === 'smtp'){
                $rules['mail_config.host'] = 'required';
                $rules['mail_config.port'] = 'required';
                $rules['mail_config.username'] = 'required';
                $rules['mail_config.password'] = 'required';
            } else if($this->mail_config['driver'] === 'mailgun'){
                $rules['mail_config.mg_domain'] = 'required';
                $rules['mail_config.mg_secret'] = 'required';
            } else if($this->mail_config['driver'] === 'ses'){
                $rules['mail_config.ses_key'] = 'required';
                $rules['mail_config.ses_secret'] = 'required';
                $rules['mail_config.ses_region'] = 'required';
            }
        }

        if ($this->type == 'storage') {
            if($this->storage_system === 'aws'){
                $rules['aws.access_key'] = 'required';
                $rules['aws.secret_key'] = 'required';
                $rules['aws.default_region'] = 'required';
                $rules['aws.bucket'] = 'required';
            }
        }

        if ($this->type == 'billing') {
            $rules['billing_name'] = 'required';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'required' => __('This field is required.'),
            'trial_period.gte' => __('This field must be greater than or equal to 0.'),
            'trial_limits.users.gte' => __('This field must be greater than or equal to -1.'),
            'trial_limits.messages.gte' => __('This field must be greater than or equal to -1.'),
            'trial_limits.campaigns.gte' => __('This field must be greater than or equal to -1.'),
            'trial_limits.contacts.gte' => __('This field must be greater than or equal to -1.'),
            'trial_limits.automated_replies.gte' => __('This field must be greater than or equal to -1.'),
        ];
    }
}
