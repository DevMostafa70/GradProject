<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        try {

            /** @var User $user */
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $stats = [
                'total_interviews' => $user->interviews()->where('status', 'completed_with_report')->count(),
                'average_score' => $this->calculateAverageScore($user),
                'practice_days' => $this->calculatePracticeDays($user),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'bio' => $user->bio ?? null,
                    'avatar' => $user->avatar ? asset('storage/' . $user->avatar) : null,
                    'created_at' => $user->created_at,
                    'stats' => $stats,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = Auth::user();

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'bio' => 'nullable|string|max:1000',
            ]);

            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            /** @var User $user */
            $user = Auth::user();

            if (!Hash::check($request->current_password, $user->password)) {

                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }

            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function uploadAvatar(Request $request): JsonResponse
{
    try {

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg|max:5120'
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (
            $user->avatar &&
            Storage::disk('public')->exists($user->avatar)
        ) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')
            ->store('avatars', 'public');

        $user->update([
            'avatar' => $path
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Avatar uploaded successfully',
            'data' => [
                'avatar' => asset('storage/' . $path)
            ]
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}
    private function calculateAverageScore(User $user): ?float
    {
        $completedInterviews = $user->interviews()
            ->where('status', 'completed_with_report')
            ->with('finalReport')
            ->get();

        if ($completedInterviews->isEmpty()) {
            return null;
        }

        $totalScore = $completedInterviews->sum(function ($interview) {
            return $interview->finalReport?->overall_score ?? 0;
        });

        $average = $totalScore / $completedInterviews->count();

        return round($average * 10, 2);
    }

    private function calculatePracticeDays($user): int
    {
        return $user->interviews()
            ->where('status', 'completed_with_report')
            ->distinct()
            ->count('created_at');
    }
}
