<?php

namespace App\Services\Contracts;

interface ProfileServiceInterface
{
    /**
     * Get a student's profile information.
     *
     * @param string $studentId
     * @return array|null Returns the profile data or null if not found.
     */
    public function getStudentProfile(string $studentId): ?array;

    /**
     * Get a faculty member's profile information.
     *
     * @param string $facultyId
     * @return array|null Returns the profile data or null if not found.
     */
    public function getFacultyProfile(string $facultyId): ?array;
}
