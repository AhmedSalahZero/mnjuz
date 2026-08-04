<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط سجلات منجز شات بنظيرتها في منصة واز أعمال: المنشأة ↔ Company،
 * والمستخدم المالك ↔ Contact. نخزّنها لنعرف لاحقاً أي عميل يقابل أيّ شركة
 * هناك (الفواتير، التذاكر) وحتى لا نُعيد إنشاءه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'waz_company_id')) {
                $table->unsignedBigInteger('waz_company_id')->nullable()->after('identifier');
                $table->index('waz_company_id', 'idx_organizations_waz_company_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'waz_contact_id')) {
                $table->unsignedBigInteger('waz_contact_id')->nullable()->after('email');
                $table->index('waz_contact_id', 'idx_users_waz_contact_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'waz_company_id')) {
                $table->dropIndex('idx_organizations_waz_company_id');
                $table->dropColumn('waz_company_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'waz_contact_id')) {
                $table->dropIndex('idx_users_waz_contact_id');
                $table->dropColumn('waz_contact_id');
            }
        });
    }
};
