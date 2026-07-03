<?php

use App\Http\Controllers\API\Admin\AdminActivityLogController;
use App\Http\Controllers\API\UserAuthController;
use App\Http\Controllers\API\Candidate\CandidateAuthController;
use App\Http\Controllers\API\CompanyAuthController;
use App\Http\Controllers\API\Admin\AdminAuthController;
use App\Http\Controllers\API\Admin\AdminBroadcastController;
use App\Http\Controllers\API\Admin\AdminCategoryController;
use App\Http\Controllers\API\Admin\AdminCompanyController;
use App\Http\Controllers\API\Admin\AdminController;
use App\Http\Controllers\API\Admin\AdminSkillController;
use App\Http\Controllers\API\Admin\AdminUserController;
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
});

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

// ===== Stripe Webhook (No Auth - Public) =====
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->name('stripe.webhook');

// ==================== Protected Routes (Auth Required) ====================

// ===== User Routes (Regular Users) =====
Route::prefix('user')->middleware(['auth:sanctum', 'role:user', 'throttle:user'])->group(function () {
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

// ===== Company Routes (Auth + Role) =====
Route::prefix('company')->middleware(['auth:sanctum', 'role:company'])->group(function () {

    // ===== Routes لا تحتاج اشتراك فعال =====
    Route::post('/logout', [CompanyAuthController::class, 'logout']);
    Route::get('/profile', [CompanyAuthController::class, 'profile']);
    Route::post('/profile', [CompanyAuthController::class, 'updateProfile']);

        // ===== Notifications =====
    Route::get('/notifications', [CompanyAuthController::class, 'notifications']);
    Route::put('/notifications/{id}/read', [CompanyAuthController::class, 'markNotificationAsRead']);
     Route::delete('/notifications', [CompanyAuthController::class, 'deleteAllNotifications']); 
    Route::delete('/notifications/{id}', [CompanyAuthController::class, 'deleteNotification']);

    // ===== Billing Routes =====
    Route::prefix('billing')->group(function () {
        Route::get('/status', [BillingStatusController::class, 'show'])
            ->middleware('company.authenticated');

        Route::post('/select-plan', [SelectPlanController::class, 'store'])
            ->middleware(['company.authenticated', 'company.approved']);

        Route::post('/checkout', [CheckoutController::class, 'store'])
            ->middleware(['company.authenticated', 'company.approved']);

        Route::post('/portal', [BillingPortalController::class, 'store'])
            ->middleware(['company.authenticated']);
    });

    // ===== Routes تحتاج اشتراك فعال (company.paid) =====
    Route::middleware(['company.paid'])->group(function () {
        // Job Management
        Route::apiResource('jobs', CompanyJobController::class)->only(['index', 'store', 'show']);
        Route::post('/jobs/{job}/close', [CompanyJobController::class, 'close']);
        Route::get('/jobs/{job}/stats', [CompanyJobController::class, 'stats']);
        Route::get('/jobs/{job}/candidates', [CompanyJobController::class, 'candidates']);
        Route::get('/jobs/{job}/candidates/{candidate}', [CompanyJobController::class, 'candidateDetails']);
        Route::put('/jobs/{job}/candidates/{candidate}/status', [CompanyJobController::class, 'updateCandidateStatus']);

        // Bulk invitations
        Route::post('/jobs/{job}/invite-bulk', [CompanyJobController::class, 'inviteBulk']);
        Route::get('/jobs/{job}/invitation-stats', [CompanyJobController::class, 'invitationStats']);
        Route::get('/jobs/{job}/invitations', [CompanyJobController::class, 'invitations']);

        // Question Bank
        Route::post('/jobs/{job}/upload-questions', [CompanyJobController::class, 'uploadQuestions']);
        Route::get('/jobs/{job}/question-stats', [CompanyJobController::class, 'questionStats']);
        Route::get('/jobs/{job}/question-bank', [CompanyJobController::class, 'getQuestionBank']);
    });
});

// ===== Admin Routes =====
Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin,super_admin', 'throttle:admin'])->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/profile', [AdminAuthController::class, 'profile']);

    // Dashboard
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/logs', [AdminController::class, 'logs']);

    // Users Management (Regular Users)
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/users/{user}', [AdminUserController::class, 'show']);
    Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspendUser']);
    Route::post('/users/{user}/activate', [AdminUserController::class, 'activateUser']);
    Route::delete('/users/{user}', [AdminUserController::class, 'deleteUser']);

    // Candidates Management
    Route::get('/candidates', [AdminUserController::class, 'candidatesList']);
    Route::get('/candidates/{candidate}', [AdminUserController::class, 'showCandidate']);
    Route::delete('/candidates/{candidate}', [AdminUserController::class, 'deleteCandidate']);
    Route::get('/companies/{company}/candidates', [AdminUserController::class, 'companyCandidates']);

    // Admins Management
    Route::get('/admins', [AdminUserController::class, 'adminsList']);
    Route::post('/admins', [AdminUserController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/admins/{admin}', [AdminUserController::class, 'showAdmin']);
    Route::delete('/admins/{admin}', [AdminUserController::class, 'destroyAdmin']);
    Route::post('/admins/{admin}/suspend', [AdminUserController::class, 'suspendAdmin']);
    Route::post('/admins/{admin}/activate', [AdminUserController::class, 'activateAdmin']);

    // Companies Management
    Route::get('/companies/pending', [AdminCompanyController::class, 'pendingRequests']);
    Route::post('/companies/{company}/approve', [AdminCompanyController::class, 'approve']);
    Route::post('/companies/{company}/reject', [AdminCompanyController::class, 'reject']);
    Route::post('/companies/{company}/suspend', [AdminCompanyController::class, 'suspend']);
    Route::post('/companies/{company}/activate', [AdminCompanyController::class, 'activate']);
    Route::delete('/companies/{company}', [AdminCompanyController::class, 'destroy']);
    Route::get('/companies', [AdminCompanyController::class, 'index']);

    // Skills Management
    Route::apiResource('skills', AdminSkillController::class);
    Route::post('/skills/{skill}/toggle', [AdminSkillController::class, 'toggle']);

    // Categories Management
    Route::apiResource('categories', AdminCategoryController::class);
    Route::post('/categories/reorder', [AdminCategoryController::class, 'reorder']);
    Route::post('/categories/{category}/toggle', [AdminCategoryController::class, 'toggle']);

    // Broadcast Notifications
    Route::post('/broadcast/send', [AdminBroadcastController::class, 'send'])->middleware('throttle:5,10');
    Route::get('/broadcast', [AdminBroadcastController::class, 'index']);
    Route::get('/broadcast/{broadcast}', [AdminBroadcastController::class, 'show']);
    Route::delete('/broadcast/{broadcast}', [AdminBroadcastController::class, 'destroy']);
    Route::delete('/broadcast', [AdminBroadcastController::class, 'destroyAll']);

    // ===== Activity Logs =====
    Route::get('/activity-logs', [AdminActivityLogController::class, 'index']);
    Route::get('/activity-logs/stats', [AdminActivityLogController::class, 'stats']);
    Route::get('/activity-logs/{id}', [AdminActivityLogController::class, 'show']);
    Route::delete('/activity-logs/clean', [AdminActivityLogController::class, 'clean']);


    // 🔹 NEW: Backup Routes

    Route::prefix('backups')->group(function () {
        Route::get('/', [AdminController::class, 'backups']);
        Route::post('/', [AdminController::class, 'createBackup']);
        Route::get('/stats', [AdminController::class, 'backupStats']);
        Route::get('/{backup}/download', [AdminController::class, 'downloadBackup']);
        Route::delete('/{backup}', [AdminController::class, 'deleteBackup']);

});

// ============================================================
// 🔹 Interview Routes (for regular users - practice)
// ============================================================
Route::middleware(['auth:sanctum', 'role:user'])->group(function () {
    // ===== Interviews =====
    Route::apiResource('interviews', InterviewController::class)->except(['update', 'destroy'])
        ->middleware('throttle:start-interview'); // 🔹 NEW: حد لبدء المقابلات

    Route::post('/interviews/{interview}/complete', [InterviewController::class, 'complete'])
        ->middleware('throttle:complete-interview'); // 🔹 NEW

    Route::get('/interviews/{interview}/status', [InterviewController::class, 'checkFinalStatus'])
        ->middleware('throttle:session-status'); // 🔹 NEW

    Route::get('/interviews/{interview}/report', [InterviewController::class, 'getFinalReport'])
        ->middleware('throttle:get-report'); // 🔹 NEW

    Route::get('/interviews/{interview}/report-ready', [InterviewController::class, 'checkReportReady'])
        ->middleware('throttle:check-report'); // 🔹 NEW

    // ===== Session Management =====
    Route::get('/interviews/{interview}/session', [InterviewController::class, 'sessionStatus'])
        ->middleware('throttle:session-status'); // 🔹 NEW

    Route::get('/interviews/resume/{token}', [InterviewController::class, 'resumeByToken'])
        ->middleware('throttle:resume-interview'); // 🔹 NEW

    // ===== Resume Interview =====
    Route::get('/interviews/{interview}/resume', [InterviewController::class, 'resume'])
        ->middleware('throttle:resume-interview'); // 🔹 NEW

    Route::get('/interviews/{interview}/can-resume', [InterviewController::class, 'canResume'])
        ->middleware('throttle:session-status'); // 🔹 NEW

    // ===== Tab Lock =====
    Route::get('/interviews/{interview}/lock-status', [InterviewController::class, 'lockStatus'])
        ->middleware('throttle:interview-lock'); // 🔹 NEW

    Route::post('/interviews/{interview}/lock', [InterviewController::class, 'lock'])
        ->middleware('throttle:interview-lock'); // 🔹 NEW

    Route::post('/interviews/{interview}/unlock', [InterviewController::class, 'unlock'])
        ->middleware('throttle:interview-lock'); // 🔹 NEW

    Route::post('/interviews/{interview}/refresh-lock', [InterviewController::class, 'refreshLock'])
        ->middleware('throttle:refresh-lock'); // 🔹 NEW

    // ===== Answers =====
    Route::post('/answers', [AnswerController::class, 'store'])
        ->middleware('throttle:submit-answer'); // 🔹 NEW

    Route::get('/answers/{answer}', [AnswerController::class, 'show']);

    // ===== Anti-cheat =====
    Route::post('/anti-cheat/violations', [AntiCheatController::class, 'store'])
        ->middleware('throttle:anti-cheat'); // 🔹 NEW

    Route::get('/interviews/{interview}/violations', [AntiCheatController::class, 'index']);

    // ===== Interview Answer AI Analysis =====
    Route::post('/analyze-answer', [InterviewAnalysisController::class, 'analyze'])
        ->middleware('throttle:submit-answer'); // 🔹 NEW
});
});
