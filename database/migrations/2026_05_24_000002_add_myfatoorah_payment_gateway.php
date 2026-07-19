<?php

use App\Models\PaymentGateway;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PaymentGateway::firstOrCreate(
            ['name' => 'MyFatoorah'],
            [
                'metadata' => null,
                'is_active' => 0,
            ]
        );
    }

    public function down(): void
    {
        PaymentGateway::where('name', 'MyFatoorah')->delete();
    }
};
