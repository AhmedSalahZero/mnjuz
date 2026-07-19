<?php

namespace App\Traits;

use App\Models\Campaign;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait TemplateTrait{
    function buildTemplateRequest($campaignId, $contact){
        $campaign = Campaign::where('id', $campaignId)->first();
        $campaignTemplate = Template::where('id', $campaign->template_id)->first();

        $metadata = json_decode($campaign->metadata);

        return $this->buildTemplate($campaignTemplate->name, $campaignTemplate->language, $metadata, $contact);
    }

    function buildTemplate($templateName, $templateLanguage, $metadata, $contact){
        $template['name'] = $templateName;
        $template['language']['code'] = $templateLanguage;
        $template['components'] = [];

        if ($metadata->header && $metadata->header->parameters) {
            $headerComponent = $this->buildHeaderComponent($metadata, $contact);
            $template['components'][] = $headerComponent;
        }
        
        if ($metadata->body && property_exists($metadata->body, 'parameters') && !empty($metadata->body->parameters)) {
            $bodyComponent = $this->buildBodyComponent($metadata, $contact);
            $template['components'][] = $bodyComponent;
        }

        if ($metadata->buttons) {
            $buttonComponents = $this->buildButtonComponent($metadata, $contact);
            foreach ($buttonComponents as $buttonComponent) {
                $template['components'][] = $buttonComponent;
            }
        }

        //\Log::info($template);
        return $template;
    }

    function buildHeaderComponent($metadata, $contact) {
        $headerComponent = [
            'type' => 'header',
            'parameters' => [],
        ];

        if($metadata->header->parameters){
            foreach($metadata->header->parameters as $parameter){
                $param = [];
                $param['type'] = strtolower($parameter->type);
                if($parameter->type === 'IMAGE'){
                    $param['image']['link'] = $parameter->value;
                } else if($parameter->type === 'VIDEO') {
                    $param['video']['link'] = $parameter->value;
                } else if($parameter->type === 'DOCUMENT') {
                    $param['document']['link'] = $parameter->value;
                } else if($parameter->type === 'text') {
                    $param['text'] = $this->resolveTemplateTextValue($contact, $parameter);
                }
    
                $headerComponent['parameters'][] = $param;
            }
        }

        return $headerComponent;
    }
    
    function buildBodyComponent($metadata, $contact) {
        $bodyComponent = [
            'type' => 'body',
            'parameters' => [],
        ];

        if($metadata->body->parameters){
            foreach($metadata->body->parameters as $parameter){
                $param['type'] = $parameter->type;
                $param['text'] = $this->resolveTemplateTextValue($contact, $parameter);
    
                $bodyComponent['parameters'][] = $param;
            }
        }

        return $bodyComponent;
    }

    function buildButtonComponent($metadata, $contact) {
        $buttons = $metadata->buttons;
        $buttonComponent = [];
        $buttonIndex = 0;

        foreach($buttons as $key => $button){
            if(!empty($button->parameters)){
                $buttonComponent[] = [
                    'type' => 'button',
                    'sub_type' => strtolower($button->type),
                    'index' => $key,
                    'parameters' => [],
                ];

                foreach($button->parameters as $parameter){
                    $param = [];

                    if($button->type === 'QUICK_REPLY'){
                        $param['type'] = 'payload';
                        $param['payload'] = $this->resolveTemplateTextValue($contact, $parameter);
                    } else if($button->type === 'URL'){
                        $param['type'] = 'text';
                        $param['text'] = $this->resolveTemplateTextValue($contact, $parameter);
                    } else if($button->type === 'COPY_CODE'){
                        $param['type'] = 'coupon_code';
                        $param['coupon_code'] = $this->resolveTemplateTextValue($contact, $parameter);
                    }
        
                    $buttonComponent[$buttonIndex]['parameters'][] = $param;
                }

                $buttonIndex++;
            }
        }

        return $buttonComponent;
    }

    function getParameters($contact, $parameter){
        if($parameter === 'first name'){
            return $contact->first_name;
        } else if($parameter === 'last name'){
            return $contact->last_name;
        } else if($parameter === 'name'){
            return $contact->first_name . ' ' . $contact->last_name;
        } else if($parameter === 'email'){
            return $contact->email;
        } else if($parameter === 'phone'){
            return $contact->phone;
        }

        return null;
    }

    private function resolveTemplateTextValue($contact, $parameter): string
    {
        $selection = $parameter->selection ?? $parameter->type ?? 'static';
        $rawValue = $selection === 'static'
            ? ($parameter->value ?? '')
            : $this->getParameters($contact, $parameter->value ?? '');

        $value = trim((string) ($rawValue ?? ''));
        if ($value !== '') {
            return $value;
        }

        $fallback = trim((string) ($contact->phone ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return '-';
    }
}
