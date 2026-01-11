<?php

namespace App\Services;

use App\Helpers\DateTimeHelper;
use App\Http\Resources\ChatNoteResource;
use App\Models\ChatLog;
use App\Models\ChatNote;
use App\Models\Contact;

class ChatNoteService
{
    public function get(object $request)
    {
        $rows = (new ChatNote)->listAll($request->query('search'));

        return ChatNoteResource::collection($rows);
    }

    public function getByUuid($uuid = null)
    {
        return ChatNote::where('id', $uuid)->first();
    }

    public function store(object $request, $uuid = NULL)
    {
        $contact = Contact::where('uuid', $request->contact)->first();

        $note = $uuid === null ? new ChatNote() : ChatNote::where('uuid', $uuid)->firstOrFail();
        $note->contact_id = $contact->id;
        $note->content = $request->notes;
        $note->created_by = auth()->user()->id;
        $note->save();

        ChatLog::insert([
            'contact_id' => $contact->id,
            'entity_type' => 'notes',
            'entity_id' => $note->id,
            'created_at' => DateTimeHelper::convertToOrganizationTimezone(now())
        ]);

        return $note;
    }

    public function delete($uuid)
    {
        $note = ChatNote::where('uuid', $uuid)->firstOrFail();
        $note->deleted_at = date('Y-m-d H:i:s');
        $note->deleted_by = auth()->user()->id;
        $note->save();
    } 
}
