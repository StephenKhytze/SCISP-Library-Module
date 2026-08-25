<?php

namespace App\Services\Contracts;

interface AuthServiceInterface
{
    /**
     * Validate the provided authentication token (e.g., JWT).
     *
     * @param string $token
     * @return array|null Returns the decoded payload (must contain user_id and role) or null if invalid.
     */
    public function validateToken(string $token): ?array;
}
