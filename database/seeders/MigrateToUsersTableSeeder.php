<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class MigrateToUsersTableSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Starting migration of existing data to users table...');

        // ==================== 1. ترحيل المرشحين (Candidates) ====================
        $candidates = Candidate::all();
        $candidatesCount = 0;

        foreach ($candidates as $candidate) {
            // التحقق من عدم وجود المستخدم مسبقاً
            $exists = User::where('email', $candidate->email)->exists();

            if (!$exists) {
                $user = User::create([
                    'name' => $candidate->name,
                    'email' => $candidate->email,
                    'password' => $candidate->password,
                    'role' => 'candidate',
                    'is_active' => true,
                    'bio' => $candidate->bio,
                    'avatar' => $candidate->avatar,
                    'email_verified_at' => $candidate->email_verified_at,
                    'created_at' => $candidate->created_at,
                    'updated_at' => $candidate->updated_at,
                ]);

                // تحديث candidate.user_id ليربط بالجدول الجديد
                $candidate->update(['user_id' => $user->id]);

                $candidatesCount++;
                $this->command->info("Migrated candidate: {$candidate->email}");
            } else {
                $this->command->warn("Candidate already exists: {$candidate->email}");
            }
        }

        // ==================== 2. ترحيل الشركات (Companies) ====================
        $companies = Company::all();
        $companiesCount = 0;

        foreach ($companies as $company) {
            // التحقق من عدم وجود المستخدم مسبقاً
            $exists = User::where('email', $company->email)->exists();

            if (!$exists) {
                $user = User::create([
                    'name' => $company->company_name,
                    'email' => $company->email,
                    'password' => $company->password,
                    'role' => 'company',
                    'is_active' => $company->status === 'approved',
                    'bio' => $company->description,
                    'created_at' => $company->created_at,
                    'updated_at' => $company->updated_at,
                ]);

                // تحديث company.user_id ليربط بالجدول الجديد
                $company->update(['user_id' => $user->id]);

                $companiesCount++;
                $this->command->info("Migrated company: {$company->email}");
            } else {
                $this->command->warn("Company already exists: {$company->email}");
            }
        }

        // ==================== 3. ترحيل الأدمن (Admins) ====================
        $admins = Admin::all();
        $adminsCount = 0;

        foreach ($admins as $admin) {
            // الحصول على البريد من جدول users المرتبط (إذا وجد)
            $user = $admin->user;

            if ($user) {
                $exists = User::where('email', $user->email)->exists();

                if (!$exists) {
                    $newUser = User::create([
                        'name' => $user->name,
                        'email' => $user->email,
                        'password' => $user->password,
                        'role' => 'admin',
                        'is_active' => true,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ]);

                    // تحديث admin.user_id
                    $admin->update(['user_id' => $newUser->id]);

                    $adminsCount++;
                    $this->command->info("Migrated admin: {$user->email}");
                }
            }
        }

        // ==================== 4. عرض الملخص ====================
        $this->command->info("=========================================");
        $this->command->info("Migration completed successfully!");
        $this->command->info("Candidates migrated: {$candidatesCount}");
        $this->command->info("Companies migrated: {$companiesCount}");
        $this->command->info("Admins migrated: {$adminsCount}");
        $this->command->info("Total users in users table: " . User::count());
        $this->command->info("=========================================");
    }
}
