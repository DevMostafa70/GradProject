<?php

use App\Http\Controllers\API\Admin\AdminActivityLogController;
use App\Http\Controllers\API\UserAuthController;
use App\Http\Controllers\API\Auth\PasswordResetController;
use App\Http\Controllers\API\Candidate\CandidateAuthController;
use App\Http\Controllers\API\CompanyAuthController;
use App\Http\Controllers\API\Admin\AdminAuthController;
use App\Http\Controllers\API\Admin\AdminBroadcastController;
use App\Http\Controllers\API\Admin\AdminCategoryController;
use App\Http\Controllers\API\Admin\AdminCompanyController;
use App\Http\Controllers\API\Admin\AdminController;
use App\Http\Controllers\API\Admin\AdminSkillController;
use App\Http\Controllers\API\Admin\AdminUserController;
use App\Http\Controllers\API\Admin\PermissionTemplateController;
use App\Http\Controllers\API\AnswerController;
use App\Http\Controllers\API\AntiCheatController;
use App\Http\Controllers\Api\Company\Billing\BillingStatusController;
use App\Http\Controllers\Api\Company\Billing\PlanController;
use App\Http\Controllers\Api\Company\Billing\SelectPlanController;
use App\Http\Controllers\API\CompanyJobController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\InterviewController;
use App\Http\Controllers\API\InterviewAnalysisController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\PublicInterviewController;
use App\Http\Controllers\API\ResultsController;
use App\Http\Controllers\API\ResumeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Company\Billing\CheckoutController;
use App\Http\Controllers\API\Company\Billing\BillingPortalController;
use App\Http\Controllers\API\Company\CompanyDashboardController;
use App\Http\Controllers\API\Company\CompanyEmployeeController;
use App\Http\Controllers\API\Company\CompanyPermissionTemplateController;
use App\Http\Controllers\API\Company\CompanyCandidateReportController;
use App\Http\Controllers\API\Company\CompanyIdentityReviewController;
use App\Http\Controllers\API\PublicCompanyInterviewController;
use App\Http\Controllers\API\Webhook\StripeWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==================== Public Routes (No Auth Required) ====================

