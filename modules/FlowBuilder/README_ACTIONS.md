# FlowBuilder Action Execution System

This document explains how actions are executed in the FlowBuilder module.

## Overview

The action execution system allows flows to perform various automated actions when triggered. Actions are executed in sequence as the flow progresses, and can include operations like adding contacts to groups, sending emails, making webhook calls, and conditional branching.

## Architecture

### ActionExecutionService

The `ActionExecutionService` is responsible for executing individual actions. It handles:

- **Action Type Routing**: Routes different action types to their specific execution methods
- **Error Handling**: Catches and logs errors during action execution
- **Contact Management**: Manages contact data updates and group operations
- **External Integrations**: Handles webhook calls, email sending, and AI responses

### FlowExecutionService Integration

The `FlowExecutionService` has been extended to:

- **Detect Action Nodes**: Identifies when a flow step is an action node
- **Execute Actions**: Calls the ActionExecutionService to perform the action
- **Handle Conditional Branching**: Manages flow branching based on conditional action results
- **Flow Progression**: Advances the flow to the next step after action execution

## Supported Action Types

### 1. Add to Group (`add_to_group`)
- **Purpose**: Adds a contact to a specified contact group
- **Configuration**: `group_id` (required)
- **Behavior**: Checks if contact is already in group before adding

### 2. Remove from Group (`remove_from_group`)
- **Purpose**: Removes a contact from a specified contact group
- **Configuration**: `group_id` (required)
- **Behavior**: Removes all group associations for the contact

### 3. Update Contact (`update_contact`)
- **Purpose**: Updates a contact field with the user's message
- **Configuration**: `target_field` (required)
- **Supported Fields**: 
  - Standard fields: `first_name`, `last_name`, `email`, `phone`
  - Address fields: `address.street`, `address.city`, `address.state`, `address.zip`, `address.country`
  - Custom metadata fields: any custom field name

### 4. Send Email (`send_email`)
- **Purpose**: Sends an email to the contact
- **Configuration**: 
  - `subject` (required)
  - `body` (required)
  - `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password` (required)
  - `smtp_encryption` (optional, default: 'tls')
  - `from_name` (optional)
- **Features**: Placeholder replacement, SMTP configuration

### 5. Delay (`delay`)
- **Purpose**: Introduces a delay in the flow execution
- **Configuration**: `duration` (in minutes)
- **Note**: Currently logs the delay; in production, should use job queues

### 6. Webhook (`webhook`)
- **Purpose**: Makes HTTP requests to external services
- **Configuration**:
  - `url` (required)
  - `method` (optional, default: 'POST')
- **Payload**: Includes contact data, organization info, and user message

### 7. AI Response (`ai_response`)
- **Purpose**: Generates AI-powered responses
- **Configuration**:
  - `prompt` (required)
  - `proceed_condition` (optional, default: 'always')
  - `confidence_threshold` (optional, default: 0.8)
- **Features**: Placeholder replacement, confidence-based flow control

### 8. Conditional (`conditional`)
- **Purpose**: Branches flow based on conditions
- **Configuration**:
  - `condition_type` (required): 'message_contains', 'message_equals', 'contact_field'
  - `conditions` (required): Array of condition objects with `value` property
  - `field_name` (for contact_field type)
  - `field_operator` (for contact_field type)
- **Return Values**: 
  - Condition index (0, 1, 2, etc.) if condition matches
  - 'default' if no conditions match

## Flow Execution Process

1. **Flow Detection**: FlowExecutionService detects when a user triggers a flow
2. **Step Processing**: For each step in the flow:
   - If it's a regular node (text, media, etc.), process normally
   - If it's an action node, call ActionExecutionService
3. **Action Execution**: ActionExecutionService executes the specific action
4. **Flow Advancement**: 
   - For regular actions: proceed to next step
   - For conditional actions: branch based on condition result
5. **Error Handling**: If action fails, stop the flow

## Conditional Branching

Conditional actions support multiple condition types:

### Message Contains
```php
$config = [
    'condition_type' => 'message_contains',
    'conditions' => [
        ['value' => 'hello'],
        ['value' => 'world']
    ]
];
```

### Message Equals
```php
$config = [
    'condition_type' => 'message_equals',
    'conditions' => [
        ['value' => 'yes'],
        ['value' => 'no']
    ]
];
```

### Contact Field
```php
$config = [
    'condition_type' => 'contact_field',
    'field_name' => 'email',
    'field_operator' => 'contains',
    'conditions' => [
        ['value' => '@gmail.com'],
        ['value' => '@yahoo.com']
    ]
];
```

## Placeholder Replacement

Actions support placeholder replacement in text fields:

### Available Placeholders
- `{first_name}` - Contact's first name
- `{last_name}` - Contact's last name
- `{full_name}` - Contact's full name
- `{email}` - Contact's email
- `{phone}` - Contact's phone number
- `{organization_name}` - Organization name
- `{full_address}` - Complete address
- `{street}`, `{city}`, `{state}`, `{zip_code}`, `{country}` - Address components
- `{custom_field}` - Any custom metadata field

### Example Usage
```php
$config = [
    'subject' => 'Hello {first_name}!',
    'body' => 'Welcome to {organization_name}. Your email is {email}.'
];
```

## Error Handling

- **Action Failures**: If an action fails, the flow stops and FlowUserData is deleted
- **Missing Configuration**: Actions log warnings for missing required configuration
- **External Service Failures**: Webhook and email actions handle external service failures gracefully
- **Logging**: All actions log their execution status for debugging

## Testing

Unit tests are available in `tests/Unit/ActionExecutionServiceTest.php` covering:
- Add/remove from group actions
- Contact field updates
- Conditional action evaluation
- Error handling for unknown action types

## Future Enhancements

1. **Job Queue Integration**: Implement proper delay handling with job queues
2. **AI Service Integration**: Connect to actual AI services for AI response actions
3. **Enhanced Conditional Logic**: Add more condition types and operators
4. **Action Templates**: Create reusable action templates
5. **Action Validation**: Add validation for action configurations
6. **Performance Monitoring**: Add metrics and monitoring for action execution 