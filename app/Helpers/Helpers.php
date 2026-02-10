<?php

if (!function_exists('getApiLang')) {
    function getApiLang(): string
    {
        return app()->getLocale() ?? 'ar';
    }
}

/**
 * Sanitize a filename for use in storage paths (local/S3).
 * Removes Unicode control/format characters (\p{C}) that trigger Flysystem CorruptedPathDetected.
 * Use the returned value only for building paths; keep the original name for display/API if needed.
 */
if (!function_exists('sanitize_filename_for_storage')) {
    function sanitize_filename_for_storage(string $fileName): string
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = pathinfo($fileName, PATHINFO_FILENAME);

        // Remove Unicode "Other" (control, format, surrogate) - same check Flysystem uses
        $base = preg_replace('/\p{C}+/u', '', $base);
        $base = str_replace(['/', '\\', "\0"], '_', $base);
        $base = trim(preg_replace('/\s+/', ' ', $base));

        if ($base === '') {
            $base = 'file';
        }

        $ext = preg_replace('/\p{C}+/u', '', $ext);

        return $ext !== '' ? $base . '.' . $ext : $base;
    }
}
