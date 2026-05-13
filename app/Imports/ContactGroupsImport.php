<?php

namespace App\Imports;

use App\Models\ContactGroup;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactGroupsImport implements ToModel, WithHeadingRow
{
    protected $successfulImports = 0;
    protected $totalImports = 0;
    protected $failedImportsDueToFormat = 0;
    protected $failedImportsDueToDuplicates = 0;
    protected $failedImports = [];

    /**
     * Resolve group label from row. Supports:
     * - XLSX template: "Group name" → group_name
     * - CSV export in app: header "Name" → name
     * - Fallback: first non-empty string cell (single-column sheets).
     */
    private function resolveGroupName(array $row): ?string
    {
        foreach (['group_name', 'name', 'group', 'title'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $v = $row[$key];
            if ($v === null) {
                continue;
            }
            if (is_numeric($v)) {
                $v = (string) $v;
            }
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t !== '') {
                return $t;
            }
        }

        foreach ($row as $key => $v) {
            if (is_string($key) && str_starts_with($key, '__')) {
                continue;
            }
            if (is_string($v)) {
                $t = trim($v);
                if ($t !== '') {
                    return $t;
                }
            }
        }

        return null;
    }

    private function rowLabelForError(array $row): string
    {
        $name = $this->resolveGroupName($row);

        return $name ?? '-';
    }

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        try {
            $this->totalImports++;

            $groupName = $this->resolveGroupName($row);

            $validator = Validator::make(
                ['group_name' => $groupName],
                ['group_name' => 'required|string|max:255'],
            );

            if ($validator->fails()) {
                $this->failedImports[] = [
                    'row' => $this->rowLabelForError($row),
                    'error' => __('Name required!'),
                ];
                $this->failedImportsDueToFormat++;

                return null;
            }

            if (ContactGroup::where('organization_id', session()->get('current_organization'))
                ->where('name', $groupName)
                ->whereNull('deleted_at')->exists()) {
                $this->failedImports[] = [
                    'row' => $groupName,
                    'error' => __('Duplicate group name!'),
                ];
                $this->failedImportsDueToDuplicates++;

                return null;
            }

            $contactGroup = new ContactGroup([
                'organization_id' => session()->get('current_organization'),
                'name' => $groupName,
                'created_by' => auth()->user()->id,
            ]);

            if ($contactGroup) {
                $this->successfulImports++;

                return $contactGroup;
            }
        } catch (\Exception $e) {
            $this->failedImports[] = [
                'row' => $this->rowLabelForError($row),
                'error' => __('Invalid format!'),
            ];
            $this->failedImportsDueToFormat++;

            return null;
        }

        return null;
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
