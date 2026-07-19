<?php 

return [
    'ffmpeg' => [
        'binaries' => storage_path('ffmpeg/ffmpeg')
    ],
    'ffprobe' =>  [
        'binaries' => storage_path('ffmpeg/ffprobe')
    ],
    'timeout' => 3600, // Timeout in seconds
    'threads' => 12, // Number of threads
];
