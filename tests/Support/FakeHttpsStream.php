<?php

namespace Tests\Support;

/**
 * يستبدل https:// في file_get_contents بمحتوى ثابت أثناء الاختبار.
 *
 * WhatsappService::sendMedia ينزّل الملف كاملاً ليعرف حجمه، ولا شبكة في
 * الاختبارات. Guzzle وواجهة Http لا يمرّان بأغلفة PHP، فلا يتأثّران.
 * أعِده دائماً في finally.
 */
final class FakeHttpsStream
{
    public static string $body = 'fake-media-bytes';

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public static function register(string $body = 'fake-media-bytes'): void
    {
        self::$body = $body;
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', self::class);
    }

    public static function restore(): void
    {
        @stream_wrapper_restore('https');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_stat(): array
    {
        return ['size' => strlen(self::$body)];
    }

    public function url_stat(string $path, int $flags): array
    {
        return ['size' => strlen(self::$body)];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }
}
