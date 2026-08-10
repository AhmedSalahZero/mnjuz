<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;

/**
 * كتالوج أحداث نشاط المنظمة ونقطة كتابتها الوحيدة.
 *
 * الأحداث معرَّفة صراحةً لا ملتقطة تلقائياً من Eloquent: الملتقَط تلقائياً
 * يُخرج «chats.created id=3279289» وهو صحيح تقنياً بلا معنى للعميل، بينما
 * المعرَّف يُخرج «أحمد أرسل رسالة إلى نورة» وهو ما يُقرأ ويُصدَّر ويُبحث فيه.
 *
 * الكتابة لا تُفشل العملية الأصلية أبداً: تسجيلُ أن موظفاً حذف عميلاً أقلّ
 * أهميةً من نجاح الحذف نفسه، فأي خطأ هنا يُبتلع ويُكتب في سجلّ الأخطاء.
 */
class ActivityLogger
{
    // الحساب والجلسة
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const ORGANIZATION_SWITCHED = 'organization_switched';

    // جهات الاتصال
    public const CONTACT_CREATED = 'contact_created';
    public const CONTACT_UPDATED = 'contact_updated';
    public const CONTACT_DELETED = 'contact_deleted';
    public const CONTACT_BLOCKED = 'contact_blocked';
    public const CONTACT_UNBLOCKED = 'contact_unblocked';
    public const CONTACT_IMPORTED = 'contact_imported';

    // المحادثات
    public const MESSAGE_SENT = 'message_sent';
    public const TEMPLATE_SENT = 'template_sent';
    public const MEDIA_SENT = 'media_sent';
    public const CHAT_DELETED = 'chat_deleted';

    // التذاكر
    public const TICKET_ASSIGNED = 'ticket_assigned';
    public const TICKET_CLOSED = 'ticket_closed';
    public const TICKET_REOPENED = 'ticket_reopened';

    // الفريق
    public const TEAM_MEMBER_INVITED = 'team_member_invited';
    public const TEAM_MEMBER_REMOVED = 'team_member_removed';
    public const TEAM_MEMBER_ROLE_CHANGED = 'team_member_role_changed';

    // الحملات والقوالب
    public const CAMPAIGN_CREATED = 'campaign_created';
    public const CAMPAIGN_DELETED = 'campaign_deleted';
    public const TEMPLATE_CREATED = 'template_created';
    public const TEMPLATE_DELETED = 'template_deleted';

    // الإعدادات والمجموعات
    public const SETTINGS_UPDATED = 'settings_updated';
    public const CONTACT_GROUP_CREATED = 'contact_group_created';
    public const CONTACT_GROUP_DELETED = 'contact_group_deleted';
    public const AUTO_REPLY_UPDATED = 'auto_reply_updated';

    /** أيام الاحتفاظ قبل الحذف — تُعرض للمستخدم في الصفحة. */
    public const RETENTION_DAYS = 7;

