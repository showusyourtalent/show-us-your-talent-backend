<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'user_id',
        'edition_id',
        'candidat_id',
        'category_id',
        'transaction_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'payment_token',
        'customer_email',
        'customer_phone',
        'customer_firstname',
        'customer_lastname',
        'fees',
        'net_amount',
        'metadata',
        'paid_at',
        'expires_at',
        'montant',
        'email_payeur',
        'edition_id',
        'candidat_id',
        'category_id',
        'transaction_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (empty($payment->reference)) {
                $payment->reference = 'PAY-' . strtoupper(uniqid());
            }
            if (empty($payment->payment_token)) {
                $payment->payment_token = hash('sha256', uniqid('vote_', true));
            }
        });
    }

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function edition()
    {
        return $this->belongsTo(Edition::class);
    }

    public function candidat()
    {
        return $this->belongsTo(User::class, 'candidat_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['canceled', 'declined', 'expired']);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', now()->month)
                     ->whereYear('created_at', now()->year);
    }

    // Méthodes utilitaires
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'approved';
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['canceled', 'declined', 'expired']);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function markAsPaid($method = null): void
    {
        $this->update([
            'status' => 'approved',
            'payment_method' => $method ?? $this->payment_method,
            'paid_at' => now()
        ]);
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 0, ',', ' ') . ' ' . $this->currency;
    }

    public function getPaymentUrlAttribute(): ?string
    {
        if (!$this->transaction_id) {
            return null;
        }

        return route('payment.process', $this->payment_token);
    }
}