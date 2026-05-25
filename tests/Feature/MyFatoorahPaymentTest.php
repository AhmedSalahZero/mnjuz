<?php

namespace Tests\Feature;

use App\Services\MyFatoorahService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for MyFatoorah payment verification.
 *
 * Requires the mnjuz_testing database configured in phpunit.xml.
 */
class MyFatoorahPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_and_process_payment_fails_without_payment_id(): void
    {
        $service = new MyFatoorahService();
        $result = $service->verifyAndProcessPayment(null);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->message);
    }
}
