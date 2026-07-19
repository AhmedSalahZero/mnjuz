<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. تنظيف contacts المكررة — الاحتفاظ بأقدم record لكل (organization_id, phone).
 * 2. تنظيف chats المكررة بنفس wam_id — الاحتفاظ بأقدم record.
 * 3. إضافة UNIQUE constraint على contacts(organization_id, phone).
 * 4. إضافة UNIQUE constraint على chats(wam_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. تنظيف duplicate contacts ───────────────────────────────────────
        // لكل مجموعة (organization_id, phone) احتفظ بأصغر id وأعمل soft-delete للباقي.
        DB::statement("
            UPDATE contacts c
            INNER JOIN (
                SELECT organization_id, phone, MIN(id) AS keep_id
                FROM contacts
                WHERE deleted_at IS NULL
                  AND phone IS NOT NULL
                GROUP BY organization_id, phone
                HAVING COUNT(*) > 1
            ) dup ON c.organization_id = dup.organization_id
                  AND c.phone = dup.phone
            SET c.deleted_at = NOW()
            WHERE c.id <> dup.keep_id
              AND c.deleted_at IS NULL
        ");

        // ── 2. تنظيف duplicate chats (نفس wam_id) ────────────────────────────
        // احتفظ بأصغر id وأعمل soft-delete للباقي.
        DB::statement("
            UPDATE chats c
            INNER JOIN (
                SELECT wam_id, MIN(id) AS keep_id
                FROM chats
                WHERE wam_id IS NOT NULL
                  AND deleted_at IS NULL
                GROUP BY wam_id
                HAVING COUNT(*) > 1
            ) dup ON c.wam_id = dup.wam_id
            SET c.deleted_at = NOW()
            WHERE c.id <> dup.keep_id
              AND c.deleted_at IS NULL
        ");

        // ── 3. UNIQUE على contacts(organization_id, phone) ───────────────────
        // نضيف على الـ active records فقط عبر partial-unique index.
        // MySQL لا يدعم partial index، نستخدم UNIQUE عادي بعد التنظيف أعلاه.
        try {
            Schema::table('contacts', function (Blueprint $table) {
                $table->unique(['organization_id', 'phone'], 'contacts_org_phone_unique');
            });
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name') ||
                str_contains($e->getMessage(), 'Duplicate entry')) {
                // تجاهل لو موجود بالفعل
            } else {
                throw $e;
            }
        }

        // ── 4. UNIQUE على chats(wam_id) ──────────────────────────────────────
        try {
            Schema::table('chats', function (Blueprint $table) {
                $table->unique('wam_id', 'chats_wam_id_unique');
            });
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name') ||
                str_contains($e->getMessage(), 'Duplicate entry')) {
                // تجاهل لو موجود بالفعل
            } else {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        try {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropUnique('contacts_org_phone_unique');
            });
        } catch (\Throwable) {}

        try {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropUnique('chats_wam_id_unique');
            });
        } catch (\Throwable) {}
    }
};
