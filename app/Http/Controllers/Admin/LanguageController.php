<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreLanguage;
use App\Http\Resources\LangResource;
use App\Models\Language;
use App\Models\Setting;
use App\Services\LangService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LanguageController extends BaseController
{
    public function __construct(LangService $langService){
        $this->langService = $langService;
    }

    public function index(Request $request){
        return Inertia::render('Admin/Setting/Language/Index', [
            'title' => __('Languages'),
            'rows' => $this->langService->get($request), 
            'default_language' => Setting::where('key', 'default_language')->value('value') ?? 'en',
            'filters' => $request->all()
        ]);
    }

    public function translations(Request $request, $languageCode){
        $language = Language::where('code', $languageCode)->first();

        // Retrieve the path to the JSON language file
        $langDirectory = base_path('lang');
        $langFilePath = $langDirectory . '/' . $languageCode . '.json';

        if (!file_exists($langFilePath)) {
            return Inertia::render('Admin/Setting/Language/Show', [
                'title' => __('Language Translations'),
                'language' => $language,
                'rows' => [], 
                'filters' => $request->all()
            ]);
        }

        // Read the contents of the language file
        $contents = File::get($langFilePath);
        $translations = json_decode($contents, true);

        // Prepare rows from translations
        $rows = collect($translations)->map(function ($translation, $key) {
            return [
                'Key' => $key,
                'Translation' => $translation
            ];
        });

        $searchQuery = $request->input('search');
        if ($searchQuery) {
            $rows = $rows->filter(function ($row) use ($searchQuery) {
                $key = strtolower($row['Key']);
                return strpos($key, strtolower($searchQuery)) !== false;
            });
        }

        return Inertia::render('Admin/Setting/Language/Show', [
            'title' => __('Language Translations'),
            'language' => $language,
            'rows' => $rows->values()->toArray(),
            'filters' => $request->all()
        ]);
    }

    public function create(Request $request){
        $query = $this->langService->getById(NULL);

        return Inertia::render('Admin/Language/Show', ['title' => __('Languages'), 'faq' => $query]);
    }

    public function store(StoreLanguage $request){
        $this->langService->store($request);

        return redirect('/admin/languages')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Language added successfully!')
            ]
        );
    }

    public function show($id){
        $query = Language::where('id', $id)->first();
        return response()->json(['success' => true, 'item'=> $query]);
    }

    public function import(Request $request, $languageCode){
        // Retrieve the path to the JSON language file
        $langDirectory = base_path('lang');
        $langFilePath = $langDirectory . '/' . $languageCode . '.json';

        // Check if the language file exists
        if (!file_exists($langFilePath)) {
            return "Language file not found";
        }

        // Read the contents of the JSON language file
        $contents = File::get($langFilePath);
        $translations = json_decode($contents, true);

        // Get the uploaded Excel file
        $excelFile = $request->file;

        // Load the Excel file
        $rows = Excel::toArray([], $excelFile);

        // Get the first worksheet
        $worksheet = $rows[0];

        // Get the headers from the first row of the Excel file
        $headers = $worksheet[0];
        $excelKeys = array_map('strtolower', $headers);

        // Find the index of the 'key' and 'translation' headers
        $keyIndex = array_search('key', $excelKeys);
        $translationIndex = array_search('translation', $excelKeys);

        // Exit if headers are not found
        if ($keyIndex === false || $translationIndex === false) {
            return "Headers not found in Excel file";
        }

        // Iterate over rows in the Excel file starting from the second row (to skip headers)
        for ($i = 1; $i < count($worksheet); $i++) {
            $row = $worksheet[$i];

            // Get key and translation values from the current row
            $excelKey = $row[$keyIndex];
            $excelTranslation = $row[$translationIndex];

            // Update the translation in the JSON file if the key exists
            if (array_key_exists($excelKey, $translations)) {
                $translations[$excelKey] = $excelTranslation;
            }
        }

        // Convert the updated translations array back to JSON
        $updatedContents = json_encode($translations, JSON_PRETTY_PRINT);

        // Write the updated JSON content back to the file
        File::put($langFilePath, $updatedContents);

        return "Translations updated successfully";
    }

    public function export($id){
        return $this->langService->exportToCsv($id);
    }

    public function update(StoreLanguage $request, $id){
        $this->langService->store($request, $id);

        return redirect('/admin/languages')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Language updated successfully!')
            ]
        );
    }

    public function updateTranslation(Request $request, $languageCode, $key){
        // Retrieve the path to the JSON language file
        $langDirectory = base_path('lang');
        $langFilePath = $langDirectory . '/' . $languageCode . '.json';

        // Check if the language file exists
        if (!file_exists($langFilePath)) {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Language file not found')
                ]
            );
        }

        // Read the contents of the JSON language file
        $contents = File::get($langFilePath);
        $translations = json_decode($contents, true);

        // Check if the key exists in the translations
        if (!array_key_exists($key, $translations)) {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Key not found')
                ]
            );
        }

        // Update the translation with the new value from the request
        $translations[$key] = $request->translation;

        // Convert the updated translations array back to JSON
        $updatedContents = json_encode($translations, JSON_PRETTY_PRINT);

        // Write the updated JSON content back to the file
        File::put($langFilePath, $updatedContents);

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Translation updated successfully')
            ]
        );
    }

    public function setDefault(Request $request, $languageCode)
    {
        // Update or insert the default_language setting in the settings table
        DB::table('settings')
            ->updateOrInsert([
                'key' => 'default_language'
            ], [
                'value' => $languageCode,
            ]);

        // Set the application's default locale
        App::setLocale($languageCode);

        return redirect('/admin/languages')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Language updated successfully!')
            ]
        );
    }

    public function destroy(Request $request, $id){
        $this->langService->delete($request, $id);

        return redirect('/admin/languages')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Language deleted successfully!')
            ]
        );
    }
}