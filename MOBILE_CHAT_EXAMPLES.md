# أمثلة الـ chat response — واحد لكل type

المصدر: `GET /api/v1/list-messages-from-uuid-to-end`. كل الأمثلة أدناه **ردود حقيقية** من الـ endpoint، منسوخة كما هي.

## شكل الرد

```
{
  "statusCode": 200,
  "success": true,
  "message": "Chat messages fetched successfully",
  "data": [
    {
      "contact_id": 1,
      "last_inbound_chat_created_at": "2026-09-07 14:43:55",
      "is_blocked": 0,
      "ticket_status": null,
      "ticket_assigned_to": null,
      "unread_messages_count": 1,
      "contact_categories": [],
      "messages": [ { "type": "chat", "value": { ... } } ]
    }
  ]
}
```

الأمثلة التالية هي محتوى عنصر واحد من `messages`.


## الحقول الثابتة في كل رسالة

| الحقل | النوع | nullable |
|---|---|---|
| `id` | int | لا |
| `uuid` | string | لا |
| `contact_id` | int | لا |
| `contact_uuid` | string | لا |
| `phone` | string | لا |
| `metadata` | string (JSON) | لا |
| `type` | string: `inbound` \| `outbound` | لا |
| `wam_id` | string | **نعم** |
| `status` | string: `sent` \| `delivered` \| `read` \| `failed` | **نعم** |
| `media` | object | **نعم** |
| `logs` | array | لا (قد تكون فارغة) |
| `user` | object | **نعم** (الوارد دائمًا `null`) |
| `deleted_at` | string | **نعم** |

---

## text — نص عادي
```json
{
  "type": "chat",
  "value": {
    "id": 1,
    "uuid": "fea52218-e30d-4c27-bc5d-c75ae9a09832",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:22:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"text\",\"text\":{\"body\":\"السلام عليكم، عندي استفسار عن الطلب.\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.4BFC9F3E1C1E",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "text",
  "text": {
    "body": "السلام عليكم، عندي استفسار عن الطلب."
  }
}
```

---

## image
```json
{
  "type": "chat",
  "value": {
    "id": 5,
    "uuid": "485ef8a4-b6d6-49b1-8eaf-6a656743d7ae",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:30:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"image\",\"image\":{\"caption\":\"الفاتورة المرفقة\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.C3AD2E9F0B43",
    "status": "delivered",
    "media": {
      "type": "image/jpeg",
      "size": "6080",
      "path": "https://app.mnjz.net/media/public/seed-message-types/photo.jpg",
      "name": "N/A"
    },
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "image",
  "image": {
    "caption": "الفاتورة المرفقة"
  }
}
```

---

## image — بلا ملف (media = null)
```json
{
  "type": "chat",
  "value": {
    "id": 7,
    "uuid": "378dd2be-fa19-42a5-9274-9bb4cea9bc3c",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:34:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"image\",\"image\":{\"caption\":null}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.E3B9F80B74C0",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "image",
  "image": {
    "caption": null
  }
}
```

---

## video
```json
{
  "type": "chat",
  "value": {
    "id": 8,
    "uuid": "51c30bbe-b911-48cc-b186-500d37e6b152",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:36:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"video\",\"video\":{\"caption\":\"شاهد المشكلة في الفيديو\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.254FE7087E72",
    "status": "delivered",
    "media": {
      "type": "video/mp4",
      "size": "43136",
      "path": "https://app.mnjz.net/media/public/seed-message-types/clip.mp4",
      "name": "N/A"
    },
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "video",
  "video": {
    "caption": "شاهد المشكلة في الفيديو"
  }
}
```

---

## audio — رسالة صوتية
```json
{
  "type": "chat",
  "value": {
    "id": 10,
    "uuid": "46952fc5-196e-423a-83f5-e06a06217f9e",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:40:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"audio\",\"audio\":{\"voice\":true}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.989A076E54D3",
    "status": "delivered",
    "media": {
      "type": "audio/ogg",
      "size": "37671",
      "path": "https://app.mnjz.net/media/public/seed-message-types/voice.ogg",
      "name": "N/A"
    },
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "audio",
  "audio": {
    "voice": true
  }
}
```

---

