<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{

    //التسجيل (للمستخدمين العاديين - Candidates)
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6|confirmed'
        ]);

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'candidate', // المستخدم العادي يكون مرشح
                'is_active' => true,   // المرشحين يتم تفعيلهم مباشرة
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'Bearer',
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during recording',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //-------------------------------------------------------------------------------------------------

    //تسجيل الدخول (للمستخدمين العاديين والشركات والأدمن)
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        try {
            if (!Auth::attempt($validated)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect email address or password',
                ], 401);
            }

            $user = User::where('email', $validated['email'])->first();

            // ========== START: Company Approval Check ==========

            // التحقق من أن الحساب مفعل
            if (!$user->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is deactivated. Please contact support.',
                ], 403);
            }

            // إذا كان المستخدم شركة، تحقق من موافقة الأدمن
            if ($user->role === 'company') {
                $company = $user->company;

                if (!$company) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Company profile not found.',
                    ], 404);
                }

                // حساب ينتظر الموافقة
                if ($company->status === 'pending') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your account is pending admin approval. Please wait for confirmation.',
                    ], 403);
                }

                // حساب مرفوض
                if ($company->status === 'rejected') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your registration has been rejected. Please contact support for more information.',
                        'reason' => $company->admin_notes,
                    ], 403);
                }
            }

            // ========== END: Company Approval Check ==========

            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'is_active' => $user->is_active,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while logging in',
            ], 500);
        }
    }

    //-------------------------------------------------------------------------------------------------

    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while logging out',
            ], 500);
        }
    }

    //-------------------------------------------------------------------------------------------------

    //طلب رابط إعادة تعيين كلمة المرور
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        try {
            $status = Password::sendResetLink($request->only('email'));

            if ($status === Password::RESET_LINK_SENT) {
                return response()->json([
                    'success' => true,
                    'message' => 'Password reset link sent to your email',
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while sending the password reset link',
            ], 500);
        }
    }

    //-------------------------------------------------------------------------------------------------

    //إعادة تعيين كلمة المرور
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:6|confirmed',
            'token' => 'required|string'
        ]);

        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password),
                    ])->save();

                    $user->tokens()->delete();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json([
                    'success' => true,
                    'message' => 'Password reset successful',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'The reset link is invalid or has expired',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while resetting the password',
            ], 500);
        }
    }

    //-------------------------------------------------------------------------------------------------

    //جلب بيانات المستخدم الحالي
    public function user(Request $request)
    {
        $user = $request->user();

        // إضافة بيانات الشركة إذا كان المستخدم شركة
        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
        ];

        if ($user->role === 'company' && $user->company) {
            $data['company'] = [
                'id' => $user->company->id,
                'company_name' => $user->company->company_name,
                'industry' => $user->company->industry,
                'phone' => $user->company->phone,
                'status' => $user->company->status,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    //-------------------------------------------------------------------------------------------------

    // تحديث الملف الشخصي
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'password' => 'sometimes|string|min:6|confirmed',
        ]);

        try {
            $user = $request->user();

            if ($request->has('name')) {
                $user->name = $request->name;
            }

            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating profile',
            ], 500);
        }
    }
}