    /**
     * وصفٌ عربي لكل حدث. %s موضع اسم الشيء (عميل، عضو، حملة…).
     * الجملة مبنية للمعلوم بلا اسم الفاعل — الاسم يُعرض في عموده الخاص.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        return [
            self::LOGIN => 'سجّل دخوله',
            self::LOGOUT => 'سجّل خروجه',
            self::ORGANIZATION_SWITCHED => 'انتقل إلى منظمة أخرى',

            self::CONTACT_CREATED => 'أضاف العميل «%s»',
            self::CONTACT_UPDATED => 'عدّل بيانات العميل «%s»',
            self::CONTACT_DELETED => 'حذف العميل «%s»',
            self::CONTACT_BLOCKED => 'حظر العميل «%s»',
            self::CONTACT_UNBLOCKED => 'رفع الحظر عن العميل «%s»',
            self::CONTACT_IMPORTED => 'استورد جهات اتصال',

            self::MESSAGE_SENT => 'أرسل رسالة إلى «%s»',
            self::TEMPLATE_SENT => 'أرسل قالباً إلى «%s»',
            self::MEDIA_SENT => 'أرسل ملفاً إلى «%s»',
            self::CHAT_DELETED => 'حذف محادثة «%s»',

            self::TICKET_ASSIGNED => 'أسند تذكرة «%s»',
            self::TICKET_CLOSED => 'أغلق تذكرة «%s»',
            self::TICKET_REOPENED => 'أعاد فتح تذكرة «%s»',

            self::TEAM_MEMBER_INVITED => 'دعا العضو «%s» للفريق',
            self::TEAM_MEMBER_REMOVED => 'أزال العضو «%s» من الفريق',
            self::TEAM_MEMBER_ROLE_CHANGED => 'غيّر دور العضو «%s»',

            self::CAMPAIGN_CREATED => 'أنشأ حملة «%s»',
            self::CAMPAIGN_DELETED => 'حذف حملة «%s»',
            self::TEMPLATE_CREATED => 'أنشأ قالب «%s»',
            self::TEMPLATE_DELETED => 'حذف قالب «%s»',

            self::SETTINGS_UPDATED => 'عدّل إعدادات المنظمة',
            self::CONTACT_GROUP_CREATED => 'أنشأ مجموعة «%s»',
            self::CONTACT_GROUP_DELETED => 'حذف مجموعة «%s»',
            self::AUTO_REPLY_UPDATED => 'عدّل الردود التلقائية',
        ];
    }

    /** تجميع الأحداث لمرشِّح الصفحة. */
    public static function groups(): array
    {
        return [
            'account' => [self::LOGIN, self::LOGOUT, self::ORGANIZATION_SWITCHED],
            'contacts' => [self::CONTACT_CREATED, self::CONTACT_UPDATED, self::CONTACT_DELETED,
                self::CONTACT_BLOCKED, self::CONTACT_UNBLOCKED, self::CONTACT_IMPORTED],
            'chats' => [self::MESSAGE_SENT, self::TEMPLATE_SENT, self::MEDIA_SENT, self::CHAT_DELETED],
            'tickets' => [self::TICKET_ASSIGNED, self::TICKET_CLOSED, self::TICKET_REOPENED],
            'team' => [self::TEAM_MEMBER_INVITED, self::TEAM_MEMBER_REMOVED, self::TEAM_MEMBER_ROLE_CHANGED],
            'campaigns' => [self::CAMPAIGN_CREATED, self::CAMPAIGN_DELETED, self::TEMPLATE_CREATED, self::TEMPLATE_DELETED],
            'settings' => [self::SETTINGS_UPDATED, self::CONTACT_GROUP_CREATED, self::CONTACT_GROUP_DELETED, self::AUTO_REPLY_UPDATED],
        ];
    }

    /** الجملة المقروءة لصفّ سجلّ. */
    public static function describe(string $event, ?string $subjectLabel = null): string
    {
        $template = self::catalog()[$event] ?? $event;

        if (!str_contains($template, '%s')) {
            return $template;
        }

        return sprintf($template, $subjectLabel !== null && $subjectLabel !== '' ? $subjectLabel : '—');
    }

    /**
     * تسجيل حدث. المنظمة والمستخدم يُستنتجان من الجلسة إن لم يُمرّرا، لأن
     * أغلب نقاط الاستدعاء داخل طلب مُصادَق عليه.
     */
    public static function log(
        string $event,
        ?string $subjectLabel = null,
        ?string $subjectType = null,
        $subjectId = null,
        array $properties = [],
        ?int $organizationId = null,
        ?int $userId = null,
        ?string $userName = null
    ): void {
        try {
            $organizationId = $organizationId ?? self::currentOrganizationId();
            if (!$organizationId) {
                return;
            }

            $user = auth()->user();
            $userId = $userId ?? ($user?->id);
            if ($userName === null && $user) {
                $userName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email;
            }

            $request = request();

            ActivityLog::create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'user_name' => $userName ? mb_substr($userName, 0, 191) : null,
                'event' => $event,
                'subject_type' => $subjectType,
                'subject_id' => is_numeric($subjectId) ? (int) $subjectId : null,
                'subject_label' => $subjectLabel !== null ? mb_substr($subjectLabel, 0, 191) : null,
                'properties' => $properties ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // التسجيل خدمةٌ للعملية لا شرطٌ لها.
            Log::warning('ActivityLogger failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * المنظمة الحالية: الويب من الجلسة، والتطبيق من عمود المستخدم.
     */
    private static function currentOrganizationId(): ?int
    {
        $sessionOrg = null;
        try {
            $sessionOrg = session()->get('current_organization');
        } catch (\Throwable $e) {
            $sessionOrg = null;
        }

        if ($sessionOrg) {
            return (int) $sessionOrg;
        }

        $user = auth()->user();

        return $user?->current_mobile_organization_id ? (int) $user->current_mobile_organization_id : null;
    }
}
