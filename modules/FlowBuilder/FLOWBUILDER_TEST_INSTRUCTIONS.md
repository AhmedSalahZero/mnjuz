# FlowBuilder Test Instructions

This document provides step-by-step instructions for creating test flows to thoroughly test the FlowBuilder module functionality.

## Prerequisites
- Ensure you have organization_id = 1 in your database
- Make sure the FlowBuilder module is properly installed and configured
- Have access to the FlowBuilder interface

---

## 1. Basic Flows

### 1.1 Welcome Flow
**Purpose**: Simple welcome flow for new contacts

**Steps**:
1. Create new flow named "Welcome Flow"
2. Set trigger to "new_contact"
3. Add Start node
4. Add Action node with type "Send Email"
   - Configure: to = `{{contact.email}}`
   - Configure: subject = "Welcome to our service!"
   - Configure: body = "Hello {{contact.first_name}}, welcome to our platform!"
5. Connect Start → Action
6. Save and activate

### 1.2 Contact Update Flow
**Purpose**: Flow that updates contact information

**Steps**:
1. Create new flow named "Contact Update Flow"
2. Set trigger to "keywords" with keywords: "update,change,modify"
3. Add Start node
4. Add Action node with type "Update Contact"
   - Configure: field = "last_message"
   - Configure: value = `{{message}}`
5. Connect Start → Action
6. Save and activate

---

## 2. Conditional Flows

### 2.1 Message Type Router
**Purpose**: Routes messages based on content

**Steps**:
1. Create new flow named "Message Type Router"
2. Set trigger to "keywords" with keywords: "help,support,info,question"
3. Add Start node
4. Add Conditional node
   - Set condition type to "Message Contains"
   - Add conditions:
     - Value: "help" → Exit 1
     - Value: "support" → Exit 1
     - Value: "info" → Exit 2
     - Value: "question" → Exit 2
5. Add Action node (Help Resources)
   - Type: "Send Email"
   - Configure: to = `{{contact.email}}`
   - Configure: subject = "Help Resources"
   - Configure: body = "Here are some helpful resources for you."
6. Add Action node (Information)
   - Type: "Send Email"
   - Configure: to = `{{contact.email}}`
   - Configure: subject = "Information Requested"
   - Configure: body = "Here is the information you requested."
7. Connect: Start → Conditional
8. Connect: Conditional Exit 1 → Help Resources Action
9. Connect: Conditional Exit 2 → Information Action
10. Save and activate

### 2.2 VIP Customer Router
**Purpose**: Routes based on customer VIP status

**Steps**:
1. Create new flow named "VIP Customer Router"
2. Set trigger to "keywords" with keywords: "vip,premium,priority"
3. Add Start node
4. Add Conditional node
   - Set condition type to "Contact Field"
   - Add conditions:
     - Value: "vip_status = true" → Exit VIP
     - Value: "vip_status = false" → Exit Regular
5. Add Action node (VIP Support)
   - Type: "Send Email"
   - Configure: to = "vip-support@example.com"
   - Configure: subject = "VIP Customer Request"
   - Configure: body = "VIP customer {{contact.first_name}} needs assistance."
6. Add Action node (Regular Support)
   - Type: "Send Email"
   - Configure: to = "support@example.com"
   - Configure: subject = "Customer Request"
   - Configure: body = "Customer {{contact.first_name}} needs assistance."
7. Connect: Start → Conditional
8. Connect: Conditional Exit VIP → VIP Support Action
9. Connect: Conditional Exit Regular → Regular Support Action
10. Save and activate

---

## 3. Action Flows

### 3.1 Email Notification Flow
**Purpose**: Sends email notifications

**Steps**:
1. Create new flow named "Email Notification Flow"
2. Set trigger to "keywords" with keywords: "email,send,mail"
3. Add Start node
4. Add Action node
   - Type: "Send Email"
   - Configure: to = `{{contact.email}}`
   - Configure: subject = "Welcome to our service!"
   - Configure: body = "Hello {{contact.first_name}}, welcome to our platform!"
5. Connect Start → Action
6. Save and activate

### 3.2 Webhook Integration Flow
**Purpose**: Triggers external webhooks

**Steps**:
1. Create new flow named "Webhook Integration Flow"
2. Set trigger to "keywords" with keywords: "webhook,api,external"
3. Add Start node
4. Add Action node
   - Type: "Webhook"
   - Configure: url = "https://api.example.com/webhook"
   - Configure: method = "POST"
   - Configure: headers = {"Content-Type": "application/json"}
   - Configure: body = '{"contact_id": "{{contact.id}}", "message": "{{message}}"}'
5. Connect Start → Action
6. Save and activate

### 3.3 AI Response Flow
**Purpose**: Uses AI to generate responses

**Steps**:
1. Create new flow named "AI Response Flow"
2. Set trigger to "keywords" with keywords: "ai,assistant,help"
3. Add Start node
4. Add Action node
   - Type: "AI Response"
   - Configure: prompt = "You are a helpful customer service assistant. The customer said: {{message}}. Provide a helpful and friendly response."
   - Configure: proceed condition = "Always proceed"
5. Connect Start → Action
6. Save and activate

---

## 4. Complex Flows

### 4.1 Customer Service Flow
**Purpose**: Complex flow with multiple actions and conditions

