<?php

namespace Tests\Unit;

use App\Services\MyFatoorah\MyFatoorahPaymentProcessor;
use Tests\TestCase;

class MyFatoorahPaymentProcessorTest extends TestCase
{
    public function test_it_parses_customer_reference(): void
    {
        $processor = new MyFatoorahPaymentProcessor();

        $parsed = $processor->parseCustomerReference('10_25_3');

        $this->assertSame(10, $parsed['organization_id']);
        $this->assertSame(25, $parsed['user_id']);
        $this->assertSame('3', $parsed['plan_id']);
    }

    public function test_it_parses_topup_customer_reference(): void
    {
        $processor = new MyFatoorahPaymentProcessor();

        $parsed = $processor->parseCustomerReference('10_25_topup');

        $this->assertSame(10, $parsed['organization_id']);
        $this->assertSame(25, $parsed['user_id']);
        $this->assertNull($parsed['plan_id']);
    }

    public function test_it_builds_customer_reference(): void
    {
        $processor = new MyFatoorahPaymentProcessor();

        $this->assertSame('10_25_3', $processor->buildCustomerReference(10, 25, 3));
        $this->assertSame('10_25_topup', $processor->buildCustomerReference(10, 25, null));
    }
}