## document
```json
{
  "type": "chat",
  "value": {
    "id": 12,
    "uuid": "18cbdd12-93cf-4b70-b09f-f1efa0dd1818",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:44:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"document\",\"document\":{\"filename\":\"invoice.pdf\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.E5A9827155B6",
    "status": "delivered",
    "media": {
      "type": "application/pdf",
      "size": "544",
      "path": "https://app.mnjz.net/media/public/seed-message-types/offer.pdf",
      "name": "invoice.pdf"
    },
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "document",
  "document": {
    "filename": "invoice.pdf"
  }
}
```

---

## sticker
```json
{
  "type": "chat",
  "value": {
    "id": 14,
    "uuid": "47e4b01f-f610-4e65-99d8-7e59d9390d9d",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:48:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"sticker\",\"sticker\":null}",
    "type": "inbound",
    "wam_id": "wamid.SEED.05C7993A8719",
    "status": "delivered",
    "media": {
      "type": "image/webp",
      "size": "2052",
      "path": "https://app.mnjz.net/media/public/seed-message-types/sticker.webp",
      "name": "N/A"
    },
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "sticker",
  "sticker": null
}
```

---

## location
```json
{
  "type": "chat",
  "value": {
    "id": 15,
    "uuid": "f4efba40-932a-477b-b797-f598f10a9ba7",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:50:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"location\",\"location\":{\"latitude\":21.485811,\"longitude\":39.192505,\"name\":\"فرع الروضة\",\"address\":\"الروضة، جدة\",\"url\":\"https://maps.google.com/?q=21.485811,39.192505\"},\"context\":{\"id\":\"wamid.SEED.LOCREQ\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.094C7DB9F508",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "location",
  "location": {
    "latitude": 21.485811,
    "longitude": 39.192505,
    "name": "فرع الروضة",
    "address": "الروضة، جدة",
    "url": "https://maps.google.com/?q=21.485811,39.192505"
  },
  "context": {
    "id": "wamid.SEED.LOCREQ"
  }
}
```

---

## contacts
```json
{
  "type": "chat",
  "value": {
    "id": 17,
    "uuid": "79b09118-81ba-4c1c-90e9-67e3b79aaf4b",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:54:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"contacts\",\"contacts\":[{\"name\":{\"formatted_name\":\"محمد أحمد\",\"first_name\":\"محمد\",\"last_name\":\"أحمد\",\"middle_name\":null,\"prefix\":null,\"suffix\":null},\"phones\":[{\"phone\":\"+966551112233\",\"wa_id\":\"966551112233\",\"type\":\"CELL\"}],\"emails\":[{\"email\":\"m@example.com\",\"type\":\"WORK\"}],\"org\":{\"company\":\"شركة النور\",\"department\":null,\"title\":null},\"addresses\":[],\"urls\":[],\"birthday\":null},{\"name\":{\"first_name\":\"سالم\",\"last_name\":null},\"phones\":[{\"wa_id\":\"966554445566\"}]}]}",
    "type": "inbound",
    "wam_id": "wamid.SEED.8EDAA1352D12",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "contacts",
  "contacts": [
    {
      "name": {
        "formatted_name": "محمد أحمد",
        "first_name": "محمد",
        "last_name": "أحمد",
        "middle_name": null,
        "prefix": null,
        "suffix": null
      },
      "phones": [
        {
          "phone": "+966551112233",
          "wa_id": "966551112233",
          "type": "CELL"
        }
      ],
      "emails": [
        {
          "email": "m@example.com",
          "type": "WORK"
        }
      ],
      "org": {
        "company": "شركة النور",
        "department": null,
        "title": null
      },
      "addresses": [],
      "urls": [],
      "birthday": null
    },
    {
      "name": {
        "first_name": "سالم",
        "last_name": null
      },
      "phones": [
        {
          "wa_id": "966554445566"
        }
      ]
    }
  ]
}
```

---

## interactive
```json
{
  "type": "chat",
  "value": {
    "id": 19,
    "uuid": "0bc5f37b-9ae7-4081-9f2a-151f7c8bccbe",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 13:58:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"interactive\",\"interactive\":{\"type\":\"button_reply\",\"button_reply\":{\"id\":\"btn_yes\",\"title\":\"أوافق\"}}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.6E5742727999",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "interactive",
  "interactive": {
    "type": "button_reply",
    "button_reply": {
      "id": "btn_yes",
      "title": "أوافق"
    }
  }
}
```

