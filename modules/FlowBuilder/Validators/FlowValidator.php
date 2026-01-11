<?php

namespace Modules\FlowBuilder\Validators;

use Illuminate\Support\Facades\Validator;

class FlowValidator
{
    /**
     * Validate WhatsApp message nodes for required fields.
     *
     * @param array $nodes
     * @return array|bool
     */
    public function validateMessageNodes(array $nodes)
    {
        $errors = [];

        foreach ($nodes['nodes'] as $node) {
            $data = $node['data']['metadata']['fields'] ?? [];

            // Validate based on node type
            switch ($node['type']) {
                case 'start':
                    if (isset($data['type']) && $data['type'] == 'keywords') {
                        if (empty($data['keywords'])) {
                            $errors['keywords'] = 'Keywords field is required and cannot be empty.';
                        }
                    }
                    break;

                case 'text':
                    $textValidation = $this->validateText($data);
                    if ($textValidation !== true) {
                        $errors['text'] = $textValidation;
                    }
                    break;

                case 'media':
                    $mediaValidation = $this->validateMedia($data);
                    if ($mediaValidation !== true) {
                        $errors['media'] = $mediaValidation;
                    }
                    break;

                case 'buttons':
                    $buttonsValidation = $this->validateButtons($data);
                    if ($buttonsValidation !== true) {
                        $errors['buttons'] = $buttonsValidation;
                    }
                    break;

                case 'list':
                    $listValidation = $this->validateList($data);
                    if ($listValidation !== true) {
                        $errors['list'] = $listValidation;
                    }
                    break;

                case 'action':
                    $actionValidation = $this->validateAction($node['data']);
                    if ($actionValidation !== true) {
                        $errors['action'] = $actionValidation;
                    }
                    break;

                default:
                    $errors['unknown'] = 'Unknown node type: ' . $node['type'];
                    break;
            }
        }

        // Return true if no errors found, otherwise return errors
        return empty($errors) ? true : $errors;
    }

