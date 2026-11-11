<?php

namespace Tests\Unit;

use App\Support\RequestLookupCache;
use PHPUnit\Framework\TestCase;

/**
 * ذاكرة الطلب الواحد: تعمل بين open وclose فقط، فوظيفة الطابور أو
 * الاختبار الذي يستدعي الخدمة مباشرةً يقرأ من القاعدة كل مرّة كما كان.
 */
class RequestLookupCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestLookupCache::close();
        parent::tearDown();
    }

    public function test_closed_it_resolves_every_time(): void
    {
        $calls = 0;
        RequestLookupCache::remember('k', function () use (&$calls) { return ++$calls; });
        RequestLookupCache::remember('k', function () use (&$calls) { return ++$calls; });

        $this->assertFalse(RequestLookupCache::isOpen());
        $this->assertSame(2, $calls);
    }

    public function test_open_it_resolves_once_per_key_including_null(): void
    {
        RequestLookupCache::open();
        $calls = 0;

        $first = RequestLookupCache::remember('subscription:1', function () use (&$calls) { $calls++; return null; });
        $second = RequestLookupCache::remember('subscription:1', function () use (&$calls) { $calls++; return 'x'; });
        RequestLookupCache::remember('subscription:2', function () use (&$calls) { $calls++; return 2; });

        $this->assertNull($first);
        $this->assertNull($second, 'a stored null is still a hit');
        $this->assertSame(2, $calls);
    }

    public function test_forget_drops_a_prefix_and_close_drops_everything(): void
    {
        RequestLookupCache::open();
        RequestLookupCache::remember('subscription:1', fn () => 'old');
        RequestLookupCache::remember('addon:Web Chat', fn () => true);

        RequestLookupCache::forget('subscription:');

        $this->assertSame('new', RequestLookupCache::remember('subscription:1', fn () => 'new'));
        $this->assertTrue(RequestLookupCache::remember('addon:Web Chat', fn () => false));

        RequestLookupCache::close();
        RequestLookupCache::open();
        $this->assertFalse(RequestLookupCache::remember('addon:Web Chat', fn () => false));
    }
}
