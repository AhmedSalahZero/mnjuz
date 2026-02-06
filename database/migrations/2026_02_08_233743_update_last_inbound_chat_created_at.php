<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
		DB::statement("
		UPDATE contacts c
		INNER JOIN (
			SELECT contact_id, MAX(created_at) AS last_inbound
			FROM chats
			WHERE type = 'inbound' AND deleted_at IS NULL
			GROUP BY contact_id
		) sub ON c.id = sub.contact_id
		SET c.last_inbound_chat_created_at = sub.last_inbound
	");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
