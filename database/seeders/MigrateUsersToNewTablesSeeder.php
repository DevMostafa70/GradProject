<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\Company;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MigrateUsersToNewTablesSeeder extends Seeder
{
    public function run(): void
    {
        // 1. ترحيل المرشحين (candidate + user)
        $candidates = User::whereIn('role', ['candidate', 'user'])->get();

        foreach ($candidates as $user) {
            $exists = Candidate::where('email', $user->email)->exists();

            if (!$exists) {
                Candidate::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'bio' => $user->bio,
                    'avatar' => $user->avatar,
                    'phone' => $user->phone,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]);

                $this->command->info("✅ Migrated candidate: {$user->email}");
            }
        }

        // 2. ترحيل الشركات
        $companies = User::where('role', 'company')->get();

        foreach ($companies as $user) {
            $exists = Company::where('email', $user->email)->exists();

            if (!$exists) {
                // جلب بيانات الشركة من جدول companies القديم
                $oldCompany = \App\Models\Company::where('user_id', $user->id)->first();

                Company::create([
                    'company_name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'phone' => $oldCompany->phone ?? null,
                    'industry' => $oldCompany->industry ?? null,
                    'logo' => $oldCompany->logo ?? null,
                    'website' => $oldCompany->website ?? null,
                    'description' => $oldCompany->description ?? null,
                    'address' => $oldCompany->address ?? null,
                    'is_verified' => $oldCompany->is_verified ?? false,
                    'status' => 'approved',
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]);

                $this->command->info("✅ Migrated company: {$user->email}");
            }
        }

        // 3. ترحيل الأدمن (إذا وجدوا في users)
        $admins = User::where('role', 'admin')->get();

        foreach ($admins as $user) {
            $exists = Admin::where('email', $user->email)->exists();

            if (!$exists) {
                Admin::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'role' => 'admin',
                    'permissions' => ['*'],
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]);

                $this->command->info("✅ Migrated admin: {$user->email}");
            }
        }

        $this->command->info('🎉 Data migration completed successfully!');
    }
}
