<?php

return [
    // Action name => active (true/false)
    'add_to_group'      => true,
    'remove_from_group' => true,
    // يعيد جهة الاتصال إلى الحملات بعد انسحابها، بطلبها هي.
    'resubscribe_marketing' => true,
    'update_contact'    => true,
    'send_email'        => true,
    'delay'             => true,
    'webhook'           => true,
    'ai_response'       => false, // Disabled
    'conditional'       => false,
    // تحويل المحادثة إلى موظف، بمخرجين: متاح / غير متاح.
    'assign_agent'      => true,
    // Add more actions as needed
]; 