<?php

namespace App\Imports;

use App\Models\ContactGroup;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;

class ContactGroupsImport implements ToModel, WithHeadingRow
{
    protected $successfulImports = 0;
    protected $totalImports = 0;
    protected $failedImportsDueToFormat = 0;
    protected $failedImportsDueToDuplicates = 0;

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        try {
            $this->totalImports++;

            // Sequential Validation: First check the first_name field
            $validator = Validator::make($row, [
                'group_name' => 'required', // Make first_name required
            ]);

            // If first_name fails, stop further validation and return the error
            if ($validator->fails()) {
                $this->failedImports[] = [
                    'row' => $row['group_name'],
                    'error' => __('Name required!')
                ];
                $this->failedImportsDueToFormat++;
                return null;
            }

            if (ContactGroup::where('organization_id', session()->get('current_organization'))
                    ->where('name', $row['group_name'])
                    ->whereNull('deleted_at')->exists()) {
                $this->failedImports[] = [
                    'row' => $row['group_name'],
                    'error' => __('Duplicate group name!')
                ];
                $this->failedImportsDueToDuplicates++;
                return null;
            }
            
            $contactGroup = new ContactGroup([
                'organization_id'  => session()->get('current_organization'),
                'name'  => $row['group_name'],
                'created_by'  => auth()->user()->id,
            ]);

            if($contactGroup){
                $this->successfulImports++;
                return $contactGroup;
            }
        } catch (\Exception $e) {
            $this->failedImports[] = [
                'row' => $row['group_name'],
                'error' => __('Invalid format!')
            ];
            $this->failedImportsDueToFormat++;
            
            return null;
        }
    }

    public function getFailedImportsDueToDuplicatesCount()
    {
        return $this->failedImportsDueToDuplicates;
    }

    public function getFailedImportsDueToFormat()
    {
        return $this->failedImportsDueToFormat;
    }

    public function getSuccessfulImports()
    {
        return $this->successfulImports;
    }

    public function getFailedImports()
    {
        return $this->failedImports;
    }

    public function getTotalImportsCount()
    {
        return $this->totalImports;
    }
}


