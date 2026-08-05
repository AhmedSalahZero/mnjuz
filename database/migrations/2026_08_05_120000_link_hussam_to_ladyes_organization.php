<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * منح hussam.ibrahim012@gmail.com صلاحية الدخول على منظمة Ladyes بدور مدير.
 *
 * الدخول يعتمد على وجود صفّ في teams: AuthService::authenticateSession يقرأ
 * أول صفّ للمستخدم ويضع منظمته في الجلسة، فبدون هذا الصفّ لا منظمة له.
 *
 * تعتمد الـmigration على الجداول مباشرة لا على الـModels: النماذج قد تتغيّر
 * لاحقاً بينما يجب أن تبقى الهجرة قابلة للتشغيل كما كُتبت.
 */
return new class extends Migration
{
    private const EMAIL = 'hussam.ibrahim012@gmail.com';

    private const ORGANIZATION = 'Ladyes';

    private const ROLE = 'manager';

    public function up(): void
    {
        $user = DB::table('users')->where('email', self::EMAIL)->first(['id']);

        if (!$user) {
            // لا نُفشل الهجرة: إيقاف النشر كله لأجل حساب واحد غير موجود أسوأ
            // من تخطّيه مع تسجيل السبب.
            Log::warning('Migration link-hussam-to-ladyes: user not found, skipped.', [
                'email' => self::EMAIL,
            ]);

            return;
        }

        // المطابقة بالاسم لا بالمعرّف: identifier يُولَّد لكل بيئة على حدة
        // فيختلف بين المحلي والإنتاج.
        $organizations = DB::table('organizations')
            ->where('name', self::ORGANIZATION)
            ->whereNull('deleted_at')
            ->get(['id', 'created_by']);

        if ($organizations->count() !== 1) {
            // صفر أو أكثر من واحدة: لا نُخمّن أيّها المقصودة.
            Log::warning('Migration link-hussam-to-ladyes: organization not uniquely resolved, skipped.', [
                'organization' => self::ORGANIZATION,
                'matches' => $organizations->count(),
            ]);

            return;
        }

        $organization = $organizations->first();

        $existing = DB::table('teams')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first(['id', 'role', 'status', 'deleted_at']);

        if ($existing) {
            // عضوية سابقة (ربما محذوفة أو موقوفة) — نُفعّلها بدل إنشاء صفّ ثانٍ.
            DB::table('teams')->where('id', $existing->id)->update([
                'role' => self::ROLE,
                'status' => 'active',
                'deleted_at' => null,
                'deleted_by' => null,
                'updated_at' => now(),
            ]);

            Log::info('Migration link-hussam-to-ladyes: existing membership reactivated.', [
                'team_id' => $existing->id,
            ]);

            return;
        }

        DB::table('teams')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => self::ROLE,
            'status' => 'active',
            'created_by' => $organization->created_by ?: $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // نضبط المنظمة الحالية فقط إذا لم تكن مضبوطة، حتى لا نُغيّر منظمةً
        // يعمل عليها المستخدم بالفعل.
        DB::table('users')
            ->where('id', $user->id)
            ->whereNull('current_web_organization_id')
            ->update(['current_web_organization_id' => $organization->id]);

        DB::table('users')
            ->where('id', $user->id)
            ->whereNull('current_mobile_organization_id')
            ->update(['current_mobile_organization_id' => $organization->id]);

        Log::info('Migration link-hussam-to-ladyes: membership created.', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => self::ROLE,
        ]);
    }

    public function down(): void
    {
        $user = DB::table('users')->where('email', self::EMAIL)->first(['id']);
        $organization = DB::table('organizations')
            ->where('name', self::ORGANIZATION)
            ->whereNull('deleted_at')
            ->first(['id']);

        if (!$user || !$organization) {
            return;
        }

        // نحذف العضوية التي أنشأناها فقط — أي بالدور نفسه. لو رُقّي المستخدم
        // أو خُفّض بعد الهجرة فتلك حالة لم نُنشئها ولا يصحّ التراجع عنها.
        DB::table('teams')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('role', self::ROLE)
            ->delete();

        DB::table('users')
            ->where('id', $user->id)
            ->where('current_web_organization_id', $organization->id)
            ->update(['current_web_organization_id' => null]);

        DB::table('users')
            ->where('id', $user->id)
            ->where('current_mobile_organization_id', $organization->id)
            ->update(['current_mobile_organization_id' => null]);
    }
};
