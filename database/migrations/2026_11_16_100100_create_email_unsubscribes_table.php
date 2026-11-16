<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * من ألغى اشتراكه في بريد حملات منشأة.
 *
 * جدول مستقلّ لا contacts.marketing_opted_out_at: ذاك يُخرج الشخص من حملات
 * واتساب وتيليجرام أيضاً، ومن ترك البريد لم يطلب ذلك. والعنوان هنا مفتاح
 * الشخص (صغيراً بلا مسافات)، فيشمل كل صفوفه في المنشأة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_unsubscribes')) {
            return;
        }

        Schema::create('email_unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('email', 191);
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'email'], 'email_unsubscribes_org_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_unsubscribes');
    }
};