// ===== User (Regular User) Auth =====
Route::prefix('user')->group(function () {
    Route::post('/register', [UserAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [UserAuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotUser'])
        ->middleware('throttle:password-reset-request');
});

// One reset endpoint for every supported account type. Forgot-password is
// intentionally exposed only under /user for regular users.
Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password-reset');

// ===== Candidate Auth (Public - للتسجيل فقط) =====
Route::prefix('candidate')->group(function () {
    Route::post('/register', [CandidateAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [CandidateAuthController::class, 'login'])->middleware('throttle:auth');
});

// ===== Company Auth =====
Route::prefix('company')->group(function () {
    Route::post('/register', [CompanyAuthController::class, 'register'])->middleware('throttle:3,1');
    Route::post('/login', [CompanyAuthController::class, 'login'])->middleware('throttle:auth');

    // ✅ Billing Plans (Public - متاحة للجميع)
    Route::get('/plans', [PlanController::class, 'index']);
});

// ===== Admin Auth =====
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:auth');
});

// ===== Public interview routes (candidates using job link - no login required) =====
Route::prefix('interview/join')->group(function () {
    Route::get('/{token}', [PublicInterviewController::class, 'showJob'])->where('token', '.*')->middleware('throttle:60,1');
    Route::post('/{token}/start', [PublicInterviewController::class, 'start'])->where('token', '.*')->middleware('throttle:public-interview');
    Route::post('/{token}/answer', [PublicInterviewController::class, 'submitAnswer'])->where('token', '.*')->middleware('throttle:public-interview');
    Route::post('/{token}/complete', [PublicInterviewController::class, 'complete'])->where('token', '.*')->middleware('throttle:public-interview');
});

// ===== Secure company-candidate interview portal =====
Route::prefix('public/company-interviews')
    ->middleware('throttle:180,1')
    ->group(function () {
        Route::get('/invitations/{token}', [PublicCompanyInterviewController::class, 'showInvitation']);
        Route::post('/invitations/{token}/claim', [PublicCompanyInterviewController::class, 'claim'])
            ->middleware('throttle:15,1');

        Route::post('/session/resume', [PublicCompanyInterviewController::class, 'resume'])
            ->middleware('throttle:20,1');
        Route::post('/session/heartbeat', [PublicCompanyInterviewController::class, 'heartbeat']);
        Route::get('/session/state', [PublicCompanyInterviewController::class, 'state']);

        Route::post('/identity/document', [PublicCompanyInterviewController::class, 'uploadDocument'])
            ->middleware('throttle:10,1');
        Route::get('/identity/status', [PublicCompanyInterviewController::class, 'identityStatus']);

        Route::post('/start', [PublicCompanyInterviewController::class, 'start'])
            ->middleware('throttle:10,1');
        Route::post('/snapshots', [PublicCompanyInterviewController::class, 'uploadSnapshot'])
            ->middleware('throttle:20,1');
        Route::post('/violations', [PublicCompanyInterviewController::class, 'violation'])
            ->middleware('throttle:120,1');
        Route::post('/answers', [PublicCompanyInterviewController::class, 'submitAnswer'])
            ->middleware('throttle:30,1');
        Route::post('/complete', [PublicCompanyInterviewController::class, 'complete'])
            ->middleware('throttle:10,1');
        Route::get('/processing-status', [PublicCompanyInterviewController::class, 'processingStatus']);
    });

// ===== Stripe Webhook (No Auth - Public) =====
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->name('stripe.webhook');

// ==================== Protected Routes (Auth Required) ====================

// ===== User Routes (Regular Users) =====
Route::prefix('user')->middleware(['auth:sanctum', 'checkrole:regular_user', 'throttle:user'])->group(function () {
    Route::post('/logout', [UserAuthController::class, 'logout']);
    Route::get('/profile', [UserAuthController::class, 'profile']);
    Route::put('/profile', [UserAuthController::class, 'updateProfile']);
    Route::put('/password', [UserAuthController::class, 'updatePassword']);
    Route::post('/avatar', [UserAuthController::class, 'uploadAvatar'])->middleware('throttle:upload');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/progress', [DashboardController::class, 'progress']);
    Route::get('/dashboard/weaknesses', [DashboardController::class, 'weaknesses']);
    Route::get('/dashboard/daily-questions', [DashboardController::class, 'dailyQuestions']);

    // Results
    Route::get('/results', [ResultsController::class, 'index']);
    Route::get('/results/summary', [ResultsController::class, 'summary']);
    Route::get('/results/{interview}', [ResultsController::class, 'show']);

    // Resume
    Route::post('/resume/upload', [ResumeController::class, 'upload'])->middleware('throttle:upload');
    Route::get('/resume', [ResumeController::class, 'index']);
    Route::get('/resume/latest', [ResumeController::class, 'latest']);
    Route::get('/resume/{resume}', [ResumeController::class, 'show']);
    Route::get('/resume/{resume}/improvements', [ResumeController::class, 'improvements']);
    Route::delete('/resume/{resume}', [ResumeController::class, 'destroy']);

    // ===== Notifications =====
    Route::get('/notifications', [UserAuthController::class, 'notifications']);
    Route::put('/notifications/{id}/read', [UserAuthController::class, 'markNotificationAsRead']);
    Route::delete('/notifications', [UserAuthController::class, 'deleteAllNotifications']);
    Route::delete('/notifications/{id}', [UserAuthController::class, 'deleteNotification']);
});

// ===== Candidate Routes (Legacy - معلقة مؤقتاً) =====
// Route::prefix('candidate')->middleware('auth:candidate')->group(function () {
//     ... باقي Routes معلقة ...
// });

// ============================================================
// 🔹 Company Routes
// ============================================================
Route::prefix('company')
    ->middleware([
        'auth:sanctum',
        'checkrole:company_owner,company_hr,company_interviewer,company_recruiter,company_question_bank_manager,company_viewer,company_employee'
    ])
    ->group(function () {

        // ============================================================
        // ✅ Company Dashboard
        // ============================================================
        Route::get('/dashboard', [CompanyDashboardController::class, 'index'])
            ->middleware('checkpermission:company.dashboard.view');

        // ============================================================
        // ✅ Company Employee Management (فقط company_owner)
        // ============================================================
        Route::prefix('employees')
            ->middleware('checkrole:company_owner')
            ->group(function () {
                Route::get('/', [CompanyEmployeeController::class, 'index'])
                    ->middleware('checkpermission:company.employees.view');

                Route::post('/', [CompanyEmployeeController::class, 'store'])
                    ->middleware('checkpermission:company.employees.create');

                Route::get('/{employee}', [CompanyEmployeeController::class, 'show'])
                    ->middleware('checkpermission:company.employees.view');

                Route::put('/{employee}', [CompanyEmployeeController::class, 'update'])
                    ->middleware('checkpermission:company.employees.update');

                Route::delete('/{employee}', [CompanyEmployeeController::class, 'destroy'])
                    ->middleware('checkpermission:company.employees.delete');

                Route::post('/{employee}/toggle', [CompanyEmployeeController::class, 'toggleStatus'])
                    ->middleware('checkpermission:company.employees.update');
            });

        // ============================================================
        // ✅ Company Permission Templates (فقط company_owner)
        // ============================================================
        Route::prefix('permission-templates')
            ->middleware(['checkrole:company_owner', 'company.paid'])
            ->group(function () {
                Route::get('/', [CompanyPermissionTemplateController::class, 'index'])
                    ->middleware('checkpermission:company.employees.view');

                Route::post('/', [CompanyPermissionTemplateController::class, 'store'])
                    ->middleware('checkpermission:company.employees.create');

                Route::get('/available-permissions', [CompanyPermissionTemplateController::class, 'availablePermissions'])
                    ->middleware('checkpermission:company.employees.view');

                Route::get('/permissions-by-module', [CompanyPermissionTemplateController::class, 'permissionsByModule'])
                    ->middleware('checkpermission:company.employees.view');

                Route::get('/{template}', [CompanyPermissionTemplateController::class, 'show'])
                    ->middleware('checkpermission:company.employees.view');

                Route::put('/{template}', [CompanyPermissionTemplateController::class, 'update'])
                    ->middleware('checkpermission:company.employees.update');

                Route::delete('/{template}', [CompanyPermissionTemplateController::class, 'destroy'])
                    ->middleware('checkpermission:company.employees.delete');

                Route::post('/{template}/toggle', [CompanyPermissionTemplateController::class, 'toggle'])
                    ->middleware('checkpermission:company.employees.update');
            });

        // ============================================================
        // ✅ Employee Limits (فقط company_owner)
        // ============================================================
        Route::get('/employee-limits', [CompanyEmployeeController::class, 'limits'])
            ->middleware(['checkrole:company_owner', 'checkpermission:company.employees.view']);

        // ============================================================
        // ✅ Routes المشتركة (لا تحتاج اشتراك فعال)
        // ============================================================

        Route::post('/logout', [CompanyAuthController::class, 'logout']);
        Route::get('/me', [CompanyAuthController::class, 'me']);

        // Company profile management is owner-only.
        Route::get('/profile', [CompanyAuthController::class, 'profile'])
            ->middleware(['checkrole:company_owner', 'checkpermission:company.profile.view']);

        Route::post('/profile', [CompanyAuthController::class, 'updateProfile'])
            ->middleware(['checkrole:company_owner', 'checkpermission:company.profile.update']);

        // ============================================================
        // ✅ Notifications
        // ============================================================
        Route::get('/notifications', [CompanyAuthController::class, 'notifications'])
            ->middleware('checkpermission:company.notifications.view');

        Route::put('/notifications/{id}/read', [CompanyAuthController::class, 'markNotificationAsRead'])
            ->middleware('checkpermission:company.notifications.view');

        Route::delete('/notifications', [CompanyAuthController::class, 'deleteAllNotifications'])
            ->middleware('checkpermission:company.notifications.view');

        Route::delete('/notifications/{id}', [CompanyAuthController::class, 'deleteNotification'])
            ->middleware('checkpermission:company.notifications.view');

        // ============================================================
        // ✅ Billing Routes
        // ============================================================
        Route::prefix('billing')->group(function () {
            Route::get('/status', [BillingStatusController::class, 'show'])
                ->middleware(['company.authenticated', 'checkpermission:company.billing.view']);

            Route::post('/select-plan', [SelectPlanController::class, 'store'])
                ->middleware(['company.authenticated', 'company.approved', 'checkpermission:company.billing.select_plan']);

            Route::post('/checkout', [CheckoutController::class, 'store'])
                ->middleware(['company.authenticated', 'company.approved', 'checkpermission:company.billing.checkout']);

            Route::post('/portal', [BillingPortalController::class, 'store'])
                ->middleware(['company.authenticated', 'checkpermission:company.billing.portal']);
        });

        // ============================================================
        // ✅ Routes تحتاج اشتراك فعال (company.paid)
        // ============================================================
        Route::middleware(['company.paid'])->group(function () {

            // Job Management
            Route::get('/jobs', [CompanyJobController::class, 'index'])
                ->middleware('checkpermission:company.jobs.view,company.candidates.view,company.candidates.invite,company.interviews.view,company.question_bank.view');
            Route::get('/jobs/{job}', [CompanyJobController::class, 'show'])
                ->middleware('checkpermission:company.jobs.view');
            Route::post('/jobs', [CompanyJobController::class, 'store'])
                ->middleware('checkpermission:company.jobs.create');
            Route::delete('/jobs/{job}', [CompanyJobController::class, 'destroy'])
                ->middleware('checkpermission:company.jobs.delete');

            Route::post('/jobs/{job}/close', [CompanyJobController::class, 'close'])
                ->middleware('checkpermission:company.jobs.close');

            Route::get('/jobs/{job}/stats', [CompanyJobController::class, 'stats'])
                ->middleware('checkpermission:company.candidates.view,company.interviews.view,company.results.view');

            Route::get('/jobs/{job}/candidates', [CompanyJobController::class, 'candidates'])
                ->middleware('checkpermission:company.candidates.view');

            Route::get('/jobs/{job}/candidates/{candidate}', [CompanyJobController::class, 'candidateDetails'])
                ->middleware('checkpermission:company.candidates.view');

            Route::put('/jobs/{job}/candidates/{candidate}/status', [CompanyJobController::class, 'updateCandidateStatus'])
                ->middleware('checkpermission:company.candidates.update');

            // Bulk invitations
            Route::post('/jobs/{job}/invite-bulk', [CompanyJobController::class, 'inviteBulk'])
                ->middleware('checkpermission:company.candidates.invite');

            Route::get('/jobs/{job}/invitation-stats', [CompanyJobController::class, 'invitationStats'])
                ->middleware('checkpermission:company.candidates.view,company.candidates.invite');

            Route::get('/jobs/{job}/invitations', [CompanyJobController::class, 'invitations'])
                ->middleware('checkpermission:company.candidates.view,company.candidates.invite');

            // Interview settings remain part of the existing job flow.
            Route::get('/jobs/{job}/interview-settings', [CompanyJobController::class, 'interviewSettings'])
                ->middleware('checkpermission:company.jobs.view,company.interviews.view');
            Route::put('/jobs/{job}/interview-settings', [CompanyJobController::class, 'updateInterviewSettings'])
                ->middleware('checkpermission:company.jobs.update');

            // Two-step Excel import through the existing Bulk Invitations page.
            Route::post('/jobs/{job}/candidate-import/preview', [CompanyJobController::class, 'previewCandidateImport'])
                ->middleware('checkpermission:company.candidates.invite');
            Route::post('/jobs/{job}/candidate-import/confirm', [CompanyJobController::class, 'confirmCandidateImport'])
                ->middleware('checkpermission:company.candidates.invite');

            Route::post('/jobs/{job}/invitations/{invitation}/resend', [CompanyJobController::class, 'resendInvitation'])
                ->middleware('checkpermission:company.candidates.invite');
            Route::post('/jobs/{job}/invitations/{invitation}/cancel', [CompanyJobController::class, 'cancelInvitation'])
                ->middleware('checkpermission:company.candidates.update');
            Route::post('/jobs/{job}/invitations/{invitation}/extend-resumes', [CompanyJobController::class, 'extendResumeLimit'])
                ->middleware('checkpermission:company.candidates.update');

            Route::get('/jobs/{job}/candidates/{candidate}/report', [CompanyCandidateReportController::class, 'show'])
                ->middleware('checkpermission:company.results.view');
            Route::get('/jobs/{job}/candidates/{candidate}/identity', [CompanyIdentityReviewController::class, 'show'])
                ->middleware('checkpermission:company.candidates.view');
            Route::get('/jobs/{job}/candidates/{candidate}/identity/evidences/{evidence}', [CompanyIdentityReviewController::class, 'evidence'])
                ->middleware('checkpermission:company.candidates.view');
            Route::post('/jobs/{job}/candidates/{candidate}/identity/review', [CompanyIdentityReviewController::class, 'review'])
                ->middleware('checkpermission:company.candidates.update');

            // Question Bank
            Route::post('/jobs/{job}/upload-questions', [CompanyJobController::class, 'uploadQuestions'])
                ->middleware('checkpermission:company.question_bank.create');

            Route::get('/jobs/{job}/question-stats', [CompanyJobController::class, 'questionStats'])
                ->middleware('checkpermission:company.question_bank.view');

            Route::get('/jobs/{job}/question-bank', [CompanyJobController::class, 'getQuestionBank'])
                ->middleware('checkpermission:company.question_bank.view');
        });
    });

// ============================================================
// 🔹 Admin Routes
// ============================================================
Route::prefix('admin')
    ->middleware(['auth:sanctum', 'checkrole:admin,super_admin'])
    ->group(function () {

        Route::get('/company-candidate-reports/{candidate}', [CompanyCandidateReportController::class, 'adminShow'])
            ->middleware('checkpermission:admin.interviews.view');


        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/profile', [AdminAuthController::class, 'profile']);

        // ===== Dashboard =====
        Route::get('/dashboard', [AdminController::class, 'dashboard'])
            ->middleware('checkpermission:admin.dashboard.view');

        // ============================================================
        // 🔹 Permission Templates - فقط super_admin
        // ============================================================
        Route::prefix('permission-templates')
            ->middleware('checkrole:super_admin')
            ->group(function () {

                // ✅ 1. Routes الثابتة (بدون {id}) تأتي أولاً
                Route::get('/available-permissions', [PermissionTemplateController::class, 'availablePermissions'])
                    ->middleware('checkpermission:admin.roles.view');

                // ✅ 2. Routes المتغيرة (مع {id}) تأتي بعدها
                Route::get('/', [PermissionTemplateController::class, 'index'])
                    ->middleware('checkpermission:admin.roles.view');
                Route::post('/', [PermissionTemplateController::class, 'store'])
                    ->middleware('checkpermission:admin.roles.create');
                Route::get('/{template}', [PermissionTemplateController::class, 'show'])
                    ->middleware('checkpermission:admin.roles.view');
                Route::put('/{template}', [PermissionTemplateController::class, 'update'])
                    ->middleware('checkpermission:admin.roles.update');
                Route::delete('/{template}', [PermissionTemplateController::class, 'destroy'])
                    ->middleware('checkpermission:admin.roles.delete');
                Route::post('/{template}/toggle', [PermissionTemplateController::class, 'toggle'])
                    ->middleware('checkpermission:admin.roles.update');
            });

        // ============================================================
        // ✅ Users Management
        // ============================================================
        Route::prefix('users')->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])
                ->middleware('checkpermission:admin.users.view');

            Route::get('/{user}', [AdminUserController::class, 'show'])
                ->middleware('checkpermission:admin.users.view');

            Route::post('/', [AdminUserController::class, 'store'])
                ->middleware('checkpermission:admin.users.create');

            Route::put('/{user}', [AdminUserController::class, 'update'])
                ->middleware('checkpermission:admin.users.update');

            Route::post('/{user}/suspend', [AdminUserController::class, 'suspendUser'])
                ->middleware('checkpermission:admin.users.suspend');

            Route::post('/{user}/activate', [AdminUserController::class, 'activateUser'])
                ->middleware('checkpermission:admin.users.update');

            Route::delete('/{user}', [AdminUserController::class, 'deleteUser'])
                ->middleware('checkpermission:admin.users.delete');
        });

        // ============================================================
        // ✅ Company Employees (موظفي الشركات)
        // ============================================================
        Route::get('/company-employees', [AdminUserController::class, 'companyEmployees'])
            ->middleware('checkpermission:admin.users.view');

        Route::get('/company-employees/{employee}', [AdminUserController::class, 'showCompanyEmployee'])
            ->middleware('checkpermission:admin.users.view');


        // ============================================================
        // ✅ Companies Management
        // ============================================================
        Route::prefix('companies')->group(function () {
            Route::get('/pending', [AdminCompanyController::class, 'pendingRequests'])
                ->middleware('checkpermission:admin.companies.view');

            Route::post('/{company}/approve', [AdminCompanyController::class, 'approve'])
                ->middleware('checkpermission:admin.companies.approve');

            Route::post('/{company}/reject', [AdminCompanyController::class, 'reject'])
                ->middleware('checkpermission:admin.companies.reject');

            Route::post('/{company}/suspend', [AdminCompanyController::class, 'suspend'])
                ->middleware('checkpermission:admin.companies.suspend');

            Route::post('/{company}/activate', [AdminCompanyController::class, 'activate'])
                ->middleware('checkpermission:admin.companies.update');

            Route::delete('/{company}', [AdminCompanyController::class, 'destroy'])
                ->middleware('checkpermission:admin.companies.delete');

            Route::get('/', [AdminCompanyController::class, 'index'])
                ->middleware('checkpermission:admin.companies.view');
        });

        // ============================================================
        // ✅ Candidates Management
        // ============================================================
        Route::prefix('candidates')->group(function () {
            Route::get('/', [AdminUserController::class, 'candidatesList'])
                ->middleware('checkpermission:admin.users.view');

            Route::get('/{candidate}', [AdminUserController::class, 'showCandidate'])
                ->middleware('checkpermission:admin.users.view');

            Route::delete('/{candidate}', [AdminUserController::class, 'deleteCandidate'])
                ->middleware('checkpermission:admin.users.delete');
        });

        Route::get('/companies/{company}/candidates', [AdminUserController::class, 'companyCandidates'])
            ->middleware('checkpermission:admin.users.view');

        // ============================================================
        // ✅ Skills Management
        // ============================================================
        // Route::get('/skills', [AdminSkillController::class, 'index'])
        //     ->middleware('checkpermission:admin.skills.view');

        // Route::post('/skills', [AdminSkillController::class, 'store'])
        //     ->middleware('checkpermission:admin.skills.create');

        // Route::put('/skills/{skill}', [AdminSkillController::class, 'update'])
        //     ->middleware('checkpermission:admin.skills.update');

        // Route::delete('/skills/{skill}', [AdminSkillController::class, 'destroy'])
        //     ->middleware('checkpermission:admin.skills.delete');

        // Route::post('/skills/{skill}/toggle', [AdminSkillController::class, 'toggle'])
        //     ->middleware('checkpermission:admin.skills.update');

        // ============================================================
        // ✅ Categories Management
        // ============================================================
        // Route::get('/categories', [AdminCategoryController::class, 'index'])
        //     ->middleware('checkpermission:admin.categories.view');

        // Route::post('/categories', [AdminCategoryController::class, 'store'])
        //     ->middleware('checkpermission:admin.categories.create');

        // Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])
        //     ->middleware('checkpermission:admin.categories.update');

        // Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])
        //     ->middleware('checkpermission:admin.categories.delete');

        // Route::post('/categories/reorder', [AdminCategoryController::class, 'reorder'])
        //     ->middleware('checkpermission:admin.categories.update');

        // Route::post('/categories/{category}/toggle', [AdminCategoryController::class, 'toggle'])
        //     ->middleware('checkpermission:admin.categories.update');

        // ============================================================
        // ✅ Broadcast Notifications - فقط super_admin
        // ============================================================
        Route::prefix('broadcast')->group(function () {
            Route::post('/send', [AdminBroadcastController::class, 'send'])
                ->middleware(['checkpermission:admin.notifications.send', 'throttle:5,10']);
            Route::get('/', [AdminBroadcastController::class, 'index'])
                ->middleware('checkpermission:admin.notifications.view');
            Route::get('/{broadcast}', [AdminBroadcastController::class, 'show'])
                ->middleware('checkpermission:admin.notifications.view');
            Route::delete('/{broadcast}', [AdminBroadcastController::class, 'destroy'])
                ->middleware('checkpermission:admin.notifications.delete');
            Route::delete('/', [AdminBroadcastController::class, 'destroyAll'])
                ->middleware('checkpermission:admin.notifications.delete');
        });

        // ============================================================
        // ✅ Activity Logs - فقط super_admin
        // ============================================================
        Route::prefix('activity-logs')->group(function () {
            Route::get('/', [AdminActivityLogController::class, 'index'])
                ->middleware('checkpermission:admin.activity_logs.view');
            Route::get('/stats', [AdminActivityLogController::class, 'stats'])
                ->middleware('checkpermission:admin.activity_logs.view');
            Route::get('/{id}', [AdminActivityLogController::class, 'show'])
                ->middleware('checkpermission:admin.activity_logs.view');
            Route::delete('/clean', [AdminActivityLogController::class, 'clean'])
                ->middleware('checkpermission:admin.activity_logs.clean');
        });

        // ============================================================
        // ✅ Backup Routes - فقط super_admin
        // ============================================================
        Route::prefix('backups')->group(function () {
            Route::get('/', [AdminController::class, 'backups'])
                ->middleware('checkpermission:admin.backups.view');
            Route::post('/', [AdminController::class, 'createBackup'])
                ->middleware('checkpermission:admin.backups.create');
            Route::get('/stats', [AdminController::class, 'backupStats'])
                ->middleware('checkpermission:admin.backups.view');
            Route::get('/{backup}/download', [AdminController::class, 'downloadBackup'])
                ->middleware('checkpermission:admin.backups.download');
            Route::delete('/{backup}', [AdminController::class, 'deleteBackup'])
                ->middleware('checkpermission:admin.backups.delete');
        });

