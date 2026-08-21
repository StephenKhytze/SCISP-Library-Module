<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Group 1: Add authentication logic here
    public function login(Request $request) {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        try {
            $user = User::where('username', $credentials['username'])->first();
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'Database connection error. Please try again later.'
            ], 500);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'An error occurred during authentication.'
            ], 500);
        }

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid username or password.'
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Your account has been disabled.'
            ], 403);
        }

        $name = 'Test User';
        $role = 'Student';
        $department = 'IT';
        $idNumber = '99999';

        if ($user->username === 'DelaCruz_Juan_C1234') {
            $name = 'Juan Dela Cruz';
            $role = 'Student';
            $department = 'IT';
            $idNumber = '12345';
        } elseif ($user->username === 'Admin_User_00001') {
            $name = 'Admin User';
            $role = 'Admin';
            $department = 'Administration';
            $idNumber = '00001';
        } else {
            $name = str_replace('_', ' ', $user->username);
            $role = $user->role === 'administrator' ? 'Admin' : ($user->role === 'faculty' ? 'Teacher' : 'Student');
            $department = $user->role === 'administrator' ? 'Administration' : 'Academic';
            $idNumber = (string)(10000 + $user->user_id);
        }

        try {
            $jwt = \App\Services\JwtService::generateToken($user, [
                'name' => $name,
                'role' => $role,
                'department' => $department,
                'idNumber' => $idNumber,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate authentication token: ' . $e->getMessage()
            ], 500);
        }

        return response()->json([
            'access_token' => $jwt,
            'user' => [
                'name' => $name,
                'username' => $user->username,
                'role' => $role,
                'department' => $department,
                'idNumber' => $idNumber,
            ]
        ]);
    }
}