    /**
     * Validate a text message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateText(array $data)
    {
        $errors = [];

        // Check if 'body' is present and not empty
        if (empty($data['body'])) {
            $errors[] = 'Text body is required.';
        }

       // Additional checks can be added for other media fields like 'caption'
       return !empty($errors) ? $errors : true;
    }

    /**
     * Validate a media message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateMedia(array $data)
    {
        $errors = [];

        // Check if 'media' data is provided and contains the required fields
        if (empty($data['media']) || empty($data['media']['path'])) {
            $errors[] = 'Media path is required.';
        }

        if (empty($data['mediaType'])) {
            $errors[] = 'Media type (image, audio, etc.) is required.';
        }

        // Validate mediaType format based on mimeType
        if (!empty($data['mediaType'])) {
            // Assuming the metadata contains 'extension' as MIME type
            if(isset($data['media']['metadata'])){
                $mimeType = json_decode($data['media']['metadata'], true)['extension'] ?? ''; // You should update this based on your actual structure
            } else {
                $mimeType = '';
            }
            // Validate based on media type and mimeType
            $validTypes = $this->validateMediaTypeFormat($mimeType, $data['mediaType']);
            if ($validTypes !== true) {
                $errors[] = $validTypes;  // Store specific error for invalid format
            }
        }

        // Additional checks can be added for other media fields like 'caption'
        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate buttons message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateButtons(array $data)
    {
        $errors = [];

        // Validate 'headerType' and corresponding fields
        if (isset($data['headerType'])) {
            switch ($data['headerType']) {
                case 'text':
                    if (empty($data['headerText'])) {
                        $errors[] = 'Header text is required when headerType is "text".';
                    }
                    break;
                
                case 'image':
                case 'video':
                case 'document':
                    if (empty($data['headerMedia'])) {
                        $errors[] = 'Media is required when headerType is "' . $data['headerType'] . '".';
                    } else if (!empty($data['headerMedia'])) {
                        // Assuming the metadata contains 'extension' as MIME type
                        $mimeType = json_decode($data['headerMedia']['metadata'], true)['extension'] ?? ''; // You should update this based on your actual structure
            
                        // Validate based on media type and mimeType
                        $validTypes = $this->validateMediaTypeFormat($mimeType, $data['headerType']);
                        if ($validTypes !== true) {
                            $errors[] = $validTypes;  // Store specific error for invalid format
                        }
                    }
                    break;
            }
        }

        // Check if 'body' has a value
        if (empty($data['body'])) {
            $errors[] = 'Body text is required.';
        }

        // Check if 'buttonType' exists and is 'buttons'
        // Validate buttons if 'buttonType' is 'buttons'
        if (($data['buttonType'] ?? '') === 'buttons' && isset($data['buttons'])) {
            $validButtons = array_filter([
                'button1' => $data['buttons']['button1'] ?? '',
                'button2' => $data['buttons']['button2'] ?? '',
                'button3' => $data['buttons']['button3'] ?? '',
            ]);

            if (empty($validButtons)) {
                $errors[] = 'At least one button (button1, button2, button3) must have a value.';
            }
			
            // Ensure button values are between 1 to 20 characters long
            foreach ($validButtons as $key => $value) {
                if (!$this->validateButtonValueLength($value)) {
					dd($value, mb_strlen(($value)));
                    $errors[] = ucfirst($key) . ' must be between 1 to 20 characters.';
                }
            }
        }

        // Check if 'buttonType' exists and is 'cta_url'
        if (isset($data['buttonType']) && $data['buttonType'] === 'cta_url') {
            // Validate that 'ctaUrlButton' array exists and contains 'displayText' and 'url'
            if (empty($data['ctaUrlButton']) || !isset($data['ctaUrlButton']['displayText']) || !isset($data['ctaUrlButton']['url'])) {
                $errors[] = 'Both displayText and url are required for ctaUrlButton.';
            }

            // Further validation if required for displayText and url (e.g., non-empty values)
            if (empty($data['ctaUrlButton']['displayText']) || empty($data['ctaUrlButton']['url'])) {
                $errors[] = 'Both displayText and url must not be empty.';
            }

            // URL pattern to validate the 'url'
            $urlPattern = '/^(https?:\/\/)([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?(\/\S*)?$/';

            // Validate if the URL matches the pattern
            if (!preg_match($urlPattern, $data['ctaUrlButton']['url'])) {
                $errors[] = 'The URL format is invalid. Please provide a valid URL.';
            }
        }
        
        // Additional checks can be added for other media fields like 'caption'
        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate action node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateAction(array $data)
    {
        $errors = [];

        $actionType = $data['actionType'] ?? '';
        $config = $data['config'] ?? [];
        $isActive = $data['is_active'] ?? true;

        // If action is not active, skip validation
        if (!$isActive) {
            return true;
        }

        // Normalize action type: convert hyphens to underscores
        $actionType = str_replace('-', '_', $actionType);

        // Validate based on action type
        switch ($actionType) {
            case 'add_to_group':
            case 'remove_from_group':
                if (empty($config['group_id'])) {
                    $errors[] = 'Group ID is required for ' . $actionType . ' action.';
                }
                break;

            case 'update_contact':
                if (empty($config['target_field'])) {
                    $errors[] = 'Target field is required for update_contact action.';
                }
                break;

            case 'send_email':
                if (empty($config['subject'])) {
                    $errors[] = 'Subject is required for send_email action.';
                }
                if (empty($config['body'])) {
                    $errors[] = 'Body is required for send_email action.';
                }
                if (empty($config['smtp_host'])) {
                    $errors[] = 'SMTP host is required for send_email action.';
                }
                if (empty($config['smtp_username'])) {
                    $errors[] = 'SMTP username is required for send_email action.';
                }
                if (empty($config['smtp_password'])) {
                    $errors[] = 'SMTP password is required for send_email action.';
                }
                break;

            case 'delay':
                if (!isset($config['duration']) || $config['duration'] <= 0) {
                    $errors[] = 'Duration must be greater than 0 for delay action.';
                }
                break;

            case 'webhook':
                if (empty($config['url'])) {
                    $errors[] = 'URL is required for webhook action.';
                }
                break;

            case 'ai_response':
                if (empty($config['prompt'])) {
                    $errors[] = 'Prompt is required for ai_response action.';
                }
                break;

            case 'conditional':
                if (empty($config['condition_type'])) {
                    $errors[] = 'Condition type is required for conditional action.';
                }
                if (empty($config['conditions']) || !is_array($config['conditions'])) {
                    $errors[] = 'Conditions array is required for conditional action.';
                }
                if ($config['condition_type'] === 'contact_field' && empty($config['field_name'])) {
                    $errors[] = 'Field name is required for contact_field condition type.';
                }
                break;

            default:
                if (empty($actionType)) {
                    $errors[] = 'Action type is required.';
                } else {
                    $errors[] = 'Unknown action type: ' . $actionType;
                }
                break;
        }

        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate list message node.
     *
     * @param array $data
     * @return bool|string
     */
    private function validateList(array $data)
    {
        $errors = [];

        // Validate 'headerType' and corresponding fields
        if (isset($data['headerType'])) {
            switch ($data['headerType']) {
                case 'text':
                    if (empty($data['headerText'])) {
                        $errors[] = 'Header text is required when headerType is "text".';
                    }
                    break;
                
                case 'image':
                case 'video':
                case 'document':
                    if (empty($data['headerMedia'])) {
                        $errors[] = 'Media is required when headerType is "' . $data['headerType'] . '".';
                    } else if (!empty($data['headerMedia'])) {
                        // Assuming the metadata contains 'extension' as MIME type
                        $mimeType = json_decode($data['headerMedia']['metadata'], true)['extension'] ?? ''; // You should update this based on your actual structure
            
                        // Validate based on media type and mimeType
                        $validTypes = $this->validateMediaTypeFormat($mimeType, $data['headerType']);
                        if ($validTypes !== true) {
                            $errors[] = $validTypes;  // Store specific error for invalid format
                        }
                    }
                    break;
            }
        }

        // Check if 'body' has a value
        if (empty($data['body'])) {
            $errors[] = 'Body text is required.';
        }

        // Check if button label has a value
        if (empty($data['buttonLabel'])) {
            $errors[] = 'Button label is required.';
        }

        // Check if list sections are provided
        if (empty($data['sections']) || !is_array($data['sections'])) {
            $errors[] = 'List sections are required.';
        }

        $hasEmptySections = false;

        if(isset($data['sections'])){
            // Ensure each section has a title and valid rows
            foreach ($data['sections'] as $section) {
                if (empty($section['title'])) {
                    $errors[] = 'Each list section must have a title.';
                }

                if (empty($section['rows']) || !is_array($section['rows'])) {
                    $errors[] = 'Each list section must have rows.';
                }

                // Check if all rows in a section are empty (no title or id)
                $allRowsEmpty = true;
                foreach ($section['rows'] as $row) {
                    if (!empty($row['title']) && !empty($row['id'])) {
                        $allRowsEmpty = false;
                        break;
                    }
                }

                if ($allRowsEmpty) {
                    $hasEmptySections = true;
                }
            }
        }

        // Return error if any section has all rows empty
        if ($hasEmptySections) {
            $errors[] = 'Each section must contain at least one row with both a title and an id.';
        }

        // Additional checks can be added for other media fields like 'caption'
        return !empty($errors) ? $errors : true;
    }