---

## button — رد على زر
```json
{
  "type": "chat",
  "value": {
    "id": 23,
    "uuid": "7edcfd12-4ccc-4fa6-8a56-5893229a069c",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 14:06:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"button\",\"button\":{\"text\":\"نعم\",\"payload\":\"YES\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.A8E265E40041",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "button",
  "button": {
    "text": "نعم",
    "payload": "YES"
  }
}
```

---

## system
```json
{
  "type": "chat",
  "value": {
    "id": 24,
    "uuid": "290d683c-46ad-4a9a-9b7c-aab9bed11449",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 14:08:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"system\",\"system\":{\"body\":\"تم تغيير الرقم إلى +966500000009\",\"type\":\"user_changed_number\",\"wa_id\":\"966500000009\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.11D1AB79A6E3",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "system",
  "system": {
    "body": "تم تغيير الرقم إلى +966500000009",
    "type": "user_changed_number",
    "wa_id": "966500000009"
  }
}
```

---

## edit — تعديل رسالة
```json
{
  "type": "chat",
  "value": {
    "id": 25,
    "uuid": "77e154cc-3c2b-457e-a12d-d396aa173006",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 14:10:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"edit\",\"edit\":{\"original_message_id\":\"wamid.SEED.ORIGINAL\",\"message\":{\"type\":\"text\",\"text\":{\"body\":\"النصّ بعد التعديل\"}}}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.3ACE033CE180",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "edit",
  "edit": {
    "original_message_id": "wamid.SEED.ORIGINAL",
    "message": {
      "type": "text",
      "text": {
        "body": "النصّ بعد التعديل"
      }
    }
  }
}
```

---

## revoke — حذف رسالة
```json
{
  "type": "chat",
  "value": {
    "id": 26,
    "uuid": "d3fea02c-1fff-4d4d-bf47-a10dc7ccc549",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 14:12:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"revoke\",\"revoke\":{\"original_message_id\":\"wamid.SEED.ORIGINAL\"}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.E183308AC6C7",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "revoke",
  "revoke": {
    "original_message_id": "wamid.SEED.ORIGINAL"
  }
}
```

---

## unsupported
```json
{
  "type": "chat",
  "value": {
    "id": 27,
    "uuid": "0d7dab45-188a-427a-9089-1469fc53afc0",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 14:14:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"unsupported\",\"errors\":[{\"code\":131051,\"title\":\"Message type not supported\",\"error_data\":{\"details\":\"Message type is not currently supported.\"}}],\"unsupported\":null}",
    "type": "inbound",
    "wam_id": "wamid.SEED.3A75C6370351",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "unsupported",
  "errors": [
    {
      "code": 131051,
      "title": "Message type not supported",
      "error_data": {
        "details": "Message type is not currently supported."
      }
    }
  ],
  "unsupported": null
}
```

---

## order — نوع غير معروف
```json
{
  "type": "chat",
  "value": {
    "id": 30,
    "uuid": "b71e4a6e-783f-434d-bb40-6fe5d56f8ed2",
    "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
    "contact_id": 1,
    "is_new_contact": false,
    "phone": "+966500000000",
    "formatted_phone_number": "+966 50 000 0000",
    "organization_id": 1,
    "latest_chat_created_at": "2026-09-07 14:43:55",
    "is_blocked": 0,
    "is_favorite": 0,
    "contact_full_name": "اختبار أنواع الرسائل",
    "unread_messages_count": 1,
    "created_at": "2026-09-07 14:20:35",
    "deleted_at": null,
    "metadata": "{\"type\":\"order\",\"order\":{\"catalog_id\":\"123\",\"product_items\":[]}}",
    "type": "inbound",
    "wam_id": "wamid.SEED.4BFF494FC2F9",
    "status": "delivered",
    "media": null,
    "logs": [],
    "user": null
  }
}
```
`metadata` بعد `JSON.parse`:
```json
{
  "type": "order",
  "order": {
    "catalog_id": "123",
    "product_items": []
  }
}
```
