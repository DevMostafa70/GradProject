<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /**
     * Request a password-reset email for a regular platform user only.
     *
     * The response is intentionally identical whether the email exists or not
     * to prevent account enumeration.
     */
    public function forgotUser(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'The provided email address is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = Str::lower(trim((string) $request->input('email')));

        $genericResponse = response()->json([
            'success' => true,
            'message' => 'If an eligible account exists for this email, a password reset link has been sent.',
        ]);

        $eligibleUser = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('role', 'user')
            ->where('is_company_employee', false)
            ->where('is_active', true)
            ->first();

        if (! $eligibleUser) {
            return $genericResponse;
        }

        try {
            $status = Password::broker('users')->sendResetLink([
                'email' => $eligibleUser->email,
                'role' => 'user',
                'is_company_employee' => false,
                'is_active' => true,
            ]);

            if ($status !== Password::RESET_LINK_SENT) {
                Log::warning('Regular-user password reset link was not sent', [
                    'user_id' => $eligibleUser->id,
                    'status' => $status,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('Regular-user password reset request failed', [
                'user_id' => $eligibleUser->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $genericResponse;
    }

    /**
     * Reset a password for any supported account type.
     *
     * Forgot-password is currently exposed only for regular users, but this
     * endpoint is intentionally shared so links issued by other controlled
     * workflows can use one secure reset page.
     */
    public function reset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account_type' => [
                'required',
                'string',
                'in:user,candidate,company_employee,company,admin',
            ],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:2048'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8)->letters()->mixedCase()->numbers(),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please review the password reset information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $accountType = (string) $request->input('account_type');
        $email = Str::lower(trim((string) $request->input('email')));

        [$broker, $credentials] = $this->brokerAndCredentials(
            $accountType,
            $email,
            (string) $request->input('token'),
            (string) $request->input('password'),
            (string) $request->input('password_confirmation'),
        );

        try {
            $status = Password::broker($broker)->reset(
                $credentials,
                function ($account, string $password) use ($accountType): void {
                    $account->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    if (method_exists($account, 'tokens')) {
                        $account->tokens()->delete();
                    }

                    // The legacy candidate flow keeps a duplicate password in
                    // candidates. Login uses users, but synchronizing it avoids
                    // stale credentials in any remaining legacy code.
                    if ($accountType === 'candidate' && $account instanceof User) {
                        Candidate::query()
                            ->where('email', $account->email)
                            ->update(['password' => Hash::make($password)]);
                    }

                    event(new PasswordReset($account));
                }
            );
        } catch (\Throwable $exception) {
            Log::error('Password reset failed unexpectedly', [
                'account_type' => $accountType,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to reset the password at this time. Please try again later.',
            ], 500);
        }

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'The password reset link is invalid, expired, or does not match this account.',
                'error_code' => 'INVALID_OR_EXPIRED_RESET_LINK',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully. Please sign in with your new password.',
        ]);
    }

    private function brokerAndCredentials(
        string $accountType,
        string $email,
        string $token,
        string $password,
        string $passwordConfirmation,
    ): array {
        $credentials = [
            'email' => $email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ];

        return match ($accountType) {
            'user' => [
                'users',
                array_merge($credentials, [
                    'role' => 'user',
                    'is_company_employee' => false,
                ]),
            ],
            'candidate' => [
                'users',
                array_merge($credentials, [
                    'role' => 'candidate',
                    'is_company_employee' => false,
                ]),
            ],
            'company_employee' => [
                'users',
                array_merge($credentials, [
                    'is_company_employee' => true,
                ]),
            ],
            'company' => ['companies', $credentials],
            'admin' => ['admins', $credentials],
        };
    }
}
