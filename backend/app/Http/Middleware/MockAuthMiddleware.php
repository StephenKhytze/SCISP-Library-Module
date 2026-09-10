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

        $username = $request->header('X-Mock-Username');

        if (! $username) {
            return response()->json([
                'message' => 'Unauthorized. Missing X-Mock-Username header.',
            ], 401);
        }

        // Map frontend role string to database enum
        $dbRole = 'student';
        $normalizedRole = strtolower($role);
        if (str_contains($normalizedRole, 'admin')) {
            $dbRole = 'administrator';
        } elseif (str_contains($normalizedRole, 'faculty') || str_contains($normalizedRole, 'teacher')) {
            $dbRole = 'faculty';
        }

        // Identity must already exist. Mock auth resolves users; it never creates them.
        // Seed personas with: php artisan db:seed --class=MockPersonaSeeder
        $userModel = \App\Models\User::where('username', $username)->first();

        if (! $userModel) {
            return response()->json([
                'message' => 'Unauthorized. Unknown user.',
            ], 401);
        }

        if ($userModel->status !== 'active') {
            return response()->json([
                'message' => 'Forbidden. User account is disabled.',
            ], 403);
        }

        // The supplied role must correspond to the stored role, compared at the mapped
        // database-role level. Admin and Super Admin both map to 'administrator'; the raw
        // header is preserved below so route gates can still tell them apart.
        if ($userModel->role !== $dbRole) {
            return response()->json([
                'message' => 'Forbidden. Supplied role does not match the user account.',
            ], 403);
        }

        // Pass the role and user_id down to the controllers
        $request->attributes->set('role', $role);
        $request->attributes->set('user_id', $userModel->user_id);

        return $next($request);
    }
}
