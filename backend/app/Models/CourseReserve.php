<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;

class CourseReserve extends Model
{
    use HasFactory;

    protected $primaryKey = 'reserve_id';

    protected $fillable = [
        'section_id',
        'book_id',
        'user_id',
        'copies_requested',
        'target_group',
        'status',
        'teacher_to_admin_note',
        'admin_to_teacher_note',
        'teacher_to_student_note'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function section()
    {
        return $this->belongsTo(CourseSection::class, 'section_id', 'section_id');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id', 'book_id');
    }

    public function copies()
    {
        return $this->hasMany(BookCopy::class, 'reserve_id', 'reserve_id');
    }
}
