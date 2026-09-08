<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MockAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $role = $request->header('X-Mock-Role');

        if (!$role) {
            return response()->json([
                'message' => 'Unauthorized. Missing X-Mock-Role header.',
            ], 401);
        }

        // If specific roles are required (e.g. for Admin routes)
        if (!empty($roles)) {
            // Trim and normalize both the passed roles and the header role
            $allowedRoles = array_map('strtolower', $roles);
            if (!in_array(strtolower($role), $allowedRoles)) {
                return response()->json([
                    'message' => 'Forbidden. Insufficient role privileges.',
                ], 403);
            }
        }

        $username = $request->header('X-Mock-Username', '2012-00000-SYS');

        // Map frontend role string to database enum
        $dbRole = 'student';
        $normalizedRole = strtolower($role);
        if (str_contains($normalizedRole, 'admin')) {
            $dbRole = 'administrator';
        } elseif (str_contains($normalizedRole, 'faculty') || str_contains($normalizedRole, 'teacher')) {
            $dbRole = 'faculty';
        }

        // Find or create user to get a valid user_id
        $userModel = \App\Models\User::firstOrCreate(
            ['username' => $username],
            ['role' => $dbRole, 'password' => bcrypt('password'), 'status' => 'active']
        );

        // Pass the role and user_id down to the controllers
        $request->attributes->set('role', $role);
        $request->attributes->set('user_id', $userModel->user_id);

        return $next($request);
    }
}
