<?php

namespace Database\Seeders;

use App\Support\AdminRoleAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModulesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $modules = [
            [
                'name' => 'customers',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'organizations',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'billing',
                'actions' => 'view',
            ],
            [
                'name' => 'support',
                'actions' => 'view, create, assign',
            ],
            [
                'name' => 'team',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'roles',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'subscription_plans',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'settings',
                'actions' => 'general, timezone, broadcast_driver, payment_gateways, smtp, email_templates, billing, tax_rates, coupons, frontend',
            ],
            [
                'name' => 'contacts',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'developer_tools',
                'actions' => 'view',
            ],
        ];

        foreach ($modules as $module) {
            // Check if the module already exists by name
            $existingModule = DB::table('modules')->where('name', $module['name'])->exists();

            if (!$existingModule) {
                // Insert only if the module name does not exist
                DB::table('modules')->insert($module);
            }
        }

        $this->seedDeveloperRolePermissions();
    }

    /**
     * Default permissions for the Developer admin role (idempotent).
     */
    private function seedDeveloperRolePermissions(): void
    {
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
}