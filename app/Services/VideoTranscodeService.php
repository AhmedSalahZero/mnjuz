<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class VideoTranscodeService
{
    /**
     * Prepare a video for WhatsApp delivery: fast remux first, full transcode if needed.
     *
     * @param  bool  $forceFullTranscode  Skip remux (e.g. Meta error 131053 needs re-encode, not copy).
     */
    public function transcodeForWhatsapp(string $inputPath, bool $forceFullTranscode = false): ?string
    {
        if (!is_readable($inputPath)) {
            return null;
        }

        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === null) {
            return null;
        }

        if (!$forceFullTranscode) {
            $remuxOutput = $this->tempOutputPath();
            if ($this->remux($ffmpeg, $inputPath, $remuxOutput)) {
                return $remuxOutput;
            }

            @unlink($remuxOutput);
        }

        $transcodeOutput = $this->tempOutputPath();
        if ($this->fullTranscode($ffmpeg, $inputPath, $transcodeOutput)) {
            return $transcodeOutput;
        }

        @unlink($transcodeOutput);

        return null;
    }

    public function isAvailable(): bool
    {
        return $this->ffmpegBinary() !== null;
    }

    private function ffmpegBinary(): ?string
    {
        $configured = config('ffmpeg.ffmpeg.binaries');
        if (is_string($configured) && $configured !== '' && $this->isUsableBinary($configured)) {
            return $configured;
        }

        if ($this->isUsableBinary('ffmpeg')) {
            return 'ffmpeg';
        }

        Log::warning('VideoTranscodeService: ffmpeg binary not found or not executable');

        return null;
    }

    private function isUsableBinary(string $binary): bool
    {
        if ($binary !== 'ffmpeg' && is_executable($binary)) {
            return true;
        }

        $result = Process::run(['bash', '-lc', 'command -v ' . escapeshellarg($binary)]);

        return $result->successful() && trim($result->output()) !== '';
    }

    private function remux(string $ffmpeg, string $inputPath, string $outputPath): bool
    {
        $result = Process::timeout((int) config('ffmpeg.timeout', 3600))
            ->run([
                $ffmpeg,
                '-y',
                '-i', $inputPath,
                '-c', 'copy',
                '-movflags', '+faststart',
                $outputPath,
            ]);

        if (!$result->successful()) {
            Log::info('VideoTranscodeService: remux failed', [
                'output' => $result->errorOutput(),
            ]);

            return false;
        }

        return $this->isValidOutput($outputPath);
    }

    private function fullTranscode(string $ffmpeg, string $inputPath, string $outputPath): bool
    {
        $result = Process::timeout((int) config('ffmpeg.timeout', 3600))
            ->run([
                $ffmpeg,
                '-y',
                '-i', $inputPath,
                '-c:v', 'libx264',
                '-profile:v', 'main',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-movflags', '+faststart',
                $outputPath,
            ]);

        if (!$result->successful()) {
            Log::warning('VideoTranscodeService: full transcode failed', [
                'output' => $result->errorOutput(),
            ]);

            return false;
        }

        return $this->isValidOutput($outputPath);
    }

    private function isValidOutput(string $path): bool
    {
        return is_file($path) && filesize($path) > 0;
    }

    private function tempOutputPath(): string
    {
        return tempnam(sys_get_temp_dir(), 'wa_video_') . '.mp4';
    }
}
