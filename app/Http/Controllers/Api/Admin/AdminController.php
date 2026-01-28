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
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    /**
     * Récupérer les candidats de l'édition active - VERSION OPTIMISÉE
     */
    public function getCandidatsEditionActive(Request $request)
    {
        DB::beginTransaction();
        try {
            // 1. Trouver l'édition active avec une requête optimisée
            $edition = Edition::where('statut', 'active')
                ->whereIn('statut_votes', ['en_cours', 'en_attente', 'termine'])
                ->latest('created_at')
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
            
            // 2. Récupérer TOUT en une seule requête avec relations optimisées
            $categories = Category::where('edition_id', $edition->id)
                ->where('active', true)
                ->with(['candidatures' => function($query) use ($edition) {
                    // Charger toutes les relations nécessaires en une fois
                    $query->where('edition_id', $edition->id)
                        ->where('statut', 'validee')
                        ->with(['candidat' => function($q) {
                            $q->select('id', 'nom', 'prenoms', 'sexe', 'photo_url', 'ethnie', 
                                     'universite', 'filiere');
                        }])
                        ->orderBy('nombre_votes', 'desc');
                }])
                ->orderBy('ordre_affichage')
                ->get(['id', 'nom', 'description', 'ordre_affichage']);
            
            // 3. Calculer les statistiques AVANT la boucle (une seule requête)
            $stats = $this->calculateStatistics($edition->id);
            
            // 4. Calculer les votes de l'utilisateur (une seule requête)
            $userVotes = $request->user() 
                ? $this->getUserVotes($edition->id, $request->user()->id)
                : [];
            
            // 5. Préparer les données de l'édition (pas de requêtes supplémentaires)
            $editionData = $this->prepareEditionData($edition);
            
            // 6. Préparer les données des catégories (traitement en mémoire)
            $categoriesData = $categories->map(function($category) use ($userVotes) {
                $candidats = $category->candidatures->map(function($candidature) use ($category, $userVotes) {
                    if (!$candidature->candidat) return null;
                    
                    return [
                        'id' => $candidature->candidat->id,
                        'candidature_id' => $candidature->id,
                        'nom' => $candidature->candidat->nom,
                        'prenoms' => $candidature->candidat->prenoms,
                        'nom_complet' => $candidature->candidat->prenoms . ' ' . $candidature->candidat->nom,
                        'sexe' => $candidature->candidat->sexe,
                        'photo_url' => $candidature->candidat->photo_url,
                        'photo' => $candidature->candidat->photo_url,
                        'ethnie' => $candidature->candidat->ethnie,
                        'universite' => $candidature->candidat->universite,
                        'filiere' => $candidature->candidat->filiere,
                        'video_url' => $candidature->video_url,
                        'nombre_votes' => (int)($candidature->nombre_votes ?? 0),
                        'a_deja_vote' => isset($userVotes[$candidature->candidat_id]),
                        'categorie_id' => $candidature->category_id,
                        'categorie_nom' => $category->nom,
                    ];
                })->filter()->values();
                
                $totalVotesCategorie = $candidats->sum('nombre_votes');
                
                return [
                    'id' => $category->id,
                    'nom' => $category->nom,
                    'description' => $category->description,
                    'ordre_affichage' => $category->ordre_affichage,
                    'candidats' => $candidats,
                    'total_votes_categorie' => $totalVotesCategorie,
                    'total_candidats_categorie' => $candidats->count(),
                ];
            });
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => $this->getEditionMessage($edition->statut_votes),
                'edition' => $editionData,
                'statistiques' => $stats,
                'categories' => $categoriesData,
                'user_votes' => $userVotes,
                'user_authenticated' => $request->user() ? true : false,
            ]);
            
        } 
        catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur récupération candidats edition active: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur lors de la récupération des candidats' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Calculer les statistiques - OPTIMISÉ
     */
    private function calculateStatistics($editionId)
    {
        // Une seule requête pour toutes les statistiques
        $result = DB::select("
            SELECT 
                -- Total des votes (fees)
                COALESCE(SUM(
                    CASE 
                        WHEN status = 'approved' AND fees IS NOT NULL 
                        THEN fees 
                        WHEN status = 'approved' AND fees IS NULL 
                        THEN amount / 100 
                        ELSE 0 
                    END
                ), 0) as total_votes,
                
                -- Nombre de candidats uniques
                (SELECT COUNT(DISTINCT candidat_id) 
                 FROM candidatures 
                 WHERE edition_id = ? AND statut = 'validee') as total_candidats,
                
                -- Votes d'aujourd'hui
                COALESCE(SUM(
                    CASE 
                        WHEN status = 'approved' AND DATE(created_at) = CURRENT_DATE AND fees IS NOT NULL 
                        THEN fees 
                        WHEN status = 'approved' AND DATE(created_at) = CURRENT_DATE AND fees IS NULL 
                        THEN amount / 100 
                        ELSE 0 
                    END
                ), 0) as total_votes_today,
                
                -- Date du dernier vote
                MAX(CASE WHEN status = 'approved' THEN created_at END) as date_dernier_vote,
                
                -- Nombre de catégories
                (SELECT COUNT(*) FROM categories WHERE edition_id = ? AND active = true) as total_categories
            FROM payments
            WHERE edition_id = ?
        ", [$editionId, $editionId, $editionId])[0];
        
        return [
            'total_votes' => (int)$result->total_votes,
            'total_candidats' => (int)$result->total_candidats,
            'total_categories' => (int)$result->total_categories,
            'total_votes_today' => (int)$result->total_votes_today,
            'date_dernier_vote' => $result->date_dernier_vote 
                ? Carbon::parse($result->date_dernier_vote)->format('Y-m-d H:i:s') 
                : null
        ];
    }
    
    /**
     * Récupérer les votes de l'utilisateur - OPTIMISÉ
     */
    private function getUserVotes($editionId, $userId)
    {
        // Une seule requête pour tous les votes
        $votes = DB::table('payments')
            ->where('edition_id', $editionId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->pluck('candidat_id')
            ->toArray();
        
        return array_fill_keys($votes, true);
    }
    
    /**
     * Préparer les données de l'édition
     */
    private function prepareEditionData(Edition $edition)
    {
        $now = Carbon::now();
        $tempsRestant = null;
        
        if ($edition->statut_votes === 'en_cours' && $edition->date_fin_votes) {
            $finVotes = Carbon::parse($edition->date_fin_votes);
            
            if ($now->lt($finVotes)) {
                $tempsRestant = $this->calculateTimeRemaining($now, $finVotes, 'Fin des votes dans');
            }
        } 
        elseif ($edition->statut_votes === 'en_attente' && $edition->date_debut_votes) {
            $debutVotes = Carbon::parse($edition->date_debut_votes);
            
            if ($now->lt($debutVotes)) {
                $tempsRestant = $this->calculateTimeRemaining($now, $debutVotes, 'Début des votes dans');
            }
        }
        
        return [
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
    }
    
    /**
     * Calculer le temps restant
     */
    private function calculateTimeRemaining($now, $targetDate, $message)
    {
        $diff = $now->diff($targetDate);
        
        return [
            'jours' => $diff->days,
            'heures' => $diff->h,
            'minutes' => $diff->i,
            'secondes' => $diff->s,
            'total_secondes' => $now->diffInSeconds($targetDate),
            'message' => $message
        ];
    }
    
    /**
     * Obtenir le message selon le statut
     */
    private function getEditionMessage($statutVotes)
    {
        return match($statutVotes) {
            'en_cours' => 'Édition en cours de vote',
            'en_attente' => 'Votes en attente',
            default => 'Informations sur l\'édition'
        };
    }

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
            Log::error('Erreur récupération candidats par catégorie: ');
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
            Log::error('Erreur vote: ' . $e->getMessage());
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
            Log::error('Erreur statistiques vote: ' . $e->getMessage());
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
            Log::error('Erreur résultats publics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }
}