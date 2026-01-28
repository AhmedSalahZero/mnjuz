<?php 

if (!function_exists('getApiLang')) {
    function getApiLang(): string
    {
        return app()->getLocale() ?? 'ar';
    }
}
