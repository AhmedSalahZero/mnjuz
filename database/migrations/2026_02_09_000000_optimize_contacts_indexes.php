<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تحسين indexes جدول contacts:
     * - إزالة indexes مكررة (نفس العمود أو نفس الغرض).
     * - إضافة مركّب (organization_id, phone) للبحث السريع عن contact بالمنظمة + الهاتف.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $drops = [
            'idx_contacts_organization_id',   // مكرر مع contacts_organization_id_index
            'idx_contacts_uuid',              // مكرر مع contacts_uuid_unique
            'idx_contacts_latest_chat',       // مكرر مع contacts_latest_chat_created_at_index
            'idx_contacts_deleted_at',        // مكرر مع contacts_deleted_at_index
        ];

        foreach ($drops as $name) {
            try {
                DB::statement("ALTER TABLE contacts DROP INDEX `{$name}`");
            } catch (\Throwable $e) {
                // الـ index قد لا يكون موجوداً (مثلاً في بيئة مختلفة)
            }
        }

        try {
            DB::statement('CREATE INDEX idx_contacts_organization_id_phone ON contacts (organization_id, phone)');
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        
    }
};
