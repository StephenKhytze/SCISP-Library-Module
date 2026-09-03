<?php

namespace App\Services\Fakes;

use App\Services\Contracts\AuthServiceInterface;

class FakeAuthService implements AuthServiceInterface
{
    public function validateToken(string $token): ?array
    {
        // For fake auth mode, we can parse a simple json or base64 token
        // In local development, we might just pass a token like "student:12345"
        
        $parts = explode(':', $token);
        
        if (count($parts) === 2) {
            $role = $parts[0]; // e.g., 'student', 'faculty', 'administrator', 'superadmin'
            $userId = $parts[1]; // e.g., '12345'
            
            return [
                'user_id' => $userId,
                'role' => $role,
            ];
        }

        // Alternatively, if it's a real-looking token but we are in fake mode, 
        // we could just decode it without verifying the signature:
        // $payload = explode('.', $token)[1] ?? null;
        // if ($payload) {
        //     return json_decode(base64_decode($payload), true);
        // }

        return null;
    }
}