    /**
     * Validate the format of the media type based on its MIME type.
     * 
     * @param string $mimeType
     * @param string $mediaType
     * @return bool|string
     */
    private function validateMediaTypeFormat($mimeType, $mediaType)
    {
        // Define valid formats for each category
        $validFormats = [
            'image' => ['image/jpeg', 'image/png'],
            'audio' => ['audio/mpeg', 'audio/mp3', 'audio/aac', 'audio/amr', 'audio/mp4', 'audio/ogg'],
            'document' => [
                'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            ],
            'video' => ['video/mp4', 'video/3gpp']
        ];

        // Check if media type matches the predefined categories and mimeType
        if ($mediaType === 'image') {
            // Only allow 'image/jpeg' or 'image/png' for image mediaType
            if (!in_array($mimeType, $validFormats['image'])) {
                return 'Invalid image format. Allowed types: JPEG, PNG.';
            }
        }

        if ($mediaType === 'audio') {
            // Only allow valid audio formats
            if (!in_array($mimeType, $validFormats['audio'])) {
                return 'Invalid audio format. Allowed types: MP3, AAC, AMR, MP4, OGG.';
            }
        }

        if ($mediaType === 'document') {
            // Only allow valid document formats
            if (!in_array($mimeType, $validFormats['document'])) {
                return 'Invalid document format. Allowed types: PDF, TXT, DOC, DOCX, XLS, XLSX, PPT, PPTX.';
            }
        }

        if ($mediaType === 'video') {
            // Only allow valid video formats
            if (!in_array($mimeType, $validFormats['video'])) {
                return 'Invalid video format. Allowed types: MP4, 3GPP.';
            }
        }

        return true; // Return true if no validation errors found
    }

    /**
     * Validate button value length (1-20 characters).
     *
     * @param string $value
     * @return bool
     */
    private function validateButtonValueLength(string $value): bool
    {
        $length = mb_strlen(trim($value),'UTF-8');
        return $length >= 1 && $length <= 20;
    }
}
