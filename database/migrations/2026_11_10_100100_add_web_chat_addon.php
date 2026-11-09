<?php

use App\Models\Addon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * إضافة «Web Chat»: المحادثة المباشرة من موقع التاجر.
 *
 * مقيّدة بالباقة كـ Working Hours: تظهر في صفحة الباقة تلقائياً
 * (SubscriptionPlanController::orderedPlanRestrictedAddonNames)، وتُفحص
 * بـ CustomHelper::isModuleEnabled('Web Chat', $organizationId).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Addon::where('name', 'Web Chat')->exists()) {
            return;
        }

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'utility',
            'name' => 'Web Chat',
            'logo' => 'webchat.svg',
            'description' => 'Add a live chat widget to your website or store. Visitor messages arrive in the same inbox, with the same automation and ticket assignment as WhatsApp.',
            'metadata' => null,
            'status' => 1,
            'is_active' => 1,
            'is_plan_restricted' => 1,
            'version' => '1.0',
        ]);
    }

    public function down(): void
    {
        Addon::where('name', 'Web Chat')->delete();
    }
};
