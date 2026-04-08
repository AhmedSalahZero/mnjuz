<?php

use App\Support\AdminRoleAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['name' => 'contacts', 'actions' => 'view, create, edit, delete'],
            ['name' => 'developer_tools', 'actions' => 'view'],
        ] as $module) {
            if (! DB::table('modules')->where('name', $module['name'])->exists()) {
                DB::table('modules')->insert($module);
            }
        }

        if (! DB::table('roles')->where('name', AdminRoleAccess::DEVELOPER_ROLE_NAME)->exists()) {
            DB::table('roles')->insert([
                'uuid' => (string) Str::uuid(),
                'name' => AdminRoleAccess::DEVELOPER_ROLE_NAME,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $role = DB::table('roles')->where('name', AdminRoleAccess::DEVELOPER_ROLE_NAME)->first();
        if (! $role) {
            return;
        }

        foreach (['developer_tools', 'contacts'] as $moduleName) {
            $mod = DB::table('modules')->where('name', $moduleName)->first();
            if (! $mod) {
                continue;
            }
            $actions = array_map('trim', explode(',', (string) $mod->actions));
            foreach ($actions as $action) {
                if ($action === '') {
                    continue;
                }
                $exists = DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('module', $moduleName)
                    ->where('action', $action)
                    ->exists();
                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $role->id,
                        'module' => $moduleName,
                        'action' => $action,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('name', AdminRoleAccess::DEVELOPER_ROLE_NAME)->first();
        if ($role) {
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
        }
    }
};
