<?php

// app/Exports/LanguageJsonExport.php

namespace App\Exports;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class LanguageJsonExport implements FromCollection, WithHeadings
{
    protected $languageCode;

    public function __construct(string $languageCode)
    {
        $this->languageCode = $languageCode;
    }

    public function collection()
    {
        // Retrieve the path to the JSON language file
        $langDirectory = base_path('lang');
        $langFilePath = $langDirectory . '/' . $this->languageCode . '.json';

        // Check if the language file exists
        if (!file_exists($langFilePath)) {
            return collect([]);
        }

        // Read the contents of the language file
        $contents = File::get($langFilePath);
        $translations = json_decode($contents, true);

        // Prepare collection from translations
        $collection = collect($translations)->map(function ($value, $key) {
            return ['Key' => $key, 'Translation' => $value];
        });

        return $collection;
    }

    public function headings(): array
    {
        return [
            'Key',
            'Translation',
        ];
    }
}