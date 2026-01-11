<?php

namespace Modules\FlowBuilder\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\FlowBuilder\Jobs\ProcessDelayedFlowJob;

class ActionExecutionService
{
    private $whatsappService;
    private $organizationId;
    private $organization;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->organization = Organization::find($organizationId);
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
        $config = $this->organization->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    /**
     * Execute an action based on its type and configuration
     *
     * @param string $actionType
     * @param array $config
     * @param Contact $contact
     * @param string $userMessage
     * @param object $flowData
     * @param int $contactId
     * @return bool|string
     */
    public function executeAction($actionType, $config, $contact, $userMessage = '', $flowData = null, $contactId = null)
    {
		 logger('inside execute action');
        try {
            switch ($actionType) {
                case 'add_to_group':
                    return $this->executeAddToGroup($config, $contact);
                
                case 'remove_from_group':
                    return $this->executeRemoveFromGroup($config, $contact);
                
                case 'update_contact':
                    return $this->executeUpdateContact($config, $contact, $userMessage);
                
                case 'send_email':
                    return $this->executeSendEmail($config, $contact);
                
                case 'delay':
                    return $this->executeDelay($config, $contact, $flowData, $contactId);
                
                case 'webhook':
                    return $this->executeWebhook($config, $contact, $userMessage);
                
                case 'ai_response':
                    return $this->executeAIResponse($config, $contact, $userMessage);
                
                case 'conditional':
                    return $this->executeConditional($config, $contact, $userMessage);
                
                default:
                    Log::warning("Unknown action type: {$actionType}");
                    return false;
            }
        } catch (\Exception $e) {
            Log::error("Error executing action {$actionType}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add contact to a group
     */
    private function executeAddToGroup($config, $contact)
    {
        $groupId = $config['group_id'] ?? null;
        if (!$groupId) {
            Log::warning("No group_id specified for add_to_group action");
            return false;
        }

        $group = ContactGroup::where('id', $groupId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (!$group) {
            Log::warning("Group not found: {$groupId}");
            return false;
        }

        // Check if contact is already in the group
        if (!$contact->contactGroups()->where('contact_group_id', $groupId)->exists()) {
            $contact->contactGroups()->attach($groupId);
            // Log::info("Contact {$contact->id} added to group {$groupId}");
        }

        return true;
    }

    /**
     * Remove contact from a group
     */
    private function executeRemoveFromGroup($config, $contact)
    {
        $groupId = $config['group_id'] ?? null;
        if (!$groupId) {
            Log::warning("No group_id specified for remove_from_group action");
            return false;
        }
        $contact->contactGroups()->detach($groupId);
        // Log::info("Contact {$contact->id} removed from group {$groupId}");
        
        return true;
    }

    /**
     * Update contact field with user's message
     */
    private function executeUpdateContact($config, $contact, $userMessage)
    {
        $targetField = $config['target_field'] ?? null;
        if (!$targetField || !$userMessage) {
            Log::warning("No target_field or userMessage for update_contact action");
            return false;
        }

        // Map field names to actual contact attributes
        $fieldMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'phone' => 'phone',
            'address.street' => 'address->street',
            'address.city' => 'address->city',
            'address.state' => 'address->state',
            'address.zip' => 'address->zip',
            'address.country' => 'address->country'
        ];

        if (isset($fieldMapping[$targetField])) {
            $field = $fieldMapping[$targetField];
            
            // Validate email field
            if ($targetField === 'email' && !empty($userMessage)) {
                if (!filter_var($userMessage, FILTER_VALIDATE_EMAIL)) {
                    Log::warning("Invalid email format provided: {$userMessage}");
                    
                    // Send invalid email message if configured
                    $invalidMessage = $config['invalid_email_message'] ?? 'Please provide a valid email address.';
                    if (!empty($invalidMessage)) {
                        $this->whatsappService->sendMessage($contact->uuid, $invalidMessage);
                    }
                    
                    return false;
                }
            }
            
            if (str_contains($field, '->')) {
                // Handle nested JSON fields like address
                $parts = explode('->', $field);
                $jsonField = $parts[0];
                $jsonKey = $parts[1];
                
                $currentValue = $contact->$jsonField ? json_decode($contact->$jsonField, true) : [];
                $currentValue[$jsonKey] = $userMessage;
                $contact->$jsonField = json_encode($currentValue);
            } else {
                // Handle regular fields
                $contact->$field = $userMessage;
            }
            
            $contact->save();
            // Log::info("Contact {$contact->id} field {$targetField} updated with: {$userMessage}");
            return true;
        } else {
            // Handle custom metadata fields
            $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
            $metadata[$targetField] = $userMessage;
            $contact->metadata = json_encode($metadata);
            $contact->save();
            
            // Log::info("Contact {$contact->id} metadata field {$targetField} updated with: {$userMessage}");
            return true;
        }
    }

    /**
     * Send email to contact
     */
    private function executeSendEmail($config, $contact)
    {
        if (!$contact->email) {
            Log::warning("Contact {$contact->id} has no email address");
            return false;
        }

        $subject = $config['subject'] ?? '';
        $body = $config['body'] ?? '';
        $smtpHost = $config['smtp_host'] ?? '';
        $smtpPort = $config['smtp_port'] ?? 587;
        $smtpUsername = $config['smtp_username'] ?? '';
        $smtpPassword = $config['smtp_password'] ?? '';
        $smtpEncryption = $config['smtp_encryption'] ?? 'tls';
        $fromName = $config['from_name'] ?? $this->organization->name;
        $fromEmail = $config['from_email'] ?? '';

        // If no from_email is specified, try to use smtp_username if it's a valid email
        if (empty($fromEmail)) {
            if (filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
                $fromEmail = $smtpUsername;
            } else {
                Log::warning("No valid from_email specified and smtp_username is not a valid email address");
                return false;
            }
        }

        if (!$subject || !$body || !$smtpHost || !$smtpUsername || !$smtpPassword) {
            Log::warning("Incomplete email configuration for send_email action");
            return false;
        }

        // Replace placeholders in subject and body
        $subject = $this->replacePlaceholders($contact, $subject);
        $body = $this->replacePlaceholders($contact, $body);

        try {
            // Configure mail settings
            config([
                'mail.mailers.smtp.host' => $smtpHost,
                'mail.mailers.smtp.port' => $smtpPort,
                'mail.mailers.smtp.username' => $smtpUsername,
                'mail.mailers.smtp.password' => $smtpPassword,
                'mail.mailers.smtp.encryption' => $smtpEncryption,
            ]);

            Mail::raw($body, function ($message) use ($contact, $subject, $fromName, $fromEmail) {
                $message->to($contact->email)
                        ->subject($subject)
                        ->from($fromEmail, $fromName);
            });

            // Log::info("Email sent to {$contact->email}: {$subject}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send email: " . $e->getMessage());
            return false;
        }
    }
	
	private function executeDelay($config, $contact, $flowData = null, $contactId = null)
{
    $duration = $config['duration'] ?? 5;
    
    if ($flowData && $contactId) {
        // ✅ الحل: إيجاد الـ target node الصحيح من الـ edges
        $flow = \Modules\FlowBuilder\Models\Flow::find($flowData->flow_id);
        
        if (!$flow || empty($flow->metadata)) {
            Log::warning("Flow not found or empty metadata for delay action");
            return false;
        }
        
        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", []);
        
        // البحث عن الـ edge الذي source = current_step
        $nextStepId = null;
        foreach ($edges as $edge) {
            if (isset($edge['source']) && (string)$edge['source'] === (string)$flowData->current_step) {
                // وجدنا الـ edge!
                $nextStepId = $edge['targetNode']['id'] ?? null;
                break;
            }
        }
        
        if (!$nextStepId) {
            Log::warning("No next step found for delay action", [
                'current_step' => $flowData->current_step,
                'flow_id' => $flowData->flow_id,
            ]);
            return false;
        }
        logger('trigger new job here');
        // ✅ تحديث current_step للـ node الصحيح
    
        
        // ✅ جدولة الـ Job
        ProcessDelayedFlowJob::dispatch(
			$flowData->id,
            $contactId, 
            $flowData->flow_id, 
            $nextStepId,  // ✅ الـ ID الصحيح من الـ edges
            $this->organizationId
        )->delay(now()->addMinutes($duration));
        
     
        
        return 'delayed';
    }
    
    Log::warning("Delay fallback mode (sleep)");
    sleep($duration * 60);
    return true;
}


    /**
     * Execute delay action
     */
//     private function executeDelay($config, $contact, $flowData = null, $contactId = null)
//     {
//         $duration = $config['duration'] ?? 5; // in minutes
//         // If we have flow data, schedule a delayed job to continue the flow
//         if ($flowData && $contactId) {
// 			logger('inside sleep if with duration'.$duration);
//             // Create a delayed job that will continue the flow after the delay
//             \Modules\FlowBuilder\Jobs\ProcessDelayedFlowJob::dispatch($contactId, $flowData->flow_id, $flowData->current_step, $this->organizationId)
//                 ->delay(now()->addMinutes($duration));
            
//             // Log::info("Delay action scheduled for {$duration} minutes for contact {$contactId}");
//             return 'delayed'; // Return special value to indicate the flow is paused
//         }
        
//         // Fallback: simple sleep for testing (not recommended for production)
//         // Log::info("Delay action executed for {$duration} minutes (fallback mode)");
// 		logger('before sleep method');
//    //     sleep($duration * 60);
        
//         return true;
//     }

    /**
     * Execute webhook action
     */
    private function executeWebhook($config, $contact, $userMessage)
    {
        $url = trim($config['url'] ?? '');
        $method = strtoupper(trim($config['method'] ?? 'POST'));

        if (!$url) {
            Log::warning("No URL specified for webhook action");
            return false;
        }

        // Prepare payload
        $payload = [
            'contact' => [
                'uuid' => $contact->uuid,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'address' => $contact->address ? json_decode($contact->address, true) : [],
                'metadata' => $contact->metadata ? json_decode($contact->metadata, true) : [],
            ],
            'message' => $userMessage,
            'timestamp' => now()->toISOString(),
        ];

        try {
            $response = Http::timeout(30)->send($method, $url, [
                'json' => $payload,
            ]);

            if ($response->successful()) {
                // Log::info("Webhook executed successfully: {$url}");
                return true;
            } else {
                Log::warning("Webhook failed with status {$response->status()}: {$url}");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Webhook execution error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute AI response action
     */
    private function executeAIResponse($config, $contact, $userMessage)
    {
        $prompt = $config['prompt'] ?? '';
        $proceedCondition = $config['proceed_condition'] ?? 'always';
        $confidenceThreshold = $config['confidence_threshold'] ?? 0.8;

        if (!$prompt) {
            Log::warning("No prompt specified for ai_response action");
            return false;
        }

        // Replace placeholders in prompt
        $prompt = $this->replacePlaceholders($contact, $prompt);

        try {
            // Here you would integrate with your AI service
            // For now, we'll simulate an AI response
            $aiResponse = "AI Response: " . substr($prompt, 0, 100) . "...";
            $confidence = 0.9; // Simulated confidence score

            // Send AI response via WhatsApp
            $this->whatsappService->sendMessage($contact->uuid, $aiResponse);

            // Check if we should proceed based on confidence
            if ($proceedCondition === 'confidence_threshold' && $confidence < $confidenceThreshold) {
                // Log::info("AI response confidence below threshold, stopping flow");
                return false;
            }

            // Log::info("AI response sent to contact {$contact->id}");
            return true;
        } catch (\Exception $e) {
            Log::error("AI response execution error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute conditional action
     */
    private function executeConditional($config, $contact, $userMessage)
    {
        $conditionType = $config['condition_type'] ?? 'message_contains';
        $conditions = $config['conditions'] ?? [];
        $fieldName = $config['field_name'] ?? '';
        $fieldOperator = $config['field_operator'] ?? 'equals';

        // This method should return the condition that matches
        // The actual flow branching logic is handled in FlowExecutionService
        foreach ($conditions as $index => $condition) {
            $conditionValue = $condition['value'] ?? '';
            
            if ($this->evaluateCondition($conditionType, $conditionValue, $userMessage, $contact, $fieldName, $fieldOperator)) {
                // Log::info("Conditional action: condition {$index} matched");
                return $index; // Return the condition index that matched
            }
        }

        // Log::info("Conditional action: no conditions matched, using default");
        return 'default'; // Return 'default' if no conditions match
    }

    /**
     * Evaluate a single condition
     */
    private function evaluateCondition($conditionType, $conditionValue, $userMessage, $contact, $fieldName, $fieldOperator)
    {
        switch ($conditionType) {
            case 'message_contains':
                return stripos($userMessage, $conditionValue) !== false;
            
            case 'message_equals':
                return strtolower(trim($userMessage)) === strtolower(trim($conditionValue));
            
            case 'contact_field':
                if (!$fieldName) return false;
                
                $fieldValue = $this->getContactFieldValue($contact, $fieldName);
                return $this->compareValues($fieldValue, $fieldOperator, $conditionValue);
            

            
            default:
                return false;
        }
    }

    /**
     * Get contact field value
     */
    private function getContactFieldValue($contact, $fieldName)
    {
        $fieldMapping = [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];

        if (isset($fieldMapping[$fieldName])) {
            return $fieldMapping[$fieldName];
        }

        // Handle address fields
        if (str_starts_with($fieldName, 'address.')) {
            $address = $contact->address ? json_decode($contact->address, true) : [];
            $key = str_replace('address.', '', $fieldName);
            return $address[$key] ?? '';
        }

        // Handle metadata fields
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
        return $metadata[$fieldName] ?? '';
    }

    /**
     * Compare values based on operator
     */
    private function compareValues($fieldValue, $operator, $conditionValue)
    {
        switch ($operator) {
            case 'equals':
                return strtolower(trim($fieldValue)) === strtolower(trim($conditionValue));
            
            case 'contains':
                return stripos($fieldValue, $conditionValue) !== false;
            
            case 'not_equals':
                return strtolower(trim($fieldValue)) !== strtolower(trim($conditionValue));
            
            case 'is_empty':
                return empty(trim($fieldValue));
            
            case 'is_not_empty':
                return !empty(trim($fieldValue));
            
            default:
                return false;
        }
    }

    /**
     * Replace placeholders in text
     */
    private function replacePlaceholders($contact, $text)
    {
        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
        
        $fullAddress = ($address['street'] ?? '') . ', ' .
                      ($address['city'] ?? '') . ', ' .
                      ($address['state'] ?? '') . ', ' .
                      ($address['zip'] ?? '') . ', ' .
                      ($address['country'] ?? '');

        $placeholders = [
            '{first_name}' => $contact->first_name ?? '',
            '{last_name}' => $contact->last_name ?? '',
            '{full_name}' => $contact->full_name ?? '',
            '{email}' => $contact->email ?? '',
            '{phone}' => $contact->phone ?? '',
            '{organization_name}' => $this->organization->name ?? '',
            '{full_address}' => $fullAddress,
            '{street}' => $address['street'] ?? '',
            '{city}' => $address['city'] ?? '',
            '{state}' => $address['state'] ?? '',
            '{zip_code}' => $address['zip'] ?? '',
            '{country}' => $address['country'] ?? '',
        ];

        // Add metadata placeholders
        foreach ($metadata as $key => $value) {
            $transformedKey = strtolower(str_replace(' ', '_', $key));
            $placeholders['{' . $transformedKey . '}'] = $value;
        }

        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }
} 
