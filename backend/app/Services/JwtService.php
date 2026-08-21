<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class JwtService
{
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    public static function generateToken(User $user, array $customClaims = []): string
    {
        $privateKeyPath = storage_path('jwt-private.pem');
        
        if (!file_exists($privateKeyPath)) {
            throw new \Exception("Private key not found at {$privateKeyPath}. Please run the setup commands to generate JWT RSA keys.");
        }

        $privateKeyContent = file_get_contents($privateKeyPath);
        $privateKey = openssl_pkey_get_private($privateKeyContent);
        
        if (!$privateKey) {
            throw new \Exception("Invalid private key. Unable to parse the key content.");
        }

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        $now = time();
        $payload = array_merge([
            'iss' => config('app.url', 'http://localhost'),
            'sub' => $user->user_id,
            'username' => $user->username,
            'iat' => $now,
            'exp' => $now + 3600 // Valid for 1 hour
        ], $customClaims);

        $base64Header = self::base64UrlEncode(json_encode($header));
        $base64Payload = self::base64UrlEncode(json_encode($payload));

        $signatureInput = $base64Header . '.' . $base64Payload;

        $signature = '';
        if (!openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \Exception("Failed to sign JWT with RSA private key.");
        }

        return $signatureInput . '.' . self::base64UrlEncode($signature);
    }

    public static function validateToken(string $token): ?array
    {
        $publicKeyPath = storage_path('jwt-public.pem');

        if (!file_exists($publicKeyPath)) {
            Log::error("Public key not found at {$publicKeyPath}");
            return null;
        }

        $publicKeyContent = file_get_contents($publicKeyPath);
        $publicKey = openssl_pkey_get_public($publicKeyContent);

        if (!$publicKey) {
            Log::error("Invalid public key. Unable to parse key content.");
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($base64Header, $base64Payload, $base64Signature) = $parts;
        $signatureInput = $base64Header . '.' . $base64Payload;
        $signature = self::base64UrlDecode($base64Signature);

        $verification = openssl_verify($signatureInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verification !== 1) {
            Log::warning("JWT signature verification failed.");
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($base64Payload), true);

        if (!$payload) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            Log::warning("JWT has expired. Expired at: " . date('Y-m-d H:i:s', $payload['exp']));
            return null;
        }

        return $payload;
    }
}
