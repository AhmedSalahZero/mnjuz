<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncTranslations extends Command
{
    protected $signature = 'translations:sync';
    protected $description = 'Sync translations across all language files';

    public function handle()
    {
        $langPath = base_path('lang');
        $defaultLocale = 'en';
        $locales = array_map(function($file) {
            return pathinfo($file, PATHINFO_FILENAME);
        }, File::files($langPath));

        // Get all translations from the default locale
        $defaultTranslations = $this->getTranslations($defaultLocale);
        
        // Update all other locale files
        foreach ($locales as $locale) {
            if ($locale === $defaultLocale) {
                continue;
            }

            $localeTranslations = $this->getTranslations($locale);
            
            // Merge translations, keeping existing translations and adding missing ones
            $mergedTranslations = array_merge($defaultTranslations, $localeTranslations);
            
            // Save the merged translations
            $this->saveTranslations($locale, $mergedTranslations);
            
            $this->info("Updated translations for {$locale}");
        }

        $this->info('Translation sync completed successfully!');
    }

    protected function getTranslations($locale)
    {
        $file = base_path("lang/{$locale}.json");
        if (!File::exists($file)) {
            return [];
        }
        
        return json_decode(File::get($file), true) ?? [];
    }

    protected function saveTranslations($locale, $translations)
    {
        $file = base_path("lang/{$locale}.json");
        File::put($file, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
} 