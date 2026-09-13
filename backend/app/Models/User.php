<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * `is_super_admin` is deliberately NOT fillable.
     *
     * MockAuthMiddleware trusts this flag to decide whether a "Super Admin"
     * header is genuine, and `users` is shared with the rest of SCISP — a
     * module doing User::create($request->all()) would otherwise let a caller
     * grant themselves the flag. Set it with forceFill() from trusted code.
     */
    protected $fillable = ['username', 'password', 'role', 'status', 'total_fines'];

    protected $primaryKey = 'user_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Super Admins are management-only in the Library: they run the desk but
     * may not borrow. Admins are ordinary librarians and may still borrow.
     *
     * Both store role = 'administrator', so this flag is the only thing that
     * tells them apart when the user is the BORROWER rather than the caller.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /** May this user be selected as a borrower at all? */
    public function canBorrow(): bool
    {
        return ! $this->isSuperAdmin();
    }

    /**
     * Get the transactions associated with the user.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'user_id', 'user_id');
    }

    /**
     * Get the holds associated with the user.
     */
    public function holds()
    {
        return $this->hasMany(Hold::class, 'user_id', 'user_id');
    }
}
