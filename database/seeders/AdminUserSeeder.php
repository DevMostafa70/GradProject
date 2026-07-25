<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminExists = Admin::where('email', 'admin@interviewai.com')->exists();

        if (!$adminExists) {
            // إنشاء الأدمن مباشرة في جدول admins (بدون user_id)
            Admin::create([
                'name' => 'Super Admin',
                'email' => 'admin@interviewai.com',
                'password' => Hash::make('Admin@123456'),
                'role' => 'super_admin',
                'legacy_permissions' => null,
                'last_login_at' => null,
            ]);

            $this->command->info('✅ Admin user created successfully!');
            $this->command->info('📧 Email: admin@interviewai.com');
            $this->command->info('🔑 Password: Admin@123456');
        } else {
            $this->command->warn('⚠️ Admin user already exists!');
        }
    }
}
