<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_room_id',
        'user_id',
        'role',
        'last_seen_at',
        'is_muted'
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_muted' => 'boolean'
    ];

    // Relations
    public function room()
    {
        return $this->belongsTo(ChatRoom::class, 'chat_room_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopePromoteurs($query)
    {
        return $query->where('role', 'promoteur');
    }

    public function scopeCandidats($query)
    {
        return $query->where('role', 'candidat');
    }

    public function scopeActive($query)
    {
        return $query->whereHas('room', function($q) {
            $q->where('status', 'active');
        });
    }

    // Method pour marquer comme vu
    public function markAsSeen()
    {
        $this->update(['last_seen_at' => now()]);
        return $this;
    }
}