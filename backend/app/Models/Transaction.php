<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = ['user_id', 'copy_id', 'date_borrowed', 'due_date', 'actual_return_date', 'status'];
    use HasFactory;

    protected $primaryKey = 'transaction_id';

    /**
     * Overdue state is DERIVED at read time, never stored.
     * Fines are only written to users.total_fines on check-in.
     */
    protected $appends = ['is_overdue', 'days_overdue', 'estimated_fine', 'renewal_status'];

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'active'
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function getDaysOverdueAttribute(): int
    {
        if (! $this->is_overdue) {
            return 0;
        }

        return app(\App\Services\FinesCalculator::class)
            ->overdueDays($this->due_date, now(), $this->borrowerRoleForFines());
    }

    /** What the fine WOULD be if returned right now. Not persisted. */
    public function getEstimatedFineAttribute(): float
    {
        if (! $this->is_overdue) {
            return 0.00;
        }

        return app(\App\Services\FinesCalculator::class)
            ->calculateFine($this->due_date, now(), $this->borrowerRoleForFines());
    }

    /**
     * The borrower's role decides the rate, grace and cap — so an estimate
     * shown to a faculty member matches what they would actually be charged.
     */
    protected function borrowerRoleForFines(): string
    {
        $role = $this->relationLoaded('user') ? $this->getRelation('user')?->role : null;

        // Avoid a query per row when `user` was not eager-loaded; the student
        // policy is the default everywhere else too.
        return $role
            ? app(\App\Services\CirculationService::class)->normalizeRole($role)
            : 'student';
    }

    /**
     * What the borrower should be shown about renewing this loan:
     * null (nothing asked yet), 'pending', 'approved' or 'denied'.
     *
     * Derived from the latest request so the borrowing screen needs no extra
     * call. Eager-load `latestRenewalRequest` to keep this off the N+1 path.
     */
    public function getRenewalStatusAttribute(): ?string
    {
        $latest = $this->relationLoaded('latestRenewalRequest')
            ? $this->getRelation('latestRenewalRequest')
            : $this->latestRenewalRequest()->first();

        return $latest?->status;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_borrowed' => 'datetime',
            'due_date' => 'datetime',
            'actual_return_date' => 'datetime',
        ];
    }

    /**
     * Get the user that made the transaction.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the book copy for the transaction.
     */
    public function bookCopy()
    {
        return $this->belongsTo(BookCopy::class, 'copy_id', 'copy_id');
    }

    public function renewalRequests()
    {
        return $this->hasMany(RenewalRequest::class, 'transaction_id', 'transaction_id');
    }

    /** The most recent request, which is the one the borrower cares about. */
    public function latestRenewalRequest()
    {
        return $this->hasOne(RenewalRequest::class, 'transaction_id', 'transaction_id')
            ->latestOfMany('renewal_request_id');
    }
}
