<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;

class Fine extends Model
{
    protected $fillable = ['ref_id', 'user_id', 'book_title', 'days_late', 'amount', 'status'];
    use HasFactory;

    protected $primaryKey = 'fine_id';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
