<?php

namespace App\Services;

use App\Exports\LanguageJsonExport;
use App\Http\Resources\LangResource;
use App\Models\Language;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Excel;

class LangService
{
    /**
     * Get all languages based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $rows = Language::where('deleted_at', null)->latest()->paginate(10);

        return LangResource::collection($rows);
    }

    public function getById($id = null)
    {
        return Language::where('id', $id)->first();
    }

    /**
     * Store Language
     *
     * @param Request $request
     * @param string $id
     * @return \App\Models\Language
     */
    public function store(object $request, $id = NULL)
    {
        try {
            $language = $id === null ? new Language() : Language::where('id', $id)->firstOrFail();

            $oldCode = $id === null ? $request->code : $language->code;
            $language->name = $request->name;
            $language->code = $request->code;
            $language->status = $request->status;
            $language->is_rtl = $request->is_rtl ?? false;
            $language->save();

            if($id === null || $request->code !== $oldCode){
                $langDirectory = base_path('lang');
                if (!file_exists($langDirectory)) {
                    mkdir($langDirectory, 0755, true);
                }

                // Read the contents of default_en.json
                $defaultLangFilePath = $langDirectory . '/default_en.json';

                $oldLangFilePath = $langDirectory . '/' . strtolower($oldCode) . '.json';
                if (file_exists($oldLangFilePath)) {
                    $newLangFilePath = $langDirectory . '/' . strtolower($request->code) . '.json';
                    rename($oldLangFilePath, $newLangFilePath);
                } else {
                    $defaultContent = File::get($defaultLangFilePath);
                    $newLangFilePath = $langDirectory . '/' . strtolower($request->code) . '.json';
                    File::put($newLangFilePath, $defaultContent);
                }
            }

            return $language;
        } catch (\Exception $e) {
            Log::error('Error creating language file: ' . $e->getMessage());
        }
    }

    /**
     * Export Language Translation Strings
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\Language
     */
    public function exportToCsv($languageCode)
    {
        return Excel::download(new LanguageJsonExport($languageCode), 'language_' . $languageCode . '.xlsx');
    }

    /**
     * Delete Language
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\Language
     */
    public function delete($request, $id)
    {
        try {
            $language = Language::findOrFail($id);

            // Delete the language file if it exists
            $langDirectory = base_path('lang');
            $langFilePath = $langDirectory . '/' . $language->code . '.json';
            if (file_exists($langFilePath)) {
                unlink($langFilePath);
                Log::info('Language file deleted: ' . $langFilePath);
            } else {
                Log::info('Language file does not exist: ' . $langFilePath);
            }

            return Language::where('id', $id)->update([
                'deleted_by' => auth()->user()->id,
                'deleted_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting language: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete language'], 500);
        }
    } 
}