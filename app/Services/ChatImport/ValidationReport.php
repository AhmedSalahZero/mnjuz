<?php

namespace App\Services\ChatImport;

/**
 * نتيجة فحص الملف كلّه قبل أي كتابة.
 */
final class ValidationReport
{
    public bool $cancelled = false;

    public ?string $fileError = null;

    public int $totalRows = 0;

    public int $errorCount = 0;

    /** @var array<int, array{row: int|string, column: string, value: string, message: string}> */
    public array $errorsPreview = [];

    public ?string $errorsFilePath = null;

    public int $inFileDuplicates = 0;

    public int $existingDuplicates = 0;

    public int $newContacts = 0;

    /** @var array<int, string> */
    public array $ignoredColumns = [];

    public function isValid(): bool
    {
        return !$this->cancelled && $this->fileError === null && $this->errorCount === 0;
    }

    public function newMessages(): int
    {
        return max(0, $this->totalRows - $this->inFileDuplicates - $this->existingDuplicates);
    }

    public function duplicates(): int
    {
        return $this->inFileDuplicates + $this->existingDuplicates;
    }
}
