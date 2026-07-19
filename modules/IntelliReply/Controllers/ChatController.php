<?php

namespace Modules\IntelliReply\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Organization;
use Illuminate\Http\Request;
use Modules\IntelliReply\Models\Document;
use OpenAI;
use Illuminate\Support\Facades\Storage;

class ChatController extends BaseController
{
    public function chat(Request $request)
    {
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $organizationConfig = $organizationConfig ? json_decode($organizationConfig->metadata, true) : [];

        $api_key = $organizationConfig['ai']['api_key'];
        $query = $request->input('query');
        $client = OpenAI::client($api_key);

        // Generate embedding for query
        $response = $client->embeddings()->create([
            'input' => $query,
            'model' => 'text-embedding-ada-002'
        ]);
        $queryEmbedding = $response->embeddings[0]->embedding;

        // Find the closest document
        $documents = Document::where('organization_id', $organizationId)->get();
        $closestDocument = null;
        $closestDistance = PHP_FLOAT_MAX;

        foreach ($documents as $document) {
            $documentEmbeddings = json_decode($document->embeddings);

            foreach ($documentEmbeddings as $documentEmbedding) {
                $distance = $this->cosineSimilarity($queryEmbedding, $documentEmbedding);

                if ($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closestDocument = $document;
                }
            }
        }

        if ($closestDocument) {
            $documentContent = $closestDocument->content;
            $response = $client->completions()->create([
                'model' => $organizationConfig['ai']['model'],
                'prompt' => "You are a customer support service AI Chatbot. You only provide answers that can be strictly found in documentation. If the information is not in the documentation say 'Sorry I don\'t have information about this' documentation:" . $documentContent . "\n\nquestion: " . $query . "\n\nanswer:",
                'max_tokens' => (int) $organizationConfig['ai']['max_tokens'],
                'temperature' => (int) $organizationConfig['ai']['temperature'],
            ]);
    
            return response()->json([
                'response' => $response->choices[0]->text,
            ]);
        }
    
        return response()->json([
            'response' => 'Sorry but I don\'t have any information about this.',
        ]);
    }

    private function cosineSimilarity($vecA, $vecB)
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($vecA); $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] ** 2;
            $normB += $vecB[$i] ** 2;
        }

        return 1 - ($dotProduct / (sqrt($normA) * sqrt($normB)));
    }
}
