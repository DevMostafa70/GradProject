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
});

// ===== Admin Auth =====
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:auth');
});

// ===== Public interview routes (candidates using job link - no login required) =====
Route::prefix('interview/join')->group(function () {
    Route::get('/{token}', [PublicInterviewController::class, 'showJob'])->where('token', '.*')->middleware('throttle:60,1');
    Route::post('/{token}/start', [PublicInterviewController::class, 'start'])->where('token', '.*')->middleware('throttle:10,1');
    Route::post('/{token}/answer', [PublicInterviewController::class, 'submitAnswer'])->where('token', '.*')->middleware('throttle:interview');
    Route::post('/{token}/complete', [PublicInterviewController::class, 'complete'])->where('token', '.*')->middleware('throttle:10,1');
});

// ==================== Protected Routes (Auth Required) ====================

// ===== User Routes (Regular Users) =====
Route::prefix('user')->middleware(['auth:sanctum', 'throttle:user'])->group(function () {
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
});

// ===== Candidate Routes (Legacy - معلقة مؤقتاً) =====
// Route::prefix('candidate')->middleware('auth:candidate')->group(function () {
//     Route::post('/logout', [CandidateAuthController::class, 'logout']);
//     Route::get('/profile', [CandidateAuthController::class, 'profile']);

//     // Dashboard
//     Route::get('/dashboard', [DashboardController::class, 'index']);
//     Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
//     Route::get('/dashboard/progress', [DashboardController::class, 'progress']);
//     Route::get('/dashboard/weaknesses', [DashboardController::class, 'weaknesses']);
//     Route::get('/dashboard/daily-questions', [DashboardController::class, 'dailyQuestions']);

//     // Results
//     Route::get('/results', [ResultsController::class, 'index']);
//     Route::get('/results/summary', [ResultsController::class, 'summary']);
//     Route::get('/results/{interview}', [ResultsController::class, 'show']);

//     // Resume
//     Route::post('/resume/upload', [ResumeController::class, 'upload']);
//     Route::get('/resume', [ResumeController::class, 'index']);
//     Route::get('/resume/latest', [ResumeController::class, 'latest']);
//     Route::get('/resume/{resume}', [ResumeController::class, 'show']);
//     Route::get('/resume/{resume}/improvements', [ResumeController::class, 'improvements']);
//     Route::delete('/resume/{resume}', [ResumeController::class, 'destroy']);
// });

// ===== Company Routes =====
Route::prefix('company')->middleware(['auth:sanctum', 'throttle:company'])->group(function () {
    Route::post('/logout', [CompanyAuthController::class, 'logout']);
    Route::get('/profile', [CompanyAuthController::class, 'profile']);

    // Job Management
    Route::apiResource('jobs', CompanyJobController::class)->only(['index', 'store', 'show']);
    Route::post('/jobs/{job}/close', [CompanyJobController::class, 'close']);
    Route::get('/jobs/{job}/stats', [CompanyJobController::class, 'stats']);
    Route::get('/jobs/{job}/candidates', [CompanyJobController::class, 'candidates']);
    Route::get('/jobs/{job}/candidates/{candidate}', [CompanyJobController::class, 'candidateDetails']);
    Route::put('/jobs/{job}/candidates/{candidate}/status', [CompanyJobController::class, 'updateCandidateStatus']);

    // Bulk invitations
    Route::post('/jobs/{job}/invite-bulk', [CompanyJobController::class, 'inviteBulk'])->middleware('throttle:5,10');
    Route::get('/jobs/{job}/invitation-stats', [CompanyJobController::class, 'invitationStats']);
    Route::get('/jobs/{job}/invitations', [CompanyJobController::class, 'invitations']);

    // Question Bank
    Route::post('/jobs/{job}/upload-questions', [CompanyJobController::class, 'uploadQuestions'])->middleware('throttle:upload');
    Route::get('/jobs/{job}/question-stats', [CompanyJobController::class, 'questionStats']);
    Route::get('/jobs/{job}/question-bank', [CompanyJobController::class, 'getQuestionBank']);


     // Billing
Route::get('/billing/status', [BillingStatusController::class, 'show'])
    ->middleware('company.authenticated');

Route::post('/billing/select-plan', [SelectPlanController::class, 'store'])
    ->middleware(['company.authenticated', 'company.approved']);

Route::post('/billing/checkout', [CheckoutController::class, 'store'])
    ->middleware(['company.authenticated', 'company.approved']);

Route::post('/billing/portal', [BillingPortalController::class, 'store'])
    ->middleware(['company.authenticated']);
});
// Billing
Route::get('/billing/status', [BillingStatusController::class, 'show'])
    ->middleware('company.authenticated');

Route::post('/billing/select-plan', [SelectPlanController::class, 'store'])
    ->middleware(['company.authenticated', 'company.approved']);

Route::post('/billing/checkout', [CheckoutController::class, 'store'])
    ->middleware(['company.authenticated', 'company.approved']);

Route::post('/billing/portal', [BillingPortalController::class, 'store'])
    ->middleware(['company.authenticated']);
// ===== Admin Routes =====
Route::prefix('admin')->middleware(['auth:sanctum', 'throttle:admin'])->group(function () {
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

    // ===== Activity Logs =====
    Route::get('/activity-logs', [AdminActivityLogController::class, 'index']);
    Route::get('/activity-logs/stats', [AdminActivityLogController::class, 'stats']);
    Route::get('/activity-logs/{id}', [AdminActivityLogController::class, 'show']);
    Route::delete('/activity-logs/clean', [AdminActivityLogController::class, 'clean']);
});

// ===== Interview Routes (for regular users - practice) =====
Route::middleware(['auth:sanctum', 'throttle:interview'])->group(function () {
    Route::apiResource('interviews', InterviewController::class)->except(['update', 'destroy']);
    Route::post('/interviews/{interview}/complete', [InterviewController::class, 'complete']);
    Route::get('/interviews/{interview}/status', [InterviewController::class, 'checkFinalStatus']);
    Route::get('/interviews/{interview}/report', [InterviewController::class, 'getFinalReport']);
    Route::get('/interviews/{interview}/report-ready', [InterviewController::class, 'checkReportReady']);

    // Answers
    Route::post('/answers', [AnswerController::class, 'store'])->middleware('throttle:interview');
    Route::get('/answers/{answer}', [AnswerController::class, 'show']);

    // Anti-cheat
    Route::post('/anti-cheat/violations', [AntiCheatController::class, 'store']);
    Route::get('/interviews/{interview}/violations', [AntiCheatController::class, 'index']);

    // Interview Answer AI Analysis
    Route::post('/analyze-answer', [InterviewAnalysisController::class, 'analyze']);
});

// ===== Company Auth =====
Route::prefix('company')->group(function () {
    Route::post('/register', [CompanyAuthController::class, 'register']);
    Route::post('/login', [CompanyAuthController::class, 'login']);
});
// ===== Company Auth =====
Route::prefix('company')->group(function () {
    Route::post('/register', [CompanyAuthController::class, 'register']);
    Route::post('/login', [CompanyAuthController::class, 'login']);

    // Billing Plans
    Route::get('/plans', [PlanController::class, 'index']);
});

