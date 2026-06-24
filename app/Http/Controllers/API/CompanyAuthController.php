<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class CompanyAuthController extends Controller
{
    /**
     * Register a new company
     * POST /api/company/register
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:companies',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'industry' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        // إنشاء الشركة مباشرة في جدول companies (بدون user)
        $company = Company::create([
            'company_name' => $request->company_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'industry' => $request->industry,
            'phone' => $request->phone,
            'status' => 'pending',
        ]);

        $token = $company->createToken('company-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Company registered successfully. Waiting for admin approval.',
            'data' => [
                'company' => [
                    'id' => $company->id,
                    'company_name' => $company->company_name,
                    'email' => $company->email,
                    'status' => $company->status,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    /**
     * Login company
     * POST /api/company/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // البحث عن الشركة مباشرة في جدول companies
        $company = Company::where('email', $request->email)->first();

        if (!$company || !Hash::check($request->password, $company->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // التحقق من حالة الشركة
        if ($company->status !== 'approved') {
            $message = $company->status === 'pending'
                ? 'Your company account is pending admin approval.'
                : 'Your company account has been suspended or rejected.';

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        // حذف التوكنات القديمة
        $company->tokens()->delete();

        // إنشاء توكن جديد
        $token = $company->createToken('company-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'company' => [
                    'id' => $company->id,
                    'company_name' => $company->company_name,
                    'email' => $company->email,
                    'status' => $company->status,
                    'industry' => $company->industry,
                    'phone' => $company->phone,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Logout company
     * POST /api/company/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get company profile
     * GET /api/company/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $company = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'email' => $company->email,
                'logo' => $company->logo,
                'industry' => $company->industry,
                'website' => $company->website,
                'description' => $company->description,
                'phone' => $company->phone,
                'address' => $company->address,
                'status' => $company->status,
                'created_at' => $company->created_at,
            ],
        ]);
    }
}
