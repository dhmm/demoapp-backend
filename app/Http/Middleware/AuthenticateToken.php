<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;

/**
 * AuthenticateToken Middleware
 *
 * Validates the JWT Bearer token on every protected request.
 * Returns structured JSON error responses for all failure modes.
 *
 * Usage: Route::middleware('auth:api') or applied via route groups.
 */
class AuthenticateToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return $this->unauthorizedResponse('User not found.');
            }

            if ($user->status === 'banned') {
                return $this->forbiddenResponse('Your account has been suspended. Please contact support.');
            }

            if (! $user->hasVerifiedEmail()) {
                return $this->forbiddenResponse('Please verify your email address before accessing this resource.');
            }

        } catch (TokenExpiredException $e) {
            return $this->unauthorizedResponse('Token has expired.', 'TOKEN_EXPIRED');

        } catch (TokenBlacklistedException $e) {
            return $this->unauthorizedResponse('Token has been invalidated.', 'TOKEN_BLACKLISTED');

        } catch (TokenInvalidException $e) {
            return $this->unauthorizedResponse('Token is invalid.', 'TOKEN_INVALID');

        } catch (JWTException $e) {
            return $this->unauthorizedResponse('Token not provided.', 'TOKEN_MISSING');
        }

        return $next($request);
    }

    /**
     * Build a 401 Unauthorized JSON response.
     */
    private function unauthorizedResponse(string $message, string $code = 'UNAUTHORIZED'): JsonResponse
    {
        return response()->json([
            'error'   => true,
            'code'    => $code,
            'message' => $message,
        ], 401);
    }

    /**
     * Build a 403 Forbidden JSON response.
     */
    private function forbiddenResponse(string $message, string $code = 'FORBIDDEN'): JsonResponse
    {
        return response()->json([
            'error'   => true,
            'code'    => $code,
            'message' => $message,
        ], 403);
    }
}
