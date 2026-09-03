<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Contracts\AuthServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ExternalAuthMiddleware
{
    protected $authService;

    public function __construct(AuthServiceInterface $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // For testing/fake mode, we can read a custom header
        $token = $request->bearerToken() ?? $request->header('X-Test-Token');

        if (!$token) {
            return response()->json(['message' => 'Unauthorized. Missing token.'], 401);
        }

        $payload = $this->authService->validateToken($token);

        if (!$payload || !isset($payload['user_id'])) {
            return response()->json(['message' => 'Unauthorized. Invalid token.'], 401);
        }

        // Find or create the lightweight user in our database
        $user = User::firstOrCreate(
            ['user_id' => $payload['user_id']],
            [
                'total_fines' => 0,
                // The template still required these fields, so we fill them with defaults
                'username' => 'user_' . $payload['user_id'],
                'password' => bcrypt(\Illuminate\Support\Str::random(16)),
                'role' => $payload['role'] ?? 'student',
                'status' => 'active',
            ]
        );

        // We attach the extracted role to the request or auth instance so we don't 
        // trust the DB role if it's outdated (as per prompt, role comes from JWT)
        $request->attributes->set('role', $payload['role'] ?? 'student');
        
        Auth::setUser($user);

        return $next($request);
    }
}
