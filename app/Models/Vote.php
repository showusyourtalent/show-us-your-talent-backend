<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // Ajoutez cette ligne
use Spatie\Permission\Traits\HasRoles;

class Vote extends Model
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes; 

    protected $fillable = [
        'edition_id',
        'candidat_id',
        'votant_id',
        'categorie_id',
        'candidature_id',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
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
        return $this->belongsTo(Category::class, 'categorie_id');
    }

    public function candidature()
    {
        return $this->belongsTo(Candidature::class);
    }

    // Scopes
    public function scopeForEdition($query, $editionId)
    {
        return $query->where('edition_id', $editionId);
    }

    public function scopeForCategorie($query, $categorieId)
    {
        return $query->where('categorie_id', $categorieId);
    }

    public function scopeByVotant($query, $votantId)
    {
        return $query->where('votant_id', $votantId);
    }

    public function scopeForCandidat($query, $candidatId)
    {
        return $query->where('candidat_id', $candidatId);
    }
}