<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseSectionStudent extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'student_id',
        'group_name',
    ];

    public function section()
    {
        return $this->belongsTo(CourseSection::class, 'section_id', 'section_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id', 'user_id');
    }
}
