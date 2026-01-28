<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'nom',
        'name',
        'prenoms',
        'email',
        'password',
        'telephone',
        'date_naissance',
        'sexe',
        'photo_url',
        'origine',
        'ethnie',
        'universite',
        'filiere',
        'annee_etude',
        'type_compte',
        'compte_actif'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'date_naissance' => 'date',
        'compte_actif' => 'boolean',
    ];
    
    protected $guard_name = 'sanctum';

    // Relations
    public function editionsPromoteur()
    {
        return $this->hasMany(Edition::class, 'promoteur_id');
    }

    public function candidatures()
    {
        return $this->hasMany(Candidature::class, 'candidat_id');
    }

    public function votes()
    {
        return $this->hasMany(Vote::class, 'votant_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    // Scopes
    public function scopeCandidats($query)
    {
        return $query->where('type_compte', 'candidat');
    }

    public function scopePromoteurs($query)
    {
        return $query->where('type_compte', 'promoteur');
    }

    public function scopeAdmins($query)
    {
        return $query->where('type_compte', 'admin');
    }

    // Relation pour les votes donnés (en tant que votant)
    public function votesDonnes()
    {
        return $this->hasMany(Vote::class, 'user_id');
    }

    // Scope pour compter les votes d'une édition spécifique
    public function scopeWithVotesCountForEdition($query, $editionId)
    {
        return $query->withCount(['votes' => function($q) use ($editionId) {
            $q->where('edition_id', $editionId);
        }]);
    }

    // Dans App\Models\User.php
public function activeCandidatures()
{
    return $this->hasMany(Candidature::class, 'candidat_id')
        ->whereHas('edition', function($query) {
            $query->where('statut', 'active');
        })
        ->where('statut', 'validee')
        ->with(['category:id,nom', 'edition:id,nom']);
}

    public function receivedVotes(){
        return $this->hasManyThrough(
            Vote::class,
            Candidature::class,
            'candidat_id', // Clé étrangère sur candidatures
            'candidature_id', // Clé étrangère sur votes
            'id', // Clé locale sur users
            'id' // Clé locale sur candidatures
        );
    }
}