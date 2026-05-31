<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نستخدم generated (virtual) columns لأن MySQL لا يدعم partial indexes.
 *
 * المنطق:
 *   - phone_active_key  = phone  عندما deleted_at IS NULL, وإلا NULL.
 *   - wam_id_active_key = wam_id عندما deleted_at IS NULL, وإلا NULL.
 *
 * MySQL يسمح بقيم NULL متعددة في unique index، لذا الـ soft-deleted records
 * لن تتعارض مع بعضها ولا مع الـ active records.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── contacts ─────────────────────────────────────────────────────────
        DB::statement("
            ALTER TABLE contacts
            ADD COLUMN phone_active_key VARCHAR(255)
                GENERATED ALWAYS AS (IF(deleted_at IS NULL, phone, NULL)) VIRTUAL
        ");

        DB::statement("
            ALTER TABLE contacts
            ADD UNIQUE INDEX contacts_org_phone_active_unique (organization_id, phone_active_key)
        ");

        // ── chats ─────────────────────────────────────────────────────────────
        DB::statement("
            ALTER TABLE chats
            ADD COLUMN wam_id_active_key VARCHAR(128)
                GENERATED ALWAYS AS (IF(deleted_at IS NULL, wam_id, NULL)) VIRTUAL
        ");

        DB::statement("
            ALTER TABLE chats
            ADD UNIQUE INDEX chats_wam_id_active_unique (wam_id_active_key)
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE contacts DROP INDEX contacts_org_phone_active_unique");
        DB::statement("ALTER TABLE contacts DROP COLUMN phone_active_key");

        DB::statement("ALTER TABLE chats DROP INDEX chats_wam_id_active_unique");
        DB::statement("ALTER TABLE chats DROP COLUMN wam_id_active_key");
    }
};
