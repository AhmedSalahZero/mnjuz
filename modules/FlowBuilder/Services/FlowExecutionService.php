<?php

namespace Modules\FlowBuilder\Services;

use App\Helpers\CustomHelper;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowLog;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Services\ActionExecutionService;

class FlowExecutionService
{
    private $whatsappService;
    private $organizationId;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }
    
    /**
     * Execute a flow for a user based on their input.
     *
     * @param $chat
     * @param boolean $isNewContact
     * @param string $message
     * @return FlowStep|null
     */
    public function executeFlow($chat, $isNewContact, $message)
    {
        if(CustomHelper::isModuleEnabled('Flow builder', $chat->organization_id)){
            // Find the current step for the user in the flow
            $flowData = FlowUserData::where('contact_id', $chat->contact_id)->first();
            $flowId = null;
		//	logger('--inside execute flow');
            if($flowData && $flowData->exists){
                // Check if the flow still exists in the database
                $flow = Flow::find($flowData->flow_id);
                
                if(!$flow){
		//			logger('--flow deleted');
                    // Flow has been deleted, remove FlowUserData and proceed as if it doesn't exist
                    Log::warning("DELETION POINT 1: Flow not found, deleting FlowUserData for contact {$chat->contact_id}");
                    FlowUserData::where('contact_id', $chat->contact_id)->delete();
                    $flowData = null;
                } else {
                    // Flow exists, proceed to process flow
                    $flowId = $flowData->flow_id;
                    Log::info("FLOW_DEBUG executeFlow: existing flow={$flowId}, step={$flowData->current_step}, msg='" . strtolower(trim($message)) . "'");
                    $result = $this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, strtolower(trim($message)));
          //          logger('--from result'.$result);
                    // If validation failed, don't delete flow data
                    if ($result === 'validation_failed') {
                        return false; // Return false but don't delete flow data
                    }
                    
                    // If flow is delayed, don't delete flow data
                    if ($result === 'delayed') {
                        return false; // Return false but don't delete flow data
                    }
			//		logger('--from return result'.$result);
                    
                    return $result;
                }
            }

            // If flowData doesn't exist or was deleted, proceed with flow determination logic
            if(!$flowData){
			//			logger('--frow new flow');
                // Determine the flow based on trigger type
                $flowQuery = Flow::where('organization_id', $chat->organization_id)->where('status', 'active');
                $flow = null;

                //Check if any flow trigger has been hit
                if($isNewContact){
			//		logger('--frow is new contact');
                    $flow = $flowQuery->where('trigger', 'new_contact')->first();
                } else {
                    $msg = strtolower(trim($message)); // Normalize the message
                    $words = explode(' ', $msg); // Split message into individual words
 			//		logger('--from else new');
                    $conditions = [];
                    $bindings = [];

                    // Condition to match the full message (as a sentence or phrase)
                    $conditions[] = "FIND_IN_SET(?, keywords)";
                    $bindings[] = $msg; // Add the full message (spaces stripped, like in DB)
						
                    // Add individual word checks
                    foreach ($words as $word) {
			//			logger('--from word'.$word);
                        $word = strtolower(trim($word));
                        $conditions[] = "FIND_IN_SET(?, keywords)";
                        $bindings[] = $word;
                    }
// logger('--from final query');
                    $flow = \DB::table('flows')->whereRaw(
                        '( `trigger` = ? AND organization_id = ? AND status = ? AND deleted_at IS NULL) AND (' . implode(' OR ', $conditions) . ')',
                        array_merge(['keywords', $chat->organization_id, 'active'], $bindings)
                    )->first();
					// if($flow){
					// 	logger('--from flow'.$flow->id);
					// } else {
					// 	logger('--from flow not found');
					// }

                    //Log::info(json_encode($flow));
                }

                // Set the flow ID if a matching flow is found
                if ($flow) {
                    $flowId = $flow->id;
                }

                // If a flow ID was found, create a new FlowUserData record
                if ($flowId) {
					// logger('--from flow data');
                    $flowData = new FlowUserData();
                    $flowData->fill([
                        'contact_id' => $chat->contact_id,
                        'flow_id' => $flowId,
                        'current_step' => 1
                    ])->save();
                    	// logger('--from pprocess flow data');
                    $result = $this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, strtolower(trim($message)));
                    
                    // If validation failed, don't delete flow data
                    if ($result === 'validation_failed') {
						//  logger('--from validation failed');
                        return false; // Return false but don't delete flow data
                    }
                    
                    // If flow is delayed, don't delete flow data
                    if ($result === 'delayed') {
						//    logger('--from delayed results');
                        return false; // Return false but don't delete flow data
                    }
                    
                    return $result;
                }
            }
		// logger('not founnnd return false');
            return false;
        }
    }

    public function hasActiveFlow($chat){
        $activeFlow = FlowUserData::where('contact_id', $chat->contact_id)->first();

        if (!$activeFlow) {
            return false;
        }

        $flow = Flow::where('id', $activeFlow->flow_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (!$flow) {
            FlowUserData::where('contact_id', $chat->contact_id)->delete();
            return false;
        }

        return true;
    }

    /**
     * Continue a flow that was paused due to a delay action
     *
     * @param int $contactId
     * @param int $flowId
     * @param int $currentStep
     * @return bool
     */
    public function continueDelayedFlow($contactId, $flowId, $currentStep)
    {
	//	logger('continue delayed flow');
        // Find the flow user data
        $flowData = FlowUserData::where('contact_id', $contactId)
            ->where('flow_id', $flowId)
            ->first();

        if (!$flowData) {
            Log::warning("FlowUserData not found for delayed flow continuation: contact {$contactId}, flow {$flowId}");
            return false;
        }

        // Update the current step if needed
        if ($flowData->current_step != $currentStep) {
            $flowData->current_step = $currentStep;
            $flowData->save();
        }

        // Get the contact
        $contact = Contact::find($contactId);
        if (!$contact) {
            Log::warning("Contact not found for delayed flow continuation: {$contactId}");
            return false;
        }

        // Create a mock chat object for processing
        $chat = (object) [
            'contact_id' => $contactId,
            'organization_id' => $this->organizationId
        ];

        // Continue processing the flow from the current step
        return $this->processFlow($chat, false, $flowData, $contactId, '');
    }

    public function processFlow($chat, $isNewContact, $flowData, $contactId, $message){
        Log::info("FLOW_DEBUG processFlow: contact={$contactId}, current_step={$flowData->current_step}, message='{$message}'");
        $flow = Flow::find($flowData->flow_id);

        if (!$flow || empty($flow->metadata)) {
            Log::warning("processFlow: flow not found or empty metadata", ['flow_id' => $flowData->flow_id]);
            FlowUserData::where('contact_id', $contactId)->delete();
            return false;
        }

        $contact = Contact::find($contactId);
        if (!$contact) {
			// logger('--from contact not found');
            return false;
        }
			//	logger('processing process flow method');
        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", null);
        //	logger('flow id'.$flow->id);
        // Process nodes continuously until we encounter a message node
        $maxIterations = 50; // Prevent infinite loops
        $iteration = 0;
		$tryAnotherTime = true ;
      //  logger('start looping');
        while ($iteration < $maxIterations) {
            $iteration++;
              //      logger('inside looping'.$iteration);
            // Get the current node metadata
            $metadataArray = $this->findEdgesBySource($edges, $flowData->current_step, $message);
            Log::info("FLOW_DEBUG findEdgesBySource: step={$flowData->current_step}, found=" . (!empty($metadataArray) ? 'yes (node=' . ($metadataArray['id'] ?? '?') . ', type=' . ($metadataArray['type'] ?? '?') . ')' : 'EMPTY'));

            if (empty($metadataArray)) {
                $edgesFromCurrentStep = 0;
                foreach ($edges as $edge) {
                    if (isset($edge['source']) && (string)$edge['source'] === (string)$flowData->current_step) {
                        $edgesFromCurrentStep++;
                    }
                }

                if ($edgesFromCurrentStep > 1) {
                    return false;
                }

                FlowUserData::where('contact_id', $contactId)->delete();
                return false;
            }

            // Check if this is an action node
            $nodeType = \Arr::get($metadataArray, "type", null);
			// logger('node type '.$nodeType);
            if ($nodeType === 'action') {
				  logger('inside loop - action inside looping '.$iteration);
                $result = $this->processActionNode($metadataArray, $contact, $message, $flowData, $contactId);
                
                if ($result === false) {
					// logger('inside loop - action failed so return false');
                    // Action failed, stop the flow
                    return false;
                }
                
                if ($result === 'validation_failed') {
                    // Validation failed (e.g., invalid email), stay on the same node
                    return 'validation_failed'; // Return special value to indicate validation failure
                }
                
                if ($result === 'delayed') {
					// logger('inside loop - delayed so return ');
                    // Flow is paused due to delay action, don't proceed to next step
                    return 'delayed'; // Return special value to indicate the flow is paused
                }
                
                // Action completed successfully, refresh flowData and continue to next node
                $flowData = FlowUserData::where('contact_id', $contactId)->first();
                if (!$flowData) {
                    Log::warning("DELETION POINT 7: FlowUserData not found after action, ending flow");
                    return false;
                }
                continue;
            }
			// logger('proceess message node and stop');
            // This is a message node (text, media, interactive, etc.) - process it and stop
            Log::info("FLOW_DEBUG processMessageNode: nodeId=" . ($metadataArray['id'] ?? '?') . ", nodeType=" . ($metadataArray['type'] ?? '?'));
            return $this->processMessageNode($metadataArray, $contact, $flowData, $contactId);
        }
        
        Log::warning("DELETION POINT 3: Maximum iterations reached for contact {$contactId}, ending flow");
        FlowUserData::where('contact_id', $contactId)->delete();
        return false;
    }

    /**
     * Process message nodes (text, media, interactive buttons, etc.)
     */
    private function processMessageNode($metadataArray, $contact, $flowData, $contactId)
    {
        $fieldsArray = \Arr::get($metadataArray, "data.metadata.fields", null);
        $type = $fieldsArray['type'] ?? null;

        $message = $this->replacePlaceholders($contact->uuid, $fieldsArray['body'] ?? '');

        // Initialize the header array if needed for interactive messages
        $header = $this->prepareHeader($fieldsArray ?? []);
        $buttonArray = [];
        $buttonType = null;
        $buttonLabel = null;

        Log::info("FLOW_DEBUG processMessageNode: type={$type}, body=" . substr($message, 0, 50));

        if($type == 'text'){
            $buttonType = 'text';
        } elseif ($type === 'interactive buttons') {
            $buttonType = ($fieldsArray['buttonType'] ?? '') === 'buttons'
                ? 'interactive buttons'
                : 'interactive call to action url';
            Log::info("FLOW_DEBUG interactive buttons: buttonType={$buttonType}, rawBtnType=" . ($fieldsArray['buttonType'] ?? 'null'));
            $buttonArray = $this->prepareButtonArray($fieldsArray ?? [], $buttonType);
    
        } elseif ($type === 'interactive list') {
			// logger('--from interactive list');
            $buttonType = 'interactive list';
            $buttonArray = $this->prepareButtonArray($fieldsArray ?? [], $buttonType);
            $buttonLabel = $fieldsArray['buttonLabel'] ?? '';
        }

        $response = null;

        switch ($type) {
            case 'text':
                $response = $this->whatsappService->sendMessage($contact->uuid, $message, 0, $buttonType);
                break;
    
            case 'media':
                $mediaInfo = json_decode(($fieldsArray['media']['metadata'] ?? '{}'), true);
                $mediaLocation = $fieldsArray['media']['location'] ?? '';
                $mediaLocation = ($mediaLocation === 'aws') ? 'amazon' : $mediaLocation;

                $response = $this->whatsappService->sendMedia(
                    $contact->uuid,
                    $fieldsArray['mediaType'] ?? '',
                    $mediaInfo['name'] ?? '',
                    $fieldsArray['media']['path'] ?? '',
                    $fieldsArray['media']['path'] ?? '',
                    $mediaLocation,
                    $fieldsArray['caption'] ?? ''
                );
                break;
    
            case 'interactive buttons':
				case 'interactive list':
					// logger('--from interactive buttons send message');
                $response = $this->whatsappService->sendMessage(
                    $contact->uuid,
                    $message,
                    0,
                    $buttonType,
                    $buttonArray,
                    $header,
                    ($fieldsArray['footer'] ?? ''),
                    $buttonLabel
                );
                break;
        }

        $processedNodeId = $metadataArray['id'] ?? null;
        Log::info("FLOW_DEBUG sendMessage response: success=" . (isset($response->success) ? ($response->success ? 'true' : 'false') : 'null') . ", processedNodeId={$processedNodeId}");

        if($response){
            $this->proceedToNextStep($flowData, $contactId, $processedNodeId);

            if(isset($response->data->chat->id)){
                FlowLog::create([
                    'flow_id' => $flowData->flow_id,
                    'chat_id' => $response->data->chat->id
                ]);
            }

            return true;
        } else {
            $this->proceedToNextStep($flowData, $contactId, $processedNodeId);
            return true;
        }
    }

    /**
     * Process action nodes in the flow
     */
    private function processActionNode($metadataArray, $contact, $message, $flowData, $contactId)
    {
				//   logger('inside action node');
        $actionType = \Arr::get($metadataArray, "data.actionType", null);
        $config = \Arr::get($metadataArray, "data.config", []);
        $isActive = \Arr::get($metadataArray, "data.is_active", true);

        if (!$actionType || !$isActive) {
            $this->proceedToNextStep($flowData, $contactId, $metadataArray['id'] ?? null);
            return true;
        }

        // Normalize action type: convert hyphens to underscores
        $actionType = str_replace('-', '_', $actionType);

        // Initialize action execution service
        $actionService = new ActionExecutionService($this->organizationId);

        // Execute the action
        $result = $actionService->executeAction($actionType, $config, $contact, $message, $flowData, $contactId);

		if ($result === 'delayed') {
   			//  logger('Delay action executed - flow paused');
  		  return 'delayed';
			}

        // Handle conditional actions specially
        if ($actionType === 'conditional') {
					//  logger('inside conditional');
            // Set current_step to this conditional node's ID before processing
            $conditionalNodeId = $metadataArray['id'] ?? null;
            if ($conditionalNodeId && $flowData->current_step != $conditionalNodeId) {
                FlowUserData::where('contact_id', $contactId)->update(['current_step' => $conditionalNodeId]);
                $flowData = FlowUserData::where('contact_id', $contactId)->first();
            }
            return $this->handleConditionalAction($result, $flowData, $contactId, $metadataArray);
        }

        // For update_contact action, check if it failed due to validation BEFORE checking general failure
        if ($actionType === 'update_contact' && $result === false) {
		//	logger('inside update contact');
            // Check if there's an invalid email message configured, which indicates validation failure
            $invalidMessage = $config['invalid_email_message'] ?? '';
            //Log::info("Update contact validation failed, staying on same node for contact {$contactId}");
            return 'validation_failed'; // Special return value to indicate validation failure
        }

        if ($result === false) {
            // Action failed, stop the flow
            Log::warning("DELETION POINT 4: Action failed, deleting FlowUserData for contact {$contactId}");
            FlowUserData::where('contact_id', $contactId)->delete();
            return false;
        }
        $this->proceedToNextStep($flowData, $contactId, $metadataArray['id'] ?? null);
        return true;
    }

    /**
     * Handle conditional action branching
     */
    private function handleConditionalAction($conditionResult, $flowData, $contactId, $metadataArray)
    {
        $flow = Flow::find($flowData->flow_id);
        if (!$flow || empty($flow->metadata)) {
            return false;
        }

        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", null);
        $currentStep = $flowData->current_step;
		// logger('--from handle conditional action'.$currentStep);
        // Build the sourceHandle for the condition or default
        if ($conditionResult !== 'default') {
            $sourceHandle = 'condition-' . $conditionResult . '|' . $currentStep;
        } else {
            $sourceHandle = 'default|' . $currentStep;
        }

        // \Log::info("Looking for edge with source: {$currentStep} and sourceHandle: {$sourceHandle}");

        foreach ($edges as $edge) {
            if (
                isset($edge['source']) && (string)$edge['source'] === (string)$currentStep &&
                isset($edge['sourceHandle']) && $edge['sourceHandle'] === $sourceHandle
            ) {
                $targetNode = $edge['targetNode']['id'] ?? null;
                if ($targetNode) {
                    // \Log::info("Found matching edge, routing to node: " . $targetNode);
                    // Reload flowData
                    $flowData = FlowUserData::where('contact_id', $contactId)->first();
                    // Get the chat object (you may need to pass it in, or reconstruct it)
                    $chat = (object) [
                        'contact_id' => $contactId,
                        'organization_id' => $this->organizationId
                    ];
                    // Process the next node
                    $this->processFlow($chat, false, $flowData, $contactId, '');
                    return true;
                }
            }
        }

        // No matching path found, end the flow
        \Log::warning("DELETION POINT 5: No matching conditional path found, deleting FlowUserData for contact {$contactId}");
        FlowUserData::where('contact_id', $contactId)->delete();
        return false;
    }

    /**
     * Proceed to the next step in the flow.
     * $processedNodeId: the ID of the node that was just processed/sent.
     * Sets current_step to that node so findEdgesBySource can find the correct next node.
     */
    private function proceedToNextStep($flowData, $contactId, $processedNodeId = null)
    {
        if ($processedNodeId !== null) {
            FlowUserData::where('contact_id', $contactId)->update(['current_step' => $processedNodeId]);
            return;
        }

        $flow = Flow::find($flowData->flow_id);
        if (!$flow || empty($flow->metadata)) {
            Log::warning("DELETION POINT 8: Flow not found or empty metadata for flow {$flowData->flow_id}");
            return;
        }

        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", null);

        $nextNodes = [];
        foreach ($edges as $edge) {
            if (isset($edge['source']) && (string) $edge['source'] === (string) $flowData->current_step) {
                if (isset($edge['targetNode']['id'])) {
                    $nextNodes[] = $edge['targetNode']['id'];
                }
            }
        }

        if (!empty($nextNodes)) {
            $nextStep = $nextNodes[0];
            FlowUserData::where('contact_id', $contactId)->update(['current_step' => $nextStep]);
        } else {
            Log::warning("DELETION POINT 6: No next step found for current_step {$flowData->current_step}, ending flow");
            FlowUserData::where('contact_id', $contactId)->delete();
        }
    }

    private function prepareHeader(array $fieldsArray): array
    {
        $header = [];

        if (($fieldsArray['headerType'] ?? '') === 'text') {
            $header = [
                'type' => 'text',
                'text' => clean($fieldsArray['headerText'] ?? ''),
            ];
        } elseif (($fieldsArray['headerType'] ?? '') !== 'none') {
            $header['type'] = $fieldsArray['headerType'] ?? '';
            $header[$fieldsArray['headerType'] ?? ''] = [
                'link' => $fieldsArray['headerMedia']['path'] ?? '',
            ];
        }

        return $header;
    }

    private function prepareButtonArray(array $fieldsArray, string $type): array
    {
        $buttonArray = [];

        if ($type === 'interactive buttons') {
            foreach ($fieldsArray['buttons'] ?? [] as $id => $title) {
                if (!empty($title)) {
                    $buttonArray[] = [
                        'id' => $id,
                        'title' => $title,
                    ];
                }
            }
        } elseif ($type === 'interactive call to action url') {
            $buttonArray = [
                'display_text' => $fieldsArray['ctaUrlButton']['displayText'] ?? '',
                'url' => $fieldsArray['ctaUrlButton']['url'] ?? '',
            ];
        } elseif ($type === 'interactive list') {
            $buttonArray = collect($fieldsArray['sections'] ?? [])->map(function ($section) {
                return [
                    'title' => $section['title'] ?? '',
                    'rows' => collect($section['rows'] ?? [])->map(function ($row) {
                        return [
                            'id' => $row['id'] ?? '',
                            'title' => $row['title'] ?? '',
                            'description' => $row['description'] ?? '',
                        ];
                    })->all()
                ];
            })->all();
        }

        return $buttonArray;
    }

    private function findEdgesBySource($edges, $sourceId, $message)
    {
        // Convert $sourceId to a string to handle loose type matching
        $sourceId = (string) $sourceId;
        
        //Log::info("findEdgesBySource: sourceId={$sourceId}, message={$message}, total_edges=" . count($edges));
        
        // Initialize an empty array to store matching edges
        $matchingEdges = [];

        // Iterate over each edge to find matches with sourceId
        foreach ($edges as $index => $edge) {
            // Check if 'source' key exists and matches the source ID
            if (isset($edge['source']) && (string) $edge['source'] === $sourceId) {
                // If there's a match, add this edge to the matching array
                $matchingEdges[] = $edge;
                //Log::info("Found matching edge: source={$edge['source']}, target=" . ($edge['targetNode']['id'] ?? 'unknown'));
            }
        }

        if (count($matchingEdges) === 1) {
            //Log::info("Single edge found, returning targetNode");
            return $matchingEdges[0]['targetNode'] ?? [];
        } else if (count($matchingEdges) > 1) {
            $firstEdge = $matchingEdges[0];
            $nodeType = $firstEdge['sourceNode']['type'] ?? null;
            $type = $firstEdge['sourceNode']['data']['metadata']['fields']['type'] ?? null;

            // Handle action nodes (they don't require user input)
            if ($nodeType === 'action') {
                // For action nodes, just return the first edge (they proceed automatically)
                return $matchingEdges[0]['targetNode'] ?? [];
            }

            // Perform logic based on the 'type'
            if ($type == 'interactive buttons') {
                $buttons = $firstEdge['sourceNode']['data']['metadata']['fields']['buttons'] ?? [];

                $buttonMapping = ['button1' => 'a', 'button2' => 'b', 'button3' => 'c'];
                $handle = null;

                foreach ($buttons as $buttonKey => $buttonValue) {
                    $normalizedBtn = strtolower(trim($buttonValue));
                    Log::info("FLOW_DEBUG btnMatch: key={$buttonKey}, btnVal='{$normalizedBtn}', msg='{$message}', match=" . ($normalizedBtn === $message ? 'YES' : 'no'));
                    if ($normalizedBtn === $message) {
                        $handle = $buttonMapping[$buttonKey] ?? null;
                    }
                }

                Log::info("FLOW_DEBUG handle resolved: " . ($handle ?? 'NULL'));
                if($handle != null){
                    // Search for the edge with this handle and return its targetNode
                    foreach ($matchingEdges as $edge) {
                        if (isset($edge['sourceHandle']) && $edge['sourceHandle'] === $handle) {
                            return $edge['targetNode'] ?? [];
                        }
                    }
                }
            } elseif ($type == 'interactive list') {
                $sections = $firstEdge['sourceNode']['data']['metadata']['fields']['sections'] ?? [];
                $handle = null;

                // Search for the matching title and generate the handle dynamically
                foreach ($sections as $sectionIndex => $section) {
                    if (isset($section['rows']) && is_array($section['rows'])) {
                        foreach ($section['rows'] as $rowIndex => $row) {
                            if (isset($row['title']) && strtolower(trim($row['title'])) === strtolower(trim($message))) {
                                // Construct the handle using section and row positions
                                $handle = 'a' . $sectionIndex . $rowIndex;
                            }
                        }
                    }
                }

                if($handle != null){
                    foreach ($matchingEdges as $edge) {
                        if (isset($edge['sourceHandle']) && $edge['sourceHandle'] === $handle) {
                            return $edge['targetNode'] ?? [];
                        }
                    }
                }
            }

            return [];
        }

        return [];
    }

    private function replacePlaceholders($contactUuid, $message){
        $organization = Organization::where('id', $this->organizationId)->first();
        $contact = Contact::with('contactGroups')->where('uuid', $contactUuid)->first();
        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
        $full_address = ($address['street'] ?? Null) . ', ' .
                        ($address['city'] ?? Null) . ', ' .
                        ($address['state'] ?? Null) . ', ' .
                        ($address['zip'] ?? Null) . ', ' .
                        ($address['country'] ?? Null);

        $data = [
            'first_name' => $contact->first_name ?? Null,
            'last_name' => $contact->last_name ?? Null,
            'full_name' => $contact->full_name ?? Null,
            'email' => $contact->email ?? Null,
            'phone' => $contact->phone ?? Null,
            'organization_name' => $organization->name,
            'full_address' => $full_address,
            'street' => $address['street'] ?? Null,
            'city' => $address['city'] ?? Null,
            'state' => $address['state'] ?? Null,
            'zip_code' => $address['zip'] ?? Null,
            'country' => $address['country'] ?? Null,
        ];

        $transformedMetadata = [];
        if($metadata){
            foreach ($metadata as $key => $value) {
                $transformedKey = strtolower(str_replace(' ', '_', $key));
                $transformedMetadata[$transformedKey] = $value;
            }
        }

        $mergedData = array_merge($data, $transformedMetadata);

        //Log::info($mergedData);

        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($mergedData) {
            $key = $matches[1];
            return isset($mergedData[$key]) ? $mergedData[$key] : $matches[0];
        }, $message);
    }
}
