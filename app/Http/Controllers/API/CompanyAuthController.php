<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

   /**
 * Update company profile
 * PUT /api/company/profile
 */
public function updateProfile(Request $request): JsonResponse
{
    $company = $request->user();

    // ✅ التحقق من صحة البيانات (مع دعم _method)
    $request->validate([
        'company_name' => 'sometimes|string|max:255',
        'industry' => 'nullable|string|max:255',
        'website' => 'nullable|string|max:255|url',
        'description' => 'nullable|string',
        'phone' => 'nullable|string|max:20',
        'address' => 'nullable|string|max:255',
        'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:5120',
        '_method' => 'nullable|string|in:PUT', // السماح بـ _method
    ]);

    // ✅ تحديث البيانات النصية
    if ($request->has('company_name')) {
        $company->company_name = $request->company_name;
    }
    if ($request->has('industry')) {
        $company->industry = $request->industry;
    }
    if ($request->has('website')) {
        $company->website = $request->website;
    }
    if ($request->has('description')) {
        $company->description = $request->description;
    }
    if ($request->has('phone')) {
        $company->phone = $request->phone;
    }
    if ($request->has('address')) {
        $company->address = $request->address;
    }

    // ✅ رفع الشعار (Logo)
    if ($request->hasFile('logo')) {
        // حذف الشعار القديم إذا وجد
        if ($company->logo && Storage::disk('public')->exists($company->logo)) {
            Storage::disk('public')->delete($company->logo);
        }

        // حفظ الشعار الجديد
        $file = $request->file('logo');
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs('companies/logos', $filename, 'public');
        $company->logo = $path;
    }

    $company->save();

    return response()->json([
        'success' => true,
        'message' => 'Profile updated successfully',
        'data' => [
            'id' => $company->id,
            'company_name' => $company->company_name,
            'email' => $company->email,
            'logo' => $company->logo ? asset('storage/' . $company->logo) : null,
            'industry' => $company->industry,
            'website' => $company->website,
            'description' => $company->description,
            'phone' => $company->phone,
            'address' => $company->address,
            'status' => $company->status,
            'updated_at' => $company->updated_at,
        ],
    ]);
}
}
