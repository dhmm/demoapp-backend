<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

/**
 * AuthController
 *
 * Handles all authentication operations: registration, login, logout,
 * token refresh, password management, email verification, and 2FA.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService      $authService,
        private readonly TwoFactorService $twoFactorService,
    ) {}

    // -------------------------------------------------------------------------
    // Registration & Login
    // -------------------------------------------------------------------------

    /**
     * Register a new customer account.
     *
     * POST /api/v1/auth/register
     *
     * @param  RegisterRequest  $request
     * @return JsonResponse  201 with user and token, or 422 validation errors
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user  = $this->authService->register($request->validated());
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Registration successful. Please verify your email.',
            'user'    => $user->only(['id', 'name', 'email', 'created_at']),
            'token'   => $token,
        ], 201);
    }

    /**
     * Authenticate an existing user and return a JWT.
     *
     * POST /api/v1/auth/login
     *
     * @param  LoginRequest  $request
     * @return JsonResponse  200 with token or 401
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                return response()->json(['message' => 'Invalid credentials.'], 401);
            }
        } catch (JWTException $e) {
            return response()->json(['message' => 'Could not create token.'], 500);
        }

        $user = Auth::user();

        // If 2FA is enabled, return a partial token requiring 2FA step
        if ($user->two_factor_enabled) {
            $partial = $this->twoFactorService->issuePartialToken($user);
            return response()->json([
                'two_factor_required' => true,
                'partial_token'       => $partial,
            ], 200);
        }

        return $this->respondWithToken($token, $user);
    }

    /**
     * Logout the authenticated user (invalidate JWT).
     *
     * POST /api/v1/auth/logout
     *
     * @return JsonResponse  204 No Content
     */
    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (JWTException $e) {
            // token already expired — treat as successful logout
        }

        return response()->json(['message' => 'Logged out successfully.'], 200);
    }

    /**
     * Refresh the current JWT token.
     *
     * POST /api/v1/auth/refresh
     *
     * @return JsonResponse  New token pair
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return $this->respondWithToken($newToken, Auth::user());
        } catch (TokenExpiredException $e) {
            return response()->json(['message' => 'Token has expired and can no longer be refreshed.'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['message' => 'Token is invalid.'], 401);
        }
    }

    /**
     * Get the currently authenticated user's info.
     *
     * GET /api/v1/auth/me
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        $user = Auth::user()->load(['roles', 'permissions']);
        return response()->json(['user' => $user]);
    }

    // -------------------------------------------------------------------------
    // Password management
    // -------------------------------------------------------------------------

    /**
     * Send a password reset link to the given email address.
     *
     * POST /api/v1/auth/forgot-password
     *
     * @param  ForgotPasswordRequest  $request
     * @return JsonResponse
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Password reset link sent to your email.'])
            : response()->json(['message' => 'Unable to send reset link. Please try again.'], 400);
    }

    /**
     * Reset password using a valid token.
     *
     * POST /api/v1/auth/reset-password
     *
     * @param  ResetPasswordRequest  $request
     * @return JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password has been reset successfully.'])
            : response()->json(['message' => 'Invalid or expired reset token.'], 400);
    }

    /**
     * Change the authenticated user's password.
     *
     * PUT /api/v1/auth/change-password
     *
     * @param  ChangePasswordRequest  $request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        // Invalidate all existing tokens for this user
        JWTAuth::invalidate(JWTAuth::getToken());
        $newToken = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Password changed successfully.',
            'token'   => $newToken,
        ]);
    }

    // -------------------------------------------------------------------------
    // Email verification
    // -------------------------------------------------------------------------

    /**
     * Verify the user's email address via a signed token.
     *
     * POST /api/v1/auth/verify-email/{token}
     *
     * @param  string  $token
     * @return JsonResponse
     */
    public function verifyEmail(string $token): JsonResponse
    {
        $result = $this->authService->verifyEmail($token);

        if (! $result) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 400);
        }

        return response()->json(['message' => 'Email address verified successfully.']);
    }

    /**
     * Resend the email verification link.
     *
     * POST /api/v1/auth/resend-verification
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|exists:users,email']);
        $this->authService->resendVerificationEmail($request->email);

        return response()->json(['message' => 'Verification email resent.']);
    }

    // -------------------------------------------------------------------------
    // Two-Factor Authentication
    // -------------------------------------------------------------------------

    /**
     * Enable TOTP-based two-factor authentication.
     *
     * POST /api/v1/auth/two-factor/enable
     *
     * @return JsonResponse  QR code URI and recovery codes
     */
    public function enableTwoFactor(): JsonResponse
    {
        $user   = Auth::user();
        $result = $this->twoFactorService->enable($user);

        return response()->json([
            'qr_code_url'    => $result['qr_code_url'],
            'secret'         => $result['secret'],
            'recovery_codes' => $result['recovery_codes'],
        ]);
    }

    /**
     * Disable two-factor authentication for the authenticated user.
     *
     * POST /api/v1/auth/two-factor/disable
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6']);
        $user = Auth::user();

        if (! $this->twoFactorService->verifyCode($user, $request->code)) {
            return response()->json(['message' => 'Invalid 2FA code.'], 422);
        }

        $this->twoFactorService->disable($user);

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    /**
     * Verify a TOTP code during the 2FA login step.
     *
     * POST /api/v1/auth/two-factor/verify
     *
     * @param  Request  $request
     * @return JsonResponse  Full JWT token on success
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'partial_token' => 'required|string',
            'code'          => 'required|string|min:6|max:8',
        ]);

        $result = $this->twoFactorService->completeTwoFactorLogin(
            $request->partial_token,
            $request->code
        );

        if (! $result) {
            return response()->json(['message' => 'Invalid 2FA code or expired session.'], 422);
        }

        return $this->respondWithToken($result['token'], $result['user']);
    }

    // -------------------------------------------------------------------------
    // Social Login
    // -------------------------------------------------------------------------

    /**
     * Authenticate via Google OAuth token.
     *
     * POST /api/v1/auth/social/google
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function socialLoginGoogle(Request $request): JsonResponse
    {
        $request->validate(['id_token' => 'required|string']);
        $result = $this->authService->handleSocialLogin('google', $request->id_token);

        return $this->respondWithToken($result['token'], $result['user']);
    }

    /**
     * Authenticate via Facebook OAuth token.
     *
     * POST /api/v1/auth/social/facebook
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function socialLoginFacebook(Request $request): JsonResponse
    {
        $request->validate(['access_token' => 'required|string']);
        $result = $this->authService->handleSocialLogin('facebook', $request->access_token);

        return $this->respondWithToken($result['token'], $result['user']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the standard token response payload.
     */
    private function respondWithToken(string $token, User $user): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
            'user'         => $user->only(['id', 'name', 'email', 'role', 'avatar_url', 'created_at']),
        ]);
    }
}