**Steps**:
1. Create new flow named "Customer Service Flow"
2. Set trigger to "keywords" with keywords: "help,support,complaint"
3. Add Start node
4. Add Conditional node
   - Set condition type to "Message Contains"
   - Add conditions:
     - Value: "help" → Exit 1
     - Value: "support" → Exit 1
     - Value: "complaint" → Exit 2
5. Add Action node (Help Info)
   - Type: "Send Email"
   - Configure: to = `{{contact.email}}`
   - Configure: subject = "Help Information"
   - Configure: body = "Here is some helpful information for you."
6. Add Action node (Log Complaint)
   - Type: "Webhook"
   - Configure: url = "https://api.example.com/complaints"
   - Configure: method = "POST"
   - Configure: headers = {"Content-Type": "application/json"}
   - Configure: body = '{"contact_id": "{{contact.id}}", "complaint": "{{message}}"}'
7. Connect: Start → Conditional
8. Connect: Conditional Exit 1 → Help Info Action
9. Connect: Conditional Exit 2 → Log Complaint Action
10. Save and activate

### 4.2 AI Support Flow with Fallback
**Purpose**: AI responses with human fallback

**Steps**:
1. Create new flow named "AI Support Flow"
2. Set trigger to "keywords" with keywords: "ai,assistant,help"
3. Add Start node
4. Add Action node (AI Response)
   - Type: "AI Response"
   - Configure: prompt = "You are a helpful customer service assistant. The customer said: {{message}}. Provide a helpful and friendly response."
   - Configure: proceed condition = "Confidence threshold"
   - Configure: confidence threshold = 0.7
5. Add Conditional node
   - Set condition type to "Contact Field"
   - Add conditions:
     - Value: "ai_confidence >= 0.7" → Exit Success
     - Value: "ai_confidence < 0.7" → Exit Fallback
6. Add Action node (Human Support)
   - Type: "Send Email"
   - Configure: to = "support@example.com"
   - Configure: subject = "Customer needs human support"
   - Configure: body = "Customer {{contact.first_name}} needs human assistance. Message: {{message}}"
7. Connect: Start → AI Response
8. Connect: AI Response → Conditional
9. Connect: Conditional Exit Fallback → Human Support Action
10. Save and activate

---

## 5. Inactive Flows (for testing status)

### 5.1 Disabled Support Flow
**Purpose**: Inactive flow for testing

**Steps**:
1. Create new flow named "Disabled Support Flow"
2. Set trigger to "keywords" with keywords: "support,help,assist"
3. Add Start node
4. Add Action node
   - Type: "Send Email"
   - Configure: to = `{{contact.email}}`
   - Configure: subject = "Welcome to our service!"
   - Configure: body = "Hello {{contact.first_name}}, welcome to our platform!"
5. Connect Start → Action
6. Save but keep inactive

### 5.2 Disabled Marketing Flow
**Purpose**: Inactive marketing flow

**Steps**:
1. Create new flow named "Disabled Marketing Flow"
2. Set trigger to "keywords" with keywords: "marketing,promotion,offer"
3. Add Start node
4. Add Conditional node
   - Set condition type to "Message Contains"
   - Add conditions:
     - Value: "interested" → Exit 1
     - Value: "maybe" → Exit 2
5. Add Action node
   - Type: "Send Email"
   - Configure: to = `{{contact.email}}`
   - Configure: subject = "Here is our product brochure"
   - Configure: body = "Thank you for your interest! Here is our detailed product brochure."
6. Connect: Start → Conditional
7. Connect: Conditional Exit 1 → Action
8. Save but keep inactive

---

## Testing Scenarios

### Test Case 1: Basic Flow Execution
1. Create Welcome Flow
2. Add a new contact to trigger the flow
3. Verify email is sent to the contact

### Test Case 2: Conditional Branching
1. Create Message Type Router
2. Send messages containing "help", "info", "random"
3. Verify correct branches are taken

### Test Case 3: Action Execution
1. Create Webhook Integration Flow
2. Trigger with keyword "webhook"
3. Verify webhook is called with correct data

### Test Case 4: AI Response
1. Create AI Response Flow
2. Send a message to trigger AI response
3. Verify AI generates appropriate response

### Test Case 5: Complex Flow
1. Create Customer Service Flow
2. Test with "help", "complaint" messages
3. Verify correct actions are executed

### Test Case 6: Inactive Flows
1. Create inactive flows
2. Verify they don't execute when triggered
3. Test activating/deactivating flows

---

## Expected Results

- **Active flows** should execute when triggered
- **Inactive flows** should not execute
- **Conditional nodes** should route to correct branches
- **Action nodes** should perform their configured actions
- **AI responses** should generate contextual responses
- **Webhooks** should make HTTP requests to configured URLs
- **Email actions** should send emails to specified recipients

---

## Troubleshooting

### Common Issues:
1. **Flow not triggering**: Check trigger type and keywords
2. **Actions not executing**: Verify action configuration
3. **Conditionals not working**: Check condition values and operators
4. **AI not responding**: Verify AI service configuration
5. **Webhooks failing**: Check URL and authentication

### Debug Steps:
1. Check flow logs for execution details
2. Verify contact data is available for placeholders
3. Test individual actions in isolation
4. Check database for flow execution records

---

## Notes

- All flows should be created with organization_id = 1
- Use realistic test data for better testing
- Test both positive and negative scenarios
- Verify error handling for invalid configurations
- Test flow performance with multiple concurrent executions 