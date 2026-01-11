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
        //dd($metadata);

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
        
        if ($metadata->body && property_exists($metadata->body, 'parameters')) {
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
        //dd($metadata->header);
        $headerComponent = [
            'type' => 'header',
            'parameters' => [],
        ];

        if($metadata->header->parameters){
            foreach($metadata->header->parameters as $parameter){
                $param['type'] = strtolower($parameter->type);
                if($parameter->type === 'IMAGE'){
                    $param['image']['link'] = $parameter->value;
                } else if($parameter->type === 'VIDEO') {
                    $param['video']['link'] = $parameter->value;
                } else if($parameter->type === 'DOCUMENT') {
                    $param['document']['link'] = $parameter->value;
                } else if($parameter->type === 'text') {
                    $param['text'] = $parameter->selection === 'static' 
                    ? $parameter->value : $this->getParameters($contact, $parameter->value);
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
                $param['text'] = $parameter->selection === 'static' 
                    ? $parameter->value : $this->getParameters($contact, $parameter->value);
    
                $bodyComponent['parameters'][] = $param;
            }
        }

        return $bodyComponent;
    }

    function buildButtonComponent($metadata, $contact) {
        //dd($metadata->buttons);
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
                        $param['payload'] = $parameter->type === 'static' 
                            ? $parameter->value : $this->getParameters($contact, $parameter->value);
                    } else if($button->type === 'URL'){
                        $param['type'] = 'text';
                        $param['text'] = $parameter->type === 'static' 
                            ? $parameter->value : $this->getParameters($contact, $parameter->value);
                    } else if($button->type === 'COPY_CODE'){
                        $param['type'] = 'coupon_code';
                        $param['coupon_code'] = $parameter->type === 'static' 
                        ? $parameter->value : $this->getParameters($contact, $parameter->value);
                    }
        
                    $buttonComponent[$buttonIndex]['parameters'][] = $param;
                }

                $buttonIndex++;
            }
        }

        //dd($buttonComponent);
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
    }
}