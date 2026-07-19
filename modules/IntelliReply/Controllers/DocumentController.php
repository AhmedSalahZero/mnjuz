<?php

namespace Modules\IntelliReply\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Organization;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Modules\IntelliReply\Models\Document;
use Modules\IntelliReply\Requests\StoreDocuments;
use OpenAI;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

class DocumentController extends BaseController
{
    public function store(StoreDocuments $request)
    {
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $organizationConfig = $organizationConfig ? json_decode($organizationConfig->metadata, true) : [];

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();

        try{
            // Extract text content based on file type
            if ($extension == 'txt') {
                $content = file_get_contents($file->getPathname());
            } elseif ($extension == 'pdf') {
                $parser = new PdfParser();
                $pdf = $parser->parseFile($file->getPathname());
                $content = $pdf->getText();
            } elseif (in_array($extension, ['doc', 'docx'])) {
                $phpWord = WordIOFactory::load($file->getPathname());
                $content = $this->extractTextFromWord($phpWord);
            } else {
                return redirect()->back()->withErrors(['file' => 'Unsupported file type.']);
            }

            DB::transaction(function () use ($organizationConfig, $organizationId, $request, $content) {
                $settings = Setting::whereIn('key', [
                    'default_open_ai_key',
                    'enable_api_key_input'
                ])->pluck('value', 'key');

                $enableApiKeyInput = $settings->get('enable_api_key_input', 1);
                $defaultOpenAiKey = $settings->get('default_open_ai_key', $organizationConfig['ai']['api_key'] ?? '');
                $api_key = $enableApiKeyInput == 1 ? $organizationConfig['ai']['api_key'] : $defaultOpenAiKey;
                $client = OpenAI::client($api_key);

                $document = new Document();
                $document->organization_id = $organizationId;
                $document->source = 'File';
                $document->title = $request->input('title');
                $document->content = $content;
                $document->status = 'Pending';
                $document->save();

                // Generate embeddings
                $chunks = $this->splitDocument($content);

                $embeddings = [];
                foreach ($chunks as $chunk) {
                    $response = $client->embeddings()->create([
                        'input' => $chunk,
                        'model' => 'text-embedding-ada-002'
                    ]);
                    $embeddings[] = $response->embeddings[0]->embedding;
                }

                $document->embeddings = json_encode($embeddings);
                $document->status = 'Complete';
                $document->save();
            });

            return redirect()->back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Document uploaded successfully!'),
                ]
            );
        } catch (\Exception $e) {
            return redirect()->back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $e->getMessage(),
                ]
            );
        }  
    }

    public function update(Request $request, $uuid){
        
    }

    public function delete($uuid){
        $query = Document::where('uuid', $uuid)->delete();

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Row deleted successfully!')
            ]
        );
    }

    private function extractTextFromWord($phpWord)
    {
        $content = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                    foreach ($element->getElements() as $textElement) {
                        if (method_exists($textElement, 'getText')) {
                            $content .= $textElement->getText() . " ";
                        }
                    }
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                    $content .= $element->getText() . " ";
                } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            foreach ($cell->getElements() as $cellElement) {
                                if (method_exists($cellElement, 'getText')) {
                                    $content .= $cellElement->getText() . " ";
                                }
                            }
                        }
                    }
                } else {
                    // Handle other element types as needed or skip
                    continue;
                }
            }
        }
        return $content;
    }

    private function splitDocument($content)
    {
        // Split the document into chunks (e.g., paragraphs)
        return explode("\n\n", $content); // Adjust splitting logic as needed
    }
}