// ============================================================
// 🔹 Admin Management - فقط super_admin
// ============================================================
Route::prefix('admins')
    ->middleware('checkrole:super_admin')
    ->group(function () {
        Route::get('/', [AdminUserController::class, 'adminsList'])
            ->middleware('checkpermission:admin.users.view');

        Route::post('/', [AdminUserController::class, 'store'])
            ->middleware('checkpermission:admin.users.create');

        Route::get('/{admin}', [AdminUserController::class, 'showAdmin'])
            ->middleware('checkpermission:admin.users.view');

        Route::delete('/{admin}', [AdminUserController::class, 'destroyAdmin'])
            ->middleware('checkpermission:admin.users.delete');

        Route::post('/{admin}/suspend', [AdminUserController::class, 'suspendAdmin'])
            ->middleware('checkpermission:admin.users.suspend');

        Route::post('/{admin}/activate', [AdminUserController::class, 'activateAdmin'])
            ->middleware('checkpermission:admin.users.update');

        Route::post('/{admin}/roles', [AdminUserController::class, 'assignRole'])
            ->middleware('checkpermission:admin.roles.update');

        Route::delete('/{admin}/roles/{role}', [AdminUserController::class, 'removeRole'])
            ->middleware('checkpermission:admin.roles.update');

        Route::post('/{admin}/permissions', [AdminUserController::class, 'syncPermissions'])
            ->middleware('checkpermission:admin.permissions.update');

        Route::get('/{admin}/permissions', [AdminUserController::class, 'getPermissions'])
            ->middleware('checkpermission:admin.permissions.view');
    });

        // ============================================================
        // 🔹 Roles Management - فقط super_admin
        // ============================================================
        Route::prefix('roles')
            ->middleware('checkrole:super_admin')
            ->group(function () {
                Route::get('/', [AdminUserController::class, 'getAllRoles'])
                    ->middleware('checkpermission:admin.roles.view');
                Route::post('/', [AdminUserController::class, 'createRole'])
                    ->middleware('checkpermission:admin.roles.create');
                Route::put('/{role}', [AdminUserController::class, 'updateRole'])
                    ->middleware('checkpermission:admin.roles.update');
                Route::delete('/{role}', [AdminUserController::class, 'deleteRole'])
                    ->middleware('checkpermission:admin.roles.delete');
                Route::get('/{role}/permissions', [AdminUserController::class, 'getRolePermissions'])
                    ->middleware('checkpermission:admin.roles.view');
                Route::post('/{role}/permissions', [AdminUserController::class, 'syncRolePermissions'])
                    ->middleware('checkpermission:admin.roles.update');
            });

        // ============================================================
        // 🔹 Permissions Management - فقط super_admin
        // ============================================================
        Route::prefix('permissions')
            ->middleware('checkrole:super_admin')
            ->group(function () {
                Route::get('/', [AdminUserController::class, 'getAllPermissions'])
                    ->middleware('checkpermission:admin.permissions.view');
                Route::post('/', [AdminUserController::class, 'createPermission'])
                    ->middleware('checkpermission:admin.permissions.create');
                Route::put('/{permission}', [AdminUserController::class, 'updatePermission'])
                    ->middleware('checkpermission:admin.permissions.update');
                Route::delete('/{permission}', [AdminUserController::class, 'deletePermission'])
                    ->middleware('checkpermission:admin.permissions.delete');
            });
    });

