<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * لكل contact، يضمن هذا Migration أن صف واحد فقط يحمل is_latest = true
     * (الأحدث بـ MAX(id)) وباقي الصفوف تُعاد إلى is_latest = false.
     *
     * السبب: ensureChatTicketsExist كانت تُنشئ ticket جديد عند كل تحميل للصفحة
     * لأن الـ cache كان معطلاً، مما أنتج عدة صفوف بـ is_latest = true لنفس الـ contact.
     * هذا يُسبّب تكرار جهات الاتصال في الـ LEFT JOIN.
     */
    public function up(): void
    {
        // 1. أعد is_latest = false لكل الصفوف الزائدة (تُبقي الأحدث فقط لكل contact)
        DB::statement("
            UPDATE chat_tickets ct
            INNER JOIN (
                SELECT contact_id, MAX(id) AS keep_id
                FROM chat_tickets
                WHERE is_latest = 1
                GROUP BY contact_id
            ) latest ON ct.contact_id = latest.contact_id
            SET ct.is_latest = 0
            WHERE ct.is_latest = 1
              AND ct.id <> latest.keep_id
        ");

        // 2. تأكّد أن كل contact لديه ticket واحد على الأقل بـ is_latest = true
        //    (الحالات التي ليس لها أي ticket بـ is_latest = true تُصحَّح)
        DB::statement("
            UPDATE chat_tickets ct
            INNER JOIN (
                SELECT contact_id, MAX(id) AS keep_id
                FROM chat_tickets
                GROUP BY contact_id
                HAVING SUM(is_latest) = 0
            ) no_latest ON ct.contact_id = no_latest.contact_id
            SET ct.is_latest = 1
            WHERE ct.id = no_latest.keep_id
        ");
    }

    public function down(): void
    {
        // لا يمكن التراجع بأمان — البيانات الأصلية غير معروفة
    }
};
