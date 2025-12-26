<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatRoom extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'category_id',
        'edition_id',
        'status'
    ];

    protected $casts = [
        'metadata' => 'array'
    ];

    // Relations
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function edition()
    {
        return $this->belongsTo(Edition::class);
    }

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'chat_room_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'chat_room_id')->latest();
    }

    public function unreadMessages()
    {
        return $this->hasMany(ChatMessage::class, 'chat_room_id')->where('is_read', false);
    }

    public function promoteurs()
    {
        return $this->participants()->where('role', 'promoteur');
    }

    public function candidats()
    {
        return $this->participants()->where('role', 'candidat');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForEdition($query, $editionId)
    {
        return $query->where('edition_id', $editionId);
    }

    public function scopeForCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeWhereHasParticipant($query, $userId)
    {
        return $query->whereHas('participants', function($q) use ($userId) {
            $q->where('user_id', $userId);
        });
    }

    // Methods
    public function addParticipant($userId, $role = 'candidat')
    {
        return ChatParticipant::create([
            'chat_room_id' => $this->id,
            'user_id' => $userId,
            'role' => $role
        ]);
    }

    public function hasParticipant($userId)
    {
        return $this->participants()->where('user_id', $userId)->exists();
    }

    public function getUnreadCountForUser($userId)
    {
        $participant = $this->participants()->where('user_id', $userId)->first();
        
        if (!$participant || !$participant->last_seen_at) {
            return $this->messages()
                ->where('user_id', '!=', $userId)
                ->count();
        }

        return $this->messages()
            ->where('user_id', '!=', $userId)
            ->where('created_at', '>', $participant->last_seen_at)
            ->count();
    }

    public function lastMessage()
    {
        return $this->hasOne(ChatMessage::class, 'chat_room_id')->latestOfMany();
    }
}