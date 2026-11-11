<?php

namespace App\Support;

use Closure;

/**
 * ذاكرة لقراءات تتكرّر داخل طلب ويب واحد: اشتراك المنشأة وباقتها، وحالة
 * الإضافات. صفحة المحادثات كانت تقرأ الاشتراك نفسه ثماني مرّات.
 *
 * تعمل داخل طلب ويب فقط (CacheLookupsForRequest يفتحها ويغلقها): وظيفة
 * الطابور أو اختبارٌ يستدعي الخدمة مباشرةً يقرأ من القاعدة كل مرّة كما كان،
 * فلا يرى اشتراكاً قديماً بعد تجديده في الوظيفة نفسها.
 */
final class RequestLookupCache
{
    /** @var array<string, mixed>|null null = مغلقة. */
    private static ?array $values = null;

    public static function open(): void
    {
        self::$values = [];
    }

    public static function close(): void
    {
        self::$values = null;
    }

    public static function isOpen(): bool
    {
        return self::$values !== null;
    }

    public static function remember(string $key, Closure $resolve): mixed
    {
        if (self::$values === null) {
            return $resolve();
        }

        if (!array_key_exists($key, self::$values)) {
            self::$values[$key] = $resolve();
        }

        return self::$values[$key];
    }

    /** بعد كتابةٍ تغيّر ما خُزّن (تجديد اشتراك داخل الطلب نفسه). */
    public static function forget(string $prefix = ''): void
    {
        if (self::$values === null) {
            return;
        }

        if ($prefix === '') {
            self::$values = [];

            return;
        }

        foreach (array_keys(self::$values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$values[$key]);
            }
        }
    }
}
