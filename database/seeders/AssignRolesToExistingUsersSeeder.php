<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Admin;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AssignRolesToExistingUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🔄 Assigning roles to existing users...');

        // ✅ الحصول على الأدوار بالـ guard_name الصحيح
        $superAdminRole = Role::where('name', 'super_admin')->where('guard_name', 'admin')->first();
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'admin')->first();
        $companyOwnerRole = Role::where('name', 'company_owner')->where('guard_name', 'company')->first();
        $regularUserRole = Role::where('name', 'regular_user')->where('guard_name', 'user')->first();

        // ============================================================
        // 1. Super Admin
        // ============================================================
        $superAdmin = Admin::where('email', 'admin@interviewai.com')->first();
        if ($superAdmin && $superAdminRole) {
            $superAdmin->assignRole($superAdminRole);
            $this->command->info('✅ Super Admin assigned role: super_admin (guard: admin)');
        } else {
            $this->command->warn('⚠️ Super Admin or role not found');
        }

        // ============================================================
        // 2. All other Admins
        // ============================================================
        if ($adminRole) {
            $admins = Admin::where('email', '!=', 'admin@interviewai.com')->get();
            foreach ($admins as $admin) {
                $admin->assignRole($adminRole);
            }
            $this->command->info('✅ All admins assigned role: admin (guard: admin)');
        }

        // ============================================================
        // 3. All Companies
        // ============================================================
        if ($companyOwnerRole) {
            $companies = Company::all();
            foreach ($companies as $company) {
                $company->assignRole($companyOwnerRole);
            }
            $this->command->info('✅ All companies assigned role: company_owner (guard: company)');
        }

        // ============================================================
        // 4. All Regular Users
        // ============================================================
        if ($regularUserRole) {
            $users = User::all();
            foreach ($users as $user) {
                $user->assignRole($regularUserRole);
            }
            $this->command->info('✅ All regular users assigned role: regular_user (guard: user)');
        }

        $this->command->info('🎉 All roles assigned successfully!');
    }
}
