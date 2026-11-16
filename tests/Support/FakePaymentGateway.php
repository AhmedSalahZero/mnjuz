<?php

namespace Tests\Support;

/**
 * بوّابة دفع وهمية لاختبار /pay: تسجّل ما طُلب منها وتُرجع رابط دفع.
 * تُربط عبر config('services.<key>.class').
 */
class FakePaymentGateway
{
    /** @var list<array{amount: mixed, plan_id: mixed, context: array}> */
    public static array $calls = [];

    public function handlePayment($amount, $planId = null, array $context = [])
    {
        self::$calls[] = ['amount' => $amount, 'plan_id' => $planId, 'context' => $context];

        return (object) ['success' => true, 'data' => 'https://pay.example/checkout'];
    }
}
