<?php

namespace Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refuseRealNetwork();
    }

    /**
     * لا اتصال حقيقيّ بالشبكة من أي اختبار.
     *
     * اختبارٌ يصل Meta أو تيليجرام فعلاً بطيءٌ ومتقلّب، ويمرّ أو يفشل بحسب
     * الشبكة لا بحسب الكود — وكانت اختبارات حملات تُرسل إلى graph.facebook.com
     * بلا رمز. الآن يفشل فوراً باسم الرابط، فيُزيَّف (fakeGraph / Http::fake).
     * الاختبار الذي يربط عميل Guzzle بنفسه (fakeGraph) يحلّ محلّ هذا الربط.
     */
    protected function refuseRealNetwork(): void
    {
        Http::preventStrayRequests();

        $this->app->bind(Client::class, fn () => new Client([
            'handler' => HandlerStack::create(static function (RequestInterface $request) {
                throw new \RuntimeException(
                    'Real network call in a test: ' . $request->getMethod() . ' ' . $request->getUri()
                    . ' — fake it (fakeGraph / Http::fake).'
                );
            }),
        ]));
    }
}
