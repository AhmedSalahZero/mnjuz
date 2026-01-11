<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @if(app()->environment('production'))
            <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        @endif
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @php
            $config = collect($page['props']['config']);
            $google_analytics = $config->firstWhere('key', 'google_analytics_tracking_id')['value'] ?? null;
            $favicon = $config->firstWhere('key', 'favicon')['value'] ?? null;
            $favicon = $favicon ? '/media/' . $favicon : '/images/favicon.png';
        @endphp
        <!-- Dynamic Favicon -->
        @if($favicon)
        <link rel="icon" href="{{ url($favicon) }}">
        @endif
        <link href="https://fonts.bunny.net/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
        @vite(['resources/js/app.js', 'resources/css/app.css'])
        @inertiaHead
        @if (!empty($google_analytics))
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $google_analytics }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ $google_analytics }}');
        </script>
        @endif
        
        @php
            $head_scripts = $config->firstWhere('key', 'head_scripts')['value'] ?? null;
            $head_styles = $config->firstWhere('key', 'head_styles')['value'] ?? null;
            $meta_tags = $config->firstWhere('key', 'meta_tags')['value'] ?? null;
            $body_scripts = $config->firstWhere('key', 'body_scripts')['value'] ?? null;
        @endphp
        
        @if (!empty($meta_tags))
        <!-- Custom Meta Tags -->
        {!! $meta_tags !!}
        @endif
        
        @if (!empty($head_styles))
        <!-- Custom Head Styles -->
        @if(str_contains($head_styles, '<style>'))
            {!! $head_styles !!}
        @else
            <style>
                {!! $head_styles !!}
            </style>
        @endif
        @endif
        
        @if (!empty($head_scripts))
        <!-- Custom Head Scripts -->
        @if(str_contains($head_scripts, '<script>'))
            {!! $head_scripts !!}
        @else
            <script>
                {!! $head_scripts !!}
            </script>
        @endif
        @endif
    </head>
    <body>
        @inertia
        
        @if (!empty($body_scripts))
        <!-- Custom Body Scripts -->
        @if(str_contains($body_scripts, '<script>'))
            {!! $body_scripts !!}
        @else
            <script>
                {!! $body_scripts !!}
            </script>
        @endif
        @endif
    </body>
</html>