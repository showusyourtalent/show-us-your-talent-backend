<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    use HasFactory;

    protected $fillable = [
        'edition_id',
        'candidat_id',
        'votant_id',
        'categorie_id',
        'candidature_id',
        'payment_id',
        'is_paid',
        'vote_price',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'vote_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relations
    public function edition()
    {
        return $this->belongsTo(Edition::class);
    }

    public function candidat()
    {
        return $this->belongsTo(User::class, 'candidat_id');
    }

    public function votant()
    {
        return $this->belongsTo(User::class, 'votant_id');
    }

    public function categorie()
    {
        return $this->belongsTo(Category::class);
    }

    public function candidature()
    {
        return $this->belongsTo(Candidature::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    // Scopes
    public function scopePaid($query)
    {
        return $query->where('is_paid', true);
    }

    public function scopeFree($query)
    {
        return $query->where('is_paid', false);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    // Méthode pour créer un vote avec paiement
    public static function createPaidVote(array $data, Payment $payment): self
    {
        return self::create(array_merge($data, [
            'payment_id' => $payment->id,
            'is_paid' => true,
            'vote_price' => $payment->amount
        ]));
    }
}