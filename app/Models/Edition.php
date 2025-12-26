<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Edition extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nom',
        'annee',
        'numero_edition',
        'description',
        'statut',
        'inscriptions_ouvertes',
        'date_debut_inscriptions',
        'date_fin_inscriptions',
        'votes_ouverts',
        'date_debut_votes',
        'date_fin_votes',
        'statut_votes',
        'promoteur_id'
    ];

    protected $casts = [
        'inscriptions_ouvertes' => 'boolean',
        'votes_ouverts' => 'boolean',
        'date_debut_inscriptions' => 'datetime',
        'date_fin_inscriptions' => 'datetime',
        'date_debut_votes' => 'datetime',
        'date_fin_votes' => 'datetime',
    ];

    // Attributs supplémentaires
    protected $appends = [
        'votes_ouverts_auto',
        'statut_votes_auto',
        'temps_restant_votes',
        'est_active',
        'peut_voter'
    ];

    // Événements du modèle SIMPLIFIÉS
    protected static function boot()
    {
        parent::boot();

        // Mettre à jour automatiquement votes_ouverts et statut_votes seulement lors de la sauvegarde
        static::saving(function ($edition) {
            $edition->mettreAJourStatutVotes();
        });
    }

    // Méthode pour mettre à jour automatiquement le statut des votes
    public function mettreAJourStatutVotes()
    {
        $now = Carbon::now();
        
        // Logique de mise à jour automatique basée sur les dates
        if ($this->statut === 'active') {
            // Si nous sommes dans la période de votes
            if ($this->date_debut_votes && $this->date_fin_votes) {
                if ($now->between($this->date_debut_votes, $this->date_fin_votes)) {
                    // Période de votes active
                    $this->votes_ouverts = true;
                    $this->statut_votes = 'en_cours';
                } 
                elseif ($now->lessThan($this->date_debut_votes)) {
                    // Votes pas encore commencés
                    $this->votes_ouverts = false;
                    $this->statut_votes = 'en_attente';
                }
                elseif ($now->greaterThan($this->date_fin_votes)) {
                    // Votes terminés
                    $this->votes_ouverts = false;
                    $this->statut_votes = 'termine';
                }
            } else {
                // Pas de dates de vote définies
                $this->votes_ouverts = false;
                $this->statut_votes = 'en_attente';
            }
            
            // Si les inscriptions sont encore ouvertes, on ne peut pas voter
            if ($this->inscriptions_ouvertes && $this->statut_votes === 'en_cours') {
                $this->votes_ouverts = false;
                $this->statut_votes = 'suspendu';
            }
        } else {
            // Édition inactive
            $this->votes_ouverts = false;
            $this->statut_votes = 'inactif';
        }
    }

    // Accesseur pour votes_ouverts basé sur les dates (calculé à la volée)
    public function getVotesOuvertsAutoAttribute()
    {
        if ($this->statut !== 'active') {
            return false;
        }

        $now = Carbon::now();
        
        // Vérifier si nous sommes dans la période de votes
        if (!$this->date_debut_votes || !$this->date_fin_votes) {
            return false;
        }

        $votesOuverts = $now->between($this->date_debut_votes, $this->date_fin_votes);
        
        // Les votes ne sont pas ouverts si les inscriptions le sont
        if ($this->inscriptions_ouvertes) {
            return false;
        }
        
        return $votesOuverts;
    }

    // Accesseur pour statut_votes basé sur les dates (calculé à la volée)
    public function getStatutVotesAutoAttribute()
    {
        if ($this->statut !== 'active') {
            return 'inactif';
        }

        $now = Carbon::now();
        
        if (!$this->date_debut_votes || !$this->date_fin_votes) {
            return 'non_configuré';
        }

        if ($now->lessThan($this->date_debut_votes)) {
            return 'en_attente';
        }
        
        if ($now->between($this->date_debut_votes, $this->date_fin_votes)) {
            return $this->inscriptions_ouvertes ? 'suspendu' : 'en_cours';
        }
        
        if ($now->greaterThan($this->date_fin_votes)) {
            return 'termine';
        }
        
        return $this->statut_votes;
    }

    // Accesseur pour le temps restant avant les votes
    public function getTempsRestantVotesAttribute()
    {
        $now = Carbon::now();
        
        if (!$this->date_debut_votes) {
            return null;
        }
        
        if ($now->greaterThanOrEqualTo($this->date_debut_votes)) {
            return null; // Déjà commencé
        }
        
        $diffInSeconds = $now->diffInSeconds($this->date_debut_votes);
        $jours = floor($diffInSeconds / (24 * 3600));
        $reste = $diffInSeconds % (24 * 3600);
        $heures = floor($reste / 3600);
        $reste = $reste % 3600;
        $minutes = floor($reste / 60);
        $secondes = $reste % 60;
        
        return [
            'jours' => $jours,
            'heures' => $heures,
            'minutes' => $minutes,
            'secondes' => $secondes,
            'total_secondes' => $diffInSeconds,
        ];
    }

    // Accesseur pour vérifier si on peut voter
    public function getPeutVoterAttribute()
    {
        return $this->votes_ouverts_auto && $this->statut_votes_auto === 'en_cours';
    }

    // Accesseur pour vérifier si l'édition est active
    public function getEstActiveAttribute()
    {
        return $this->statut === 'active';
    }

    // Méthode pour forcer la mise à jour du statut
    public function actualiserStatutVotes()
    {
        $oldVotesOuverts = $this->votes_ouverts;
        $oldStatutVotes = $this->statut_votes;
        
        $this->mettreAJourStatutVotes();
        
        if ($oldVotesOuverts !== $this->votes_ouverts || $oldStatutVotes !== $this->statut_votes) {
            $this->save();
        }
        
        return $this;
    }

    // Scopes et relations (inchangés)
    public function scopeActive($query)
    {
        return $query->where('statut', 'active');
    }

    public function scopeAvecVotesOuverts($query)
    {
        return $query->where('votes_ouverts', true)
                    ->where('statut_votes', 'en_cours')
                    ->where('date_debut_votes', '<=', now())
                    ->where('date_fin_votes', '>=', now());
    }

    public function scopeEnCoursDeVote($query)
    {
        return $query->where(function($q) {
            $q->where('statut_votes', 'en_cours')
              ->orWhere(function($query) {
                  $query->where('statut_votes', 'en_attente')
                        ->where('inscriptions_ouvertes', false);
              });
        });
    }

    public function promoteur()
    {
        return $this->belongsTo(User::class, 'promoteur_id');
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function candidatures()
    {
        return $this->hasMany(Candidature::class);
    }

    public function phases()
    {
        return $this->hasMany(EditionPhase::class);
    }

    public function partenaires()
    {
        return $this->hasMany(Partenaire::class);
    }

    public function scopeInscriptionsOuvertes($query)
    {
        return $query->where('inscriptions_ouvertes', true)
                    ->where('date_debut_inscriptions', '<=', now())
                    ->where('date_fin_inscriptions', '>=', now());
    }

    // Nouvelle méthode pour vérifier si les votes sont ouverts
    public function getVotesOuvertsAttribute()
    {
        if ($this->statut_votes !== 'en_cours') {
            return false;
        }

        $now = now();
        return $now->between($this->date_debut_votes, $this->date_fin_votes);
    }

    // Méthode pour vérifier si on peut configurer les votes
    public function getPeutConfigurerVotesAttribute()
    {
        return $this->statut === 'active' && 
               in_array($this->statut_votes, ['en_attente', 'suspendu', 'termine']);
    }

    // Méthode pour vérifier si on peut démarrer les votes
    public function getPeutDemarrerVotesAttribute()
    {
        if (!$this->est_active) return false;
        
        return $this->statut_votes === 'en_attente' && 
               $this->date_debut_votes && 
               $this->date_fin_votes &&
               now()->lessThan($this->date_debut_votes);
    }

    // Méthode pour vérifier si on peut suspendre les votes
    public function getPeutSuspendreVotesAttribute()
    {
        return $this->est_active && $this->statut_votes === 'en_cours';
    }

    // Méthode pour vérifier si on peut relancer les votes
    public function getPeutRelancerVotesAttribute()
    {
        return $this->est_active && $this->statut_votes === 'suspendu';
    }

    // Méthode pour vérifier si on peut terminer les votes
    public function getPeutTerminerVotesAttribute()
    {
        return $this->est_active && 
               ($this->statut_votes === 'en_cours' || $this->statut_votes === 'suspendu') &&
               now()->greaterThanOrEqualTo($this->date_fin_votes);
    }

    public function isVoteOpen(): bool
    {
        // Vérifier d'abord le statut explicite
        if ($this->statut_votes === 'en_cours') {
            return true;
        }

        if ($this->statut_votes === 'en_attente') {
            return false;
        }

        if ($this->statut_votes === 'termine') {
            return false;
        }

        // Vérifier les dates si pas de statut explicite
        $now = Carbon::now();
        
        if (!$this->date_debut || !$this->date_fin) {
            return false;
        }
        
        return $now->between($this->date_debut, $this->date_fin);
    }

    public function getTempsRestantAttribute()
    {
        if (!$this->date_fin) {
            return null;
        }

        $now = Carbon::now();
        $end = Carbon::parse($this->date_fin);
        
        if ($now > $end) {
            return [
                'jours' => 0,
                'heures' => 0,
                'minutes' => 0,
                'secondes' => 0,
                'total_secondes' => 0
            ];
        }

        $diff = $now->diff($end);
        
        return [
            'jours' => $diff->days,
            'heures' => $diff->h,
            'minutes' => $diff->i,
            'secondes' => $diff->s,
            'total_secondes' => $now->diffInSeconds($end)
        ];
    }


    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}