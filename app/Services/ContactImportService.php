<?php

namespace App\Services;

use App\Imports\ContactsImport;
use Illuminate\Support\Facades\Cache;

class ContactImportService
{
    public static function cacheKey(int $organizationId, int $userId): string
    {
        return "contact_import:{$organizationId}:{$userId}";
    }

    public static function putStatus(int $organizationId, int $userId, array $payload, int $ttlSeconds = 3600): void
    {
        Cache::put(self::cacheKey($organizationId, $userId), $payload, $ttlSeconds);
    }

    public static function getStatus(int $organizationId, int $userId): ?array
    {
        return Cache::get(self::cacheKey($organizationId, $userId));
    }

    public static function forgetStatus(int $organizationId, int $userId): void
    {
        Cache::forget(self::cacheKey($organizationId, $userId));
    }

    public static function buildFlashStatus(ContactsImport $import): array
    {
        $successfulImports = $import->getSuccessfulImports();
        $updatedImports = $import->getUpdatedImports();
        $createdImports = $successfulImports - $updatedImports;
        $totalImports = $import->getTotalImportsCount();
        $failedImports = $totalImports - $successfulImports;

        if ($totalImports === 0) {
            $statusType = 'error';
            $statusMessage = __('No data rows were imported. Make sure the file has a header row (first_name, phone, email, group_name, ...) and at least one data row below it.');
        } elseif ($successfulImports === 0) {
            $statusType = 'error';
            $statusMessage = __('All rows failed to import. Please check the data format or duplicates.');
        } elseif ($failedImports === 0) {
            $statusType = 'success';
            if ($updatedImports > 0 && $createdImports > 0) {
                $statusMessage = __(':created contacts created and :updated contacts updated successfully!', [
                    'created' => $createdImports,
                    'updated' => $updatedImports,
                ]);
            } elseif ($updatedImports > 0) {
                $statusMessage = __(':updated contacts updated successfully!', ['updated' => $updatedImports]);
            } else {
                $statusMessage = __('All rows have been imported successfully!');
            }
        } else {
            $statusType = 'warning';
            $statusMessage = __('Some rows have been imported successfully, while others failed. Please check the error logs for details.');
        }

        return [
            'type' => $statusType,
            'message' => $statusMessage,
            'import_summary' => [
                'total_imports' => $totalImports,
                'successful_imports' => $successfulImports,
                'created_imports' => $createdImports,
                'updated_imports' => $updatedImports,
                'failed_imports' => $failedImports,
                'duplicate_entries' => $import->getFailedImportsDueToDuplicatesCount(),
                'invalid_format_entries' => $import->getFailedImportsDueToFormat(),
                'failed_rows_details' => $import->getFailedImports(),
                'failed_rows_truncated' => $import->getFailedImportsTruncated(),
                'failed_limit_entries' => $import->getFailedImportsDueToLimit(),
            ],
        ];
    }
}
