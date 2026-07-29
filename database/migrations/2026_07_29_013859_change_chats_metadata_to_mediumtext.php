<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * WhatsApp contact-share payloads (even without vcards) can exceed TEXT (65KB).
     * MEDIUMTEXT (~16MB) safely stores large contact lists in chat.metadata.
     */
    public function up()
    {
        // Use raw ALTER to avoid doctrine/dbal change() hangs on large chats tables.
        DB::statement('ALTER TABLE `chats` MODIFY `metadata` MEDIUMTEXT NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        DB::statement('ALTER TABLE `chats` MODIFY `metadata` TEXT NOT NULL');
    }
};
