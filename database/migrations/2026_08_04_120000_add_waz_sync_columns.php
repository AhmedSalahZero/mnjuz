<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط سجلاتنا المالية وتذاكر الدعم بنظيرتها في منصة واز أعمال.
 *
 * رسوم التأسيس والاشتراك فاتورتان منفصلتان هناك (شرط التوثيق) بينما هما
 * صفّ واحد عندنا، فنحتاج معرّفين لا واحداً.
 *
 * وجود المعرّف = تمّت المزامنة؛ وهو ما يمنع التكرار عند إعادة تشغيل المهمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_invoices', 'waz_invoice_id')) {
                $table->unsignedBigInteger('waz_invoice_id')->nullable()->after('uuid');
            }
            if (!Schema::hasColumn('billing_invoices', 'waz_setup_invoice_id')) {
                $table->unsignedBigInteger('waz_setup_invoice_id')->nullable()->after('waz_invoice_id');
            }
        });

        Schema::table('billing_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_payments', 'waz_synced_at')) {
                $table->timestamp('waz_synced_at')->nullable()->after('uuid');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'waz_ticket_id')) {
                $table->unsignedBigInteger('waz_ticket_id')->nullable()->after('uuid');
            }
            if (!Schema::hasColumn('tickets', 'waz_ticket_key')) {
                // المفتاح يبني رابط عرض التذكرة للعميل.
                $table->string('waz_ticket_key', 64)->nullable()->after('waz_ticket_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(
                ['waz_invoice_id', 'waz_setup_invoice_id'],
                fn ($c) => Schema::hasColumn('billing_invoices', $c)
            )));
        });

        Schema::table('billing_payments', function (Blueprint $table) {
            if (Schema::hasColumn('billing_payments', 'waz_synced_at')) {
                $table->dropColumn('waz_synced_at');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(
                ['waz_ticket_id', 'waz_ticket_key'],
                fn ($c) => Schema::hasColumn('tickets', $c)
            )));
        });
    }
};