// ============================================================
// 🔹 Interview Routes (for regular users - practice)
// ============================================================
Route::middleware(['auth:sanctum', 'role:regular_user'])->group(function () {
    // ===== Interviews =====
    Route::apiResource('interviews', InterviewController::class)->except(['update', 'destroy'])
        ->middleware(['throttle:start-interview', 'permission:user.interviews.start']);

    Route::post('/interviews/{interview}/complete', [InterviewController::class, 'complete'])
        ->middleware(['throttle:complete-interview', 'permission:user.interviews.complete']);

    Route::get('/interviews/{interview}/status', [InterviewController::class, 'checkFinalStatus'])
        ->middleware(['throttle:session-status', 'permission:user.interviews.view']);

    Route::get('/interviews/{interview}/report', [InterviewController::class, 'getFinalReport'])
        ->middleware(['throttle:get-report', 'permission:user.results.view']);

    Route::get('/interviews/{interview}/report-ready', [InterviewController::class, 'checkReportReady'])
        ->middleware(['throttle:check-report', 'permission:user.interviews.view']);

    // ===== Session Management =====
    Route::get('/interviews/{interview}/session', [InterviewController::class, 'sessionStatus'])
        ->middleware(['throttle:session-status', 'permission:user.interviews.view']);

    Route::get('/interviews/resume/{token}', [InterviewController::class, 'resumeByToken'])
        ->middleware(['throttle:resume-interview', 'permission:user.interviews.resume']);

    // ===== Resume Interview =====
    Route::get('/interviews/{interview}/resume', [InterviewController::class, 'resume'])
        ->middleware(['throttle:resume-interview', 'permission:user.interviews.resume']);

    Route::get('/interviews/{interview}/can-resume', [InterviewController::class, 'canResume'])
        ->middleware(['throttle:session-status', 'permission:user.interviews.view']);

    // ===== Tab Lock =====
    Route::get('/interviews/{interview}/lock-status', [InterviewController::class, 'lockStatus'])
        ->middleware(['throttle:interview-lock', 'permission:user.interviews.view']);

    Route::post('/interviews/{interview}/lock', [InterviewController::class, 'lock'])
        ->middleware(['throttle:interview-lock', 'permission:user.interviews.start']);

    Route::post('/interviews/{interview}/unlock', [InterviewController::class, 'unlock'])
        ->middleware(['throttle:interview-lock', 'permission:user.interviews.start']);

    Route::post('/interviews/{interview}/refresh-lock', [InterviewController::class, 'refreshLock'])
        ->middleware(['throttle:refresh-lock', 'permission:user.interviews.start']);

    // ===== Answers =====
    Route::post('/answers', [AnswerController::class, 'store'])
        ->middleware(['throttle:submit-answer', 'permission:user.answers.submit']);

    Route::get('/answers/{answer}', [AnswerController::class, 'show'])
        ->middleware('permission:user.interviews.view');

    // ===== Anti-cheat =====
    Route::post('/anti-cheat/violations', [AntiCheatController::class, 'store'])
        ->middleware(['throttle:anti-cheat', 'permission:user.answers.submit']);

    Route::get('/interviews/{interview}/violations', [AntiCheatController::class, 'index'])
        ->middleware('permission:user.interviews.view');

    // ===== Interview Answer AI Analysis =====
    Route::post('/analyze-answer', [InterviewAnalysisController::class, 'analyze'])
        ->middleware(['throttle:submit-answer', 'permission:user.answers.submit']);
});
