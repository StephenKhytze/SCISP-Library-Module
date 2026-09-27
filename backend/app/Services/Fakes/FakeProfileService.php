<?php

namespace App\Services\Fakes;

use App\Services\Contracts\ProfileServiceInterface;

class FakeProfileService implements ProfileServiceInterface
{
    public function getStudentProfile(string $studentId): ?array
    {
        // Fake student data
        return [
            'id' => $studentId,
            'name' => 'Fake Student ' . substr($studentId, 0, 4),
            'department' => 'BS Computer Science',
            'status' => 'active',
            'email' => "student{$studentId}@scisp.edu.ph",
        ];
    }

    public function getFacultyProfile(string $facultyId): ?array
    {
        // Fake faculty data
        return [
            'id' => $facultyId,
            'name' => 'Dr. Fake Faculty ' . substr($facultyId, 0, 4),
            'department' => 'College of Computer Studies',
            'status' => 'active',
            'email' => "faculty{$facultyId}@scisp.edu.ph",
        ];
    }
}
