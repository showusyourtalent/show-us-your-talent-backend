<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use App\Models\Edition;
use App\Models\Candidature;
use App\Models\Vote;
use App\Models\Payment;
use App\Http\Resources\CandidatResource;
use App\Http\Resources\CategorieResource;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;


class AdminController extends Controller{

    // Gestion des utilisateurs
    public function getUsers(Request $request){
        $query = User::query();

        // Filtres
        if ($request->has('type_compte')) {
            $query->where('type_compte', $request->type_compte);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%$search%")
                  ->orWhere('prenoms', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%");
            });
        }

        $users = $query->with('roles')->paginate(20);

        return UserResource::collection($users);
    }

    public function createUser(Request $request){
        $request->validate([
            'nom' => 'required|string|max:100',
            'prenoms' => 'required|string|max:200',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8',
            'type_compte' => 'required|in:admin,promoteur,candidat',
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $user = User::create([
            'nom' => $request->nom,
            'prenoms' => $request->prenoms,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'type_compte' => $request->type_compte,
        ]);

        $user->assignRole($request->roles);

        return response()->json([
            'message' => 'Utilisateur créé avec succès.',
            'user' => new UserResource($user->load('roles'))
        ], 201);
    }

    public function updateUser(Request $request, $id){
        $user = User::findOrFail($id);

        $request->validate([
            'nom' => 'sometimes|string|max:100',
            'prenoms' => 'sometimes|string|max:200',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'type_compte' => 'sometimes|in:admin,promoteur,candidat',
            'compte_actif' => 'sometimes|boolean',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $user->update($request->only(['nom', 'prenoms', 'email', 'type_compte', 'compte_actif']));

        if ($request->has('roles')) {
            $user->syncRoles($request->roles);
        }

        return response()->json([
            'message' => 'Utilisateur mis à jour avec succès.',
            'user' => new UserResource($user->load('roles'))
        ]);
    }

    public function deleteUser($id){
        $user = User::findOrFail($id);

        // Empêcher la suppression de l'admin principal
        if ($user->email === 'admin@showusyourtalent.com') {
            return response()->json(['message' => 'Impossible de supprimer l\'admin principal.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Utilisateur supprimé avec succès.']);
    }

    // Gestion des rôles
    public function getRoles(){
        $roles = Role::with('permissions')->get();
        return response()->json($roles);
    }

    // Statistiques
    public function getStatistics(){
        $stats = [
            'total_users' => User::count(),
            'total_admins' => User::where('type_compte', 'admin')->count(),
            'total_promoteurs' => User::where('type_compte', 'promoteur')->count(),
            'total_candidats' => User::where('type_compte', 'candidat')->count(),
            'active_candidats' => User::where('type_compte', 'candidat')->where('compte_actif', true)->count(),
            'recent_users' => User::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Récupérer les candidats de l'édition active
     */
    public function getCandidatsEditionActive(Request $request){
        try {
            // Trouver l'édition active la plus récente
            $edition = Edition::where('statut', 'active')
                ->where(function($query) {
                    $query->where('statut_votes', 'en_cours')
                        ->orWhere('statut_votes', 'en_attente')
                        ->orWhere('statut_votes', 'termine');
                })
                ->latest()
                ->first();
            
            if (!$edition) {
                return response()->json([
                    'success' => true,
                    'message' => 'Aucune édition disponible pour le moment',
                    'edition' => null,
                    'categories' => [],
                    'statistiques' => [
                        'total_votes' => 0,
                        'total_candidats' => 0,
                        'total_votes_today' => 0,
                        'date_dernier_vote' => null
                    ]
                ], 200);
            }
            
            // Mettre à jour le statut des votes si nécessaire
            if (method_exists($edition, 'mettreAJourStatutVotes')) {
                $edition->mettreAJourStatutVotes();
            }
            
            if ($edition->isDirty(['votes_ouverts', 'statut_votes'])) {
                $edition->saveQuietly();
            }
            
            // Récupérer toutes les catégories avec leurs candidatures et candidats
            $categories = Category::where('edition_id', $edition->id)
                ->where('active', true)
                ->with(['candidatures' => function($query) use ($edition) {
                    $query->where('edition_id', $edition->id)
                        ->where('statut', 'validee')
                        ->with(['candidat' => function($q) {
                            // Utilisez uniquement les colonnes qui existent dans la table users
                            $q->select('id', 'nom', 'prenoms', 'sexe', 'photo_url', 'ethnie', 
                                    'universite', 'filiere');
                        }])
                        ->withCount(['votes' => function($q) use ($edition) {
                            $q->where('edition_id', $edition->id);
                        }]);
                }])
                ->orderBy('ordre_affichage')
                ->get();
            
            // ---- NOUVELLE LOGIQUE AVANT LE RETOUR ----
            // Récupérer tous les paiements approuvés pour cette édition
            $paiements = Payment::where('edition_id', $edition->id)
                ->where('status', 'approved')
                ->get();
            
            // Parcourir chaque paiement et mettre à jour la colonne fees
            foreach ($paiements as $paiement) {
                // Calculer les fees (montant / 100)
                $fees = intval($paiement->montant / 100);
                
                // Mettre à jour la colonne fees si elle existe
                if (Schema::hasColumn('payments', 'fees')) {
                    $paiement->fees = $fees;
                    $paiement->saveQuietly();
                }
            }
            
            // Maintenant, pour chaque candidature, calculer la somme des fees
            // en groupant par candidat, édition et catégorie
            foreach ($categories as $categorie) {
                foreach ($categorie->candidatures as $candidature) {
                    // Récupérer les candidatures liées à ce candidat pour cette édition et catégorie
                    $candidaturesCategorie = Candidature::where('candidat_id', $candidature->candidat_id)
                        ->where('edition_id', $edition->id)
                        ->where('categorie_id', $categorie->id)
                        ->where('statut', 'validee')
                        ->get();
                    
                    // Pour chaque candidature, calculer la somme des fees
                    foreach ($candidaturesCategorie as $candidatureCat) {
                        // Récupérer tous les paiements pour cette candidature
                        $paiementsCandidature = Payment::where('candidature_id', $candidatureCat->id)
                            ->where('edition_id', $edition->id)
                            ->where('status', 'approved')
                            ->get();
                        
                        // Calculer la somme des fees
                        $sommeFees = $paiementsCandidature->sum('fees');
                        
                        // Mettre à jour la colonne nombre_votes dans la table candidatures
                        $candidatureCat->nombre_votes = $sommeFees;
                        $candidatureCat->saveQuietly();
                    }
                }
            }
            
            // Recharger les candidatures avec les nouveaux votes
            $categories->each(function($categorie) use ($edition) {
                $categorie->load(['candidatures' => function($query) use ($edition) {
                    $query->where('edition_id', $edition->id)
                        ->where('statut', 'validee')
                        ->with(['candidat' => function($q) {
                            $q->select('id', 'nom', 'prenoms', 'sexe', 'photo_url', 'ethnie', 
                                    'universite', 'filiere');
                        }]);
                }]);
            });
            
            // Recalculer les votes_count pour chaque candidature
            foreach ($categories as $categorie) {
                foreach ($categorie->candidatures as $candidature) {
                    // Utiliser directement nombre_votes que nous venons de mettre à jour
                    $candidature->votes_count = $candidature->nombre_votes ?? 0;
                }
            }
            // ---- FIN DE LA NOUVELLE LOGIQUE ----
            
            // Calculer les statistiques (MAINTENANT AVEC LES FEES)
            $totalVotes = Payment::where('edition_id', $edition->id)
                                ->where('status', 'approved')
                                ->sum('fees');
            
            // Compter le nombre total de candidats uniques
            $totalCandidats = Candidature::where('edition_id', $edition->id)
                ->where('statut', 'validée')
                ->distinct('candidat_id')
                ->count('candidat_id');
            
            // Votes d'aujourd'hui (avec fees)
            $totalVotesToday = Payment::where('edition_id', $edition->id)
                                    ->where('status', 'approved')
                                    ->whereDate('created_at', Carbon::today())
                                    ->sum('fees');
            
            // Calcul du temps restant
            $now = Carbon::now();
            $tempsRestant = null;
            
            if ($edition->statut_votes === 'en_cours' && $edition->date_fin_votes) {
                $finVotes = Carbon::parse($edition->date_fin_votes);
                
                if ($now->lt($finVotes)) {
                    $diff = $now->diff($finVotes);
                    
                    $tempsRestant = [
                        'jours' => $diff->days,
                        'heures' => $diff->h,
                        'minutes' => $diff->i,
                        'secondes' => $diff->s,
                        'total_secondes' => $now->diffInSeconds($finVotes),
                        'message' => 'Fin des votes dans'
                    ];
                }
            } 
            elseif ($edition->statut_votes === 'en_attente' && $edition->date_debut_votes) {
                $debutVotes = Carbon::parse($edition->date_debut_votes);
                
                if ($now->lt($debutVotes)) {
                    $diff = $now->diff($debutVotes);
                    
                    $tempsRestant = [
                        'jours' => $diff->days,
                        'heures' => $diff->h,
                        'minutes' => $diff->i,
                        'secondes' => $diff->s,
                        'total_secondes' => $now->diffInSeconds($debutVotes),
                        'message' => 'Début des votes dans'
                    ];
                }
            }
            
            // Vérifier si l'utilisateur a déjà voté
            $userVotes = [];
            if ($request->user()) {
                $votesUtilisateur = Payment::where('edition_id', $edition->id)
                    ->where('user_id', $request->user()->id)
                    ->pluck('candidature_id')
                    ->toArray();
                
                foreach ($categories as $categorie) {
                    foreach ($categorie->candidatures as $candidature) {
                        if (in_array($candidature->id, $votesUtilisateur)) {
                            $userVotes[$candidature->candidat_id] = true;
                        }
                    }
                }
            }
            
            // Préparer les données de l'édition
            $editionData = [
                'id' => $edition->id,
                'nom' => $edition->nom,
                'annee' => $edition->annee,
                'numero_edition' => $edition->numero_edition,
                'statut_votes' => $edition->statut_votes,
                'votes_ouverts' => $edition->votes_ouverts,
                'inscriptions_ouvertes' => $edition->inscriptions_ouvertes,
                'date_debut_votes' => $edition->date_debut_votes,
                'date_fin_votes' => $edition->date_fin_votes,
                'date_debut_inscriptions' => $edition->date_debut_inscriptions,
                'date_fin_inscriptions' => $edition->date_fin_inscriptions,
                'peut_voter' => $edition->peut_voter ?? false,
                'temps_restant' => $tempsRestant,
            ];
            
            // Préparer les catégories avec leurs candidats
            $categoriesData = $categories->map(function($categorie) use ($userVotes) {
                // Trier les candidatures par nombre de votes (descendant)
                $candidaturesTriees = $categorie->candidatures->sortByDesc('nombre_votes');
                
                $candidatsAvecVotes = $candidaturesTriees->map(function($candidature) use ($categorie, $userVotes) {
                    if (!$candidature->candidat) return null;
                    
                    // Notez que video_url vient de la table candidatures, pas de users
                    return [
                        'id' => $candidature->candidat->id,
                        'candidature_id' => $candidature->id,
                        'nom' => $candidature->candidat->nom,
                        'prenoms' => $candidature->candidat->prenoms,
                        'nom_complet' => $candidature->candidat->prenoms . ' ' . $candidature->candidat->nom,
                        'sexe' => $candidature->candidat->sexe,
                        'photo_url' => $candidature->candidat->photo_url,
                        'photo' => $candidature->candidat->photo_url, // Alias pour compatibilité
                        'ethnie' => $candidature->candidat->ethnie,
                        'universite' => $candidature->candidat->universite,
                        'filiere' => $candidature->candidat->filiere,
                        'video_url' => $candidature->video_url, // Important: vient de candidatures
                        'nombre_votes' => $candidature->nombre_votes ?? 0,
                        'a_deja_vote' => $userVotes[$candidature->candidat_id] ?? false,
                        'categorie_id' => $candidature->categorie_id,
                        'categorie_nom' => $categorie->nom,
                    ];
                })
                ->filter()
                ->values();
                
                return [
                    'id' => $categorie->id,
                    'nom' => $categorie->nom,
                    'description' => $categorie->description,
                    'ordre_affichage' => $categorie->ordre_affichage,
                    'candidats' => $candidatsAvecVotes,
                    'total_votes_categorie' => $candidatsAvecVotes->sum('nombre_votes'),
                    'total_candidats_categorie' => $candidatsAvecVotes->count(),
                ];
            });
            
            return response()->json([
                'success' => true,
                'message' => $edition->statut_votes === 'en_cours' 
                    ? 'Édition en cours de vote' 
                    : ($edition->statut_votes === 'en_attente' 
                        ? 'Votes en attente' 
                        : 'Informations sur l\'édition'),
                'edition' => $editionData,
                'statistiques' => [
                    'total_votes' => $totalVotes,
                    'total_candidats' => $totalCandidats,
                    'total_categories' => $categories->count(),
                    'total_votes_today' => $totalVotesToday,
                    'date_dernier_vote' => Payment::where('edition_id', $edition->id)
                        ->where('status', 'approved')
                        ->latest()
                        ->first()
                        ?->created_at
                        ?->format('Y-m-d H:i:s')
                ],
                'categories' => $categoriesData,
                'user_votes' => $userVotes,
                'user_authenticated' => $request->user() ? true : false,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération candidats edition active: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur lors de la récupération des candidats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer un candidat spécifique
     */
    public function show($id)
    {
        try {
            $candidat = User::where('type_compte', 'candidat')
                ->with(['candidatures' => function($query) {
                    $query->with('category')
                          ->with('edition')
                          ->withCount('votes');
                }])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'candidat' => $candidat
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération candidat: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Candidat non trouvé'
            ], 404);
        }
    }

    /**
     * Récupérer les candidats par catégorie
     */
    public function getCandidatsByCategory($categoryId){
        try {
            $candidatures = Candidature::where('categorie_id', $categoryId)
                ->whereHas('edition', function($query) {
                    $query->where('statut', 'active');
                })
                ->where('statut', 'validée')
                ->with(['candidat' => function($query) {
                    $query->select('id', 'nom', 'prenoms', 'sexe', 'photo_url', 'ethnie', 
                                 'universite', 'filiere');
                }])
                ->withCount('votes')
                ->orderByDesc('votes_count')
                ->get();

            $candidats = $candidatures->map(function($candidature) {
                return [
                    'id' => $candidature->candidat->id,
                    'nom' => $candidature->candidat->nom,
                    'prenoms' => $candidature->candidat->prenoms,
                    'nom_complet' => $candidature->candidat->prenoms . ' ' . $candidature->candidat->nom,
                    'sexe' => $candidature->candidat->sexe,
                    'photo_url' => $candidature->candidat->photo_url,
                    'ethnie' => $candidature->candidat->ethnie,
                    'universite' => $candidature->candidat->universite,
                    'filiere' => $candidature->candidat->filiere,
                    'video_url' => $candidature->video_url,
                    'nombre_votes' => $candidature->votes_count,
                    'candidature_id' => $candidature->id,
                    'categorie_id' => $candidature->categorie_id,
                ];
            });

            return response()->json([
                'success' => true,
                'candidats' => $candidats
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération candidats par catégorie: ' . e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des candidats'
            ], 500);
        }
    }

    /**
     * Rechercher des candidats
     */
    public function search(Request $request)
    {
        try {
            $searchTerm = $request->get('q', '');
            
            if (empty($searchTerm)) {
                return response()->json([
                    'success' => true,
                    'candidats' => []
                ]);
            }

            $candidats = User::where('type_compte', 'candidat')
                ->where(function($query) use ($searchTerm) {
                    $query->where('nom', 'LIKE', "%{$searchTerm}%")
                          ->orWhere('prenoms', 'LIKE', "%{$searchTerm}%")
                          ->orWhere('universite', 'LIKE', "%{$searchTerm}%")
                          ->orWhere('filiere', 'LIKE', "%{$searchTerm}%");
                })
                ->whereHas('candidatures', function($query) {
                    $query->whereHas('edition', function($q) {
                        $q->where('statut', 'active');
                    })
                    ->where('statut', 'validée');
                })
                ->with(['candidatures' => function($query) {
                    $query->whereHas('edition', function($q) {
                        $q->where('statut', 'active');
                    })
                    ->with('category')
                    ->withCount('votes');
                }])
                ->limit(20)
                ->get();

            $result = $candidats->map(function($candidat) {
                $candidatureActive = $candidat->candidatures->first();
                
                return [
                    'id' => $candidat->id,
                    'nom' => $candidat->nom,
                    'prenoms' => $candidat->prenoms,
                    'nom_complet' => $candidat->prenoms . ' ' . $candidat->nom,
                    'sexe' => $candidat->sexe,
                    'photo_url' => $candidat->photo_url,
                    'ethnie' => $candidat->ethnie,
                    'universite' => $candidat->universite,
                    'filiere' => $candidat->filiere,
                    'video_url' => $candidatureActive ? $candidatureActive->video_url : null,
                    'nombre_votes' => $candidatureActive ? $candidatureActive->votes_count : 0,
                    'categorie_nom' => $candidatureActive ? $candidatureActive->category->nom : null,
                ];
            });

            return response()->json([
                'success' => true,
                'candidats' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur recherche candidats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche'
            ], 500);
        }
    }
    
    /**
     * Voter pour un candidat
     */
    public function voter(Request $request){
        try {
            $validated = $request->validate([
                'candidat_id' => 'required|exists:candidats,id',
                'categorie_id' => 'required|exists:categories,id',
            ]);
            
            // Vérifier l'édition active
            $edition = Edition::where('statut', 'active')
                ->where('statut_votes', 'en_cours')
                ->first();
            
            if (!$edition) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune édition en cours de vote'
                ], 400);
            }
            
            // Vérifier si les votes sont ouverts
            $now = Carbon::now();
            if ($edition->date_debut_votes && $now->lt(Carbon::parse($edition->date_debut_votes))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les votes ne sont pas encore ouverts'
                ], 400);
            }
            
            if ($edition->date_fin_votes && $now->gt(Carbon::parse($edition->date_fin_votes))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les votes sont terminés'
                ], 400);
            }
            
            // Vérifier si le candidat appartient à la catégorie dans cette édition
            $candidature = Candidature::where('edition_id', $edition->id)
                ->where('candidat_id', $validated['candidat_id'])
                ->where('categorie_id', $validated['categorie_id'])
                ->first();
            
            if (!$candidature) {
                return response()->json([
                    'success' => false,
                    'message' => 'Candidat non trouvé dans cette catégorie'
                ], 404);
            }
            
            // Vérifier si l'utilisateur a déjà voté dans cette catégorie
            $voteExistant = Vote::where('edition_id', $edition->id)
                ->where('categorie_id', $validated['categorie_id'])
                ->where('user_id', $request->user()->id)
                ->first();
            
            if ($voteExistant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous avez déjà voté dans cette catégorie'
                ], 400);
            }
            
            // Créer le vote
            $vote = Vote::create([
                'user_id' => $request->user()->id,
                'candidat_id' => $validated['candidat_id'],
                'edition_id' => $edition->id,
                'categorie_id' => $validated['categorie_id'],
                'date_vote' => $now,
            ]);
            
            // Mettre à jour le compteur de votes du candidat
            $candidat = Candidature::find($validated['candidat_id']);
            $candidat->increment('total_votes');
            
            return response()->json([
                'success' => true,
                'message' => 'Vote enregistré avec succès',
                'vote' => $vote,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Erreur vote: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du vote'
            ], 500);
        }
    }
    
    /**
     * Vérifier si l'utilisateur a déjà voté pour un candidat
     */
    public function checkVote(Request $request, $candidatId){
        try {
            $edition = Edition::where('statut', 'active')
                ->where('statut_votes', 'en_cours')
                ->first();
            
            if (!$edition) {
                return response()->json([
                    'has_voted' => false
                ]);
            }
            
            // Vérifier si l'utilisateur a voté pour ce candidat dans cette édition
            $vote = Vote::where('edition_id', $edition->id)
                ->where('candidat_id', $candidatId)
                ->where('user_id', $request->user()->id)
                ->first();
            
            return response()->json([
                'has_voted' => $vote !== null,
                'vote' => $vote
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'has_voted' => false
            ]);
        }
    }
    
    /**
     * Récupérer les statistiques de vote
     */
    public function getStatistiquesVote(Request $request)
    {
        try {
            $edition = Edition::where('statut', 'active')
                ->first();
            
            if (!$edition) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune édition active'
                ], 404);
            }
            
            $totalVotes = Vote::where('edition_id', $edition->id)->count();
            $votesParCategorie = Vote::where('edition_id', $edition->id)
                ->select('categorie_id', DB::raw('count(*) as total'))
                ->groupBy('categorie_id')
                ->get();
            
            // Top 5 candidats
            $topCandidats = Vote::where('edition_id', $edition->id)
                ->select('candidat_id', DB::raw('count(*) as votes'))
                ->groupBy('candidat_id')
                ->orderBy('votes', 'desc')
                ->limit(5)
                ->with('candidat')
                ->get();
            
            return response()->json([
                'success' => true,
                'statistiques' => [
                    'total_votes' => $totalVotes,
                    'votes_par_categorie' => $votesParCategorie,
                    'top_candidats' => $topCandidats,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Erreur statistiques vote: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }
    
    /**
     * Récupérer les résultats publics
     */
    public function getResultatsPublic($editionId){
        try {
            $edition = Edition::findOrFail($editionId);
            
            $categories = Category::where('edition_id', $edition->id)
                ->where('active', true)
                ->with(['candidatures.candidat' => function($query) use ($edition) {
                    $query->withCount(['votes' => function($query) use ($edition) {
                        $query->where('edition_id', $edition->id);
                    }])
                    ->orderBy('votes_count', 'desc');
                }])
                ->orderBy('ordre_affichage')
                ->get();
            
            $totalVotes = Vote::where('edition_id', $edition->id)->count();
            
            return response()->json([
                'success' => true,
                'edition' => [
                    'id' => $edition->id,
                    'nom' => $edition->nom,
                    'annee' => $edition->annee,
                    'statut_votes' => $edition->statut_votes,
                ],
                'statistiques' => [
                    'total_votes' => $totalVotes,
                ],
                'categories' => $categories->map(function($categorie) {
                    $candidats = $categorie->candidatures->map(function($candidature) {
                        if (!$candidature->candidat) return null;
                        
                        return [
                            'id' => $candidature->candidat->id,
                            'nom_complet' => $candidature->candidat->nom_complet,
                            'sexe' => $candidature->candidat->sexe,
                            'photo' => $candidature->candidat->photo_url,
                            'ethnie' => $candidature->candidat->ethnie,
                            'universite' => $candidature->candidat->universite,
                            'entite' => $candidature->candidat->entite,
                            'filiere' => $candidature->candidat->filiere,
                            'nombre_votes' => $candidature->candidat->votes_count ?? 0,
                            'pourcentage' => 0, // Calculé côté client
                        ];
                    })->filter()->values();
                    
                    return [
                        'id' => $categorie->id,
                        'nom' => $categorie->nom,
                        'candidats' => $candidats,
                    ];
                }),
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Erreur résultats publics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }
}