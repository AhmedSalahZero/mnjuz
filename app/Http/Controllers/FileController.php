<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FileController extends Controller
{
    public function show(Request $request, $filename)
    {
        $path = storage_path('app/' . $filename);

        if (!file_exists($path)) {
            return response(__('File not found'), 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $mime = @mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=86400',
            'Content-Type' => $mime,
        ]);
    }
}
