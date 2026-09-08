<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $fillable = ['user_id', 'book_id', 'copy_id', 'request_date', 'status', 'queue_position'];
    use HasFactory;

    protected $primaryKey = 'hold_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_date' => 'datetime',
        ];
    }

    /**
     * Get the user that placed the hold.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the book the hold is for.
     */
    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id', 'book_id');
    }
}
