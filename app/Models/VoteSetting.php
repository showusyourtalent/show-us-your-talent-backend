<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'edition_id',
        'category_id',
        'vote_price',
        'is_paid',
        'free_votes_per_user',
        'max_votes_per_candidat',
        'max_votes_per_user',
        'vote_start',
        'vote_end',
        'allow_mobile_money',
        'allow_card',
        'allow_bank_transfer'
    ];

    protected $casts = [
        'vote_price' => 'decimal:2',
        'is_paid' => 'boolean',
        'allow_mobile_money' => 'boolean',
        'allow_card' => 'boolean',
        'allow_bank_transfer' => 'boolean',
        'vote_start' => 'datetime',
        'vote_end' => 'datetime'
    ];

    // Relations
    public function edition()
    {
        return $this->belongsTo(Edition::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // Méthodes utilitaires
    public function isVotePeriodActive(): bool
    {
        $now = now();
        
        if ($this->vote_start && $now->lt($this->vote_start)) {
            return false;
        }
        
        if ($this->vote_end && $now->gt($this->vote_end)) {
            return false;
        }
        
        return true;
    }

    public function getAvailablePaymentMethods(): array
    {
        $methods = [];
        
        if ($this->allow_mobile_money) {
            $methods[] = 'mobile_money';
        }
        
        if ($this->allow_card) {
            $methods[] = 'card';
        }
        
        if ($this->allow_bank_transfer) {
            $methods[] = 'bank_transfer';
        }
        
        return $methods;
    }

    public function canUserVoteForFree(User $user): bool
    {
        if ($this->free_votes_per_user <= 0) {
            return false;
        }
        
        $freeVotesCount = Vote::where('votant_id', $user->id)
            ->where('edition_id', $this->edition_id)
            ->where('is_paid', false)
            ->count();
            
        return $freeVotesCount < $this->free_votes_per_user;
    }

    public function getUserRemainingFreeVotes(User $user): int
    {
        if ($this->free_votes_per_user <= 0) {
            return 0;
        }
        
        $freeVotesCount = Vote::where('votant_id', $user->id)
            ->where('edition_id', $this->edition_id)
            ->where('is_paid', false)
            ->count();
            
        return max(0, $this->free_votes_per_user - $freeVotesCount);
    }
}