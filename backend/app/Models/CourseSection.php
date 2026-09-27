<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseSection extends Model
{
    use HasFactory;

    protected $primaryKey = 'section_id';

    protected $fillable = [
        'teacher_id',
        'name',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id', 'user_id');
    }

    public function students()
    {
        return $this->hasMany(CourseSectionStudent::class, 'section_id', 'section_id');
    }

    public function reserves()
    {
        return $this->hasMany(CourseReserve::class, 'section_id', 'section_id');
    }
}
