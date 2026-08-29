<?php

namespace App\Http\Controllers\Api\Candidate;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CandidateAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        // 1. إنشاء المستخدم في جدول users (للعرض الموحد)
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'candidate',
            'is_active' => true,
        ]);

        // 2. إنشاء المرشح في جدول candidates (بياناته كاملة)
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'bio' => null,
            'skills' => null,
            'phone' => null,
        ]);

        $token = $user->createToken('candidate-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => $user,
                'candidate' => $candidate,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // ========== التحقق من حالة الحساب في users ==========
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact support.',
            ], 403);
        }

        // ========== التحقق من حالة المرشح في جدول candidates ==========
        if ($user->isCandidate() && $user->candidate) {
            if ($user->candidate->status === 'suspended') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your candidate account has been suspended. Please contact support.',
                ], 403);
            }
        }

        // ========== نهاية التحقق ==========

        $user->tokens()->delete();
        $token = $user->createToken('candidate-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'candidate' => $user->candidate,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user(),
        ]);
    }
}
