<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\JwtService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class VerifyJwtToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorizationHeader = $request->header('Authorization');

        if (!$authorizationHeader || !str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json([
                'message' => 'Unauthorized. Authorization Bearer token is required.'
            ], 401);
        }

        $token = substr($authorizationHeader, 7);
        $payload = JwtService::validateToken($token);

        if (!$payload) {
            return response()->json([
                'message' => 'Unauthorized. Invalid or expired token.'
            ], 401);
        }

        // Retrieve user from sub claim
        $user = User::find($payload['sub']);

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized. User not found.'
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Your account has been disabled.'
            ], 403);
        }

        // Log the user in for the current request context
        Auth::setUser($user);

        return $next($request);
    }
}
