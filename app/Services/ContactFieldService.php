<?php

namespace App\Services;

use App\Http\Resources\ContactFieldResource;
use App\Models\ContactField;

class ContactFieldService
{
    private $organizationId;
    
    public function __construct($organizationId = NULL)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Get all contact fields based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $rows = ContactField::where('organization_id', $this->organizationId)
            ->where('deleted_at', null)
            ->orderBy('position', 'asc')
            ->paginate(5);

        return ContactFieldResource::collection($rows);
    }

    public function getByUuid($uuid = null)
    {
        return ContactField::where('organization_id', $this->organizationId)->where('uuid', $uuid)->first();;
    }

    /**
     * Store Contact Field
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\ContactField
     */
    public function store(object $request, $uuid = NULL)
    {
        $last_position = ContactField::where('organization_id', $this->organizationId)->where('deleted_at', null)->count();

        $field = $uuid === null ? new ContactField() : ContactField::where('uuid', $uuid)->firstOrFail();
        $field->organization_id = $this->organizationId;
        $field->name = $request->name;
        $field->type = $request->component;

        if($uuid === null){
            $field->position = $last_position + 1;
        }

        if($request->component === 'select'){
            $transformedString = collect($request->options)->pluck('value')->implode(', ');
            $field->value = $transformedString;
        } else if($request->component === 'input'){
            $field->value = $request->type;
        } else {
            $field->value = null;
        }

        $field->required = $request->required;
        $field->save();

        return $field;
    }

    /**
     * Delete ContactField
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\ContactField
     */
    public function delete($uuid)
    {
        return ContactField::where('uuid', $uuid)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => auth()->user()->id
        ]);
    }

    /**
     * Update positions of contact fields
     *
     * @param array $orderedIds Array of contact field IDs in the new order
     * @return bool
     */
    public function updatePositions($orderedIds)
    {
        foreach ($orderedIds as $index => $uuid) {
            ContactField::where('uuid', $uuid)
                ->where('organization_id', $this->organizationId)
                ->update(['position' => $index + 1]);
        }
        
        return true;
    }
}