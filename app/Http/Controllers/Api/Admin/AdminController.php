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
     * Récupérer les candidats de l'édition active - VERSION CORRIGÉE ET OPTIMISÉE
     */
    public function getCandidatsEditionActive(Request $request)
    {
        // Pas de transaction pour les lectures uniquement - évite les erreurs de transaction abortée
        try {
            // 1. Trouver l'édition active avec une requête optimisée
            $edition = Edition::select('id', 'nom', 'annee', 'numero_edition', 'statut_votes', 
                                     'votes_ouverts', 'inscriptions_ouvertes', 'date_debut_votes', 
                                     'date_fin_votes', 'date_debut_inscriptions', 'date_fin_inscriptions')
                ->where('statut', 'active')
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
            
            // 2. Récupérer les catégories
            $categories = Category::select('id', 'nom', 'description', 'ordre_affichage')
                ->where('edition_id', $edition->id)
                ->where('active', true)
                ->orderBy('ordre_affichage')
                ->get();
            
            // 3. Récupérer les candidatures valides en une seule requête
            $candidatures = Candidature::select('id', 'candidat_id', 'category_id', 'video_url', 'nombre_votes')
                ->where('edition_id', $edition->id)
                ->where('statut', 'validee')
                ->whereIn('category_id', $categories->pluck('id'))
                ->with(['candidat:id,nom,prenoms,sexe,photo_url,ethnie,universite,filiere'])
                ->orderBy('nombre_votes', 'desc')
                ->get()
                ->groupBy('category_id');
            
            // 4. Calculer les statistiques (requêtes séparées pour plus de clarté)
            $stats = $this->calculateStatistics($edition->id);
            
            // 5. Calculer les votes de l'utilisateur
            $userVotes = $request->user() 
                ? $this->getUserVotes($edition->id, $request->user()->id)
                : [];
            
            // 6. Préparer les données de l'édition
            $editionData = $this->prepareEditionData($edition);
            
            // 7. Préparer les données des catégories
            $categoriesData = $categories->map(function($category) use ($candidatures, $userVotes) {
                $categoryCandidatures = $candidatures->get($category->id, collect());
                
                $candidats = $categoryCandidatures->map(function($candidature) use ($category, $userVotes) {
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
            
            return response()->json([
                'success' => true,
                'message' => $this->getEditionMessage($edition->statut_votes),
                'edition' => $editionData,
                'statistiques' => $stats,
                'categories' => $categoriesData,
                'user_votes' => $userVotes,
                'user_authenticated' => $request->user() ? true : false,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération candidats edition active: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur lors de la récupération des candidats'
            ], 500);
        }
    }
    
    /**
     * Calculer les statistiques - VERSION CORRIGÉE
     */
    private function calculateStatistics($editionId)
    {
        try {
            // Utiliser des requêtes séparées pour éviter les problèmes de transaction
            
            // Total des votes (payments approved)
            $totalVotes = Payment::where('edition_id', $editionId)
                ->where('status', 'approved')
                ->sum(DB::raw('COALESCE(fees, amount / 100)'));
            
            // Nombre de candidats uniques
            $totalCandidats = Candidature::where('edition_id', $editionId)
                ->where('statut', 'validee')
                ->distinct('candidat_id')
                ->count('candidat_id');
            
            // Votes d'aujourd'hui
            $today = Carbon::today();
            $totalVotesToday = Payment::where('edition_id', $editionId)
                ->where('status', 'approved')
                ->whereDate('created_at', $today)
                ->sum(DB::raw('COALESCE(fees, amount / 100)'));
            
            // Date du dernier vote
            $lastVote = Payment::where('edition_id', $editionId)
                ->where('status', 'approved')
                ->latest('created_at')
                ->value('created_at');
            
            // Nombre de catégories actives
            $totalCategories = Category::where('edition_id', $editionId)
                ->where('active', true)
                ->count();
            
            return [
                'total_votes' => (int)$totalVotes,
                'total_candidats' => (int)$totalCandidats,
                'total_categories' => (int)$totalCategories,
                'total_votes_today' => (int)$totalVotesToday,
                'date_dernier_vote' => $lastVote 
                    ? Carbon::parse($lastVote)->format('Y-m-d H:i:s') 
                    : null
            ];
            
        } catch (\Exception $e) {
            Log::error('Erreur calcul statistiques: ' . $e->getMessage());
            return [
                'total_votes' => 0,
                'total_candidats' => 0,
                'total_categories' => 0,
                'total_votes_today' => 0,
                'date_dernier_vote' => null
            ];
        }
    }
    
    /**
     * Récupérer les votes de l'utilisateur - VERSION CORRIGÉE
     */
    private function getUserVotes($editionId, $userId)
    {
        try {
            $votes = Payment::select('candidat_id')
                ->where('edition_id', $editionId)
                ->where('user_id', $userId)
                ->where('status', 'approved')
                ->distinct()
                ->pluck('candidat_id')
                ->toArray();
            
            return array_fill_keys($votes, true);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération votes utilisateur: ' . $e->getMessage());
            return [];
        }
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

    /**
     * Gestion des utilisateurs avec transactions robustes
     */
    public function getUsers(Request $request){
        try {
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
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération utilisateurs: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la récupération des utilisateurs'
            ], 500);
        }
    }

    /**
     * Créer un utilisateur avec transaction sécurisée
     */
    public function createUser(Request $request){
        DB::beginTransaction();
        try {
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

            DB::commit();

            return response()->json([
                'message' => 'Utilisateur créé avec succès.',
                'user' => new UserResource($user->load('roles'))
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création utilisateur: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la création de l\'utilisateur'
            ], 500);
        }
    }

    /**
     * Mettre à jour un utilisateur avec transaction sécurisée
     */
    public function updateUser(Request $request, $id){
        DB::beginTransaction();
        try {
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

            DB::commit();

            return response()->json([
                'message' => 'Utilisateur mis à jour avec succès.',
                'user' => new UserResource($user->load('roles'))
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur mise à jour utilisateur: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la mise à jour de l\'utilisateur'
            ], 500);
        }
    }

    /**
     * Supprimer un utilisateur avec transaction sécurisée
     */
    public function deleteUser($id){
        DB::beginTransaction();
        try {
            $user = User::findOrFail($id);

            // Empêcher la suppression de l'admin principal
            if ($user->email === 'admin@showusyourtalent.com') {
                return response()->json(['message' => 'Impossible de supprimer l\'admin principal.'], 403);
            }

            $user->delete();

            DB::commit();

            return response()->json(['message' => 'Utilisateur supprimé avec succès.']);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Utilisateur non trouvé.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur suppression utilisateur: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la suppression de l\'utilisateur'
            ], 500);
        }
    }

    /**
     * Récupérer les rôles
     */
    public function getRoles(){
        try {
            $roles = Role::with('permissions')->get();
            return response()->json($roles);
        } catch (\Exception $e) {
            Log::error('Erreur récupération rôles: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur serveur'], 500);
        }
    }

    /**
     * Récupérer les statistiques
     */
    public function getStatistics(){
        try {
            $stats = [
                'total_users' => User::count(),
                'total_admins' => User::where('type_compte', 'admin')->count(),
                'total_promoteurs' => User::where('type_compte', 'promoteur')->count(),
                'total_candidats' => User::where('type_compte', 'candidat')->count(),
                'active_candidats' => User::where('type_compte', 'candidat')
                    ->where('compte_actif', true)
                    ->count(),
                'recent_users' => User::where('created_at', '>=', now()->subDays(7))->count(),
            ];

            return response()->json($stats);
        } catch (\Exception $e) {
            Log::error('Erreur statistiques: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur serveur'], 500);
        }
    }

    /**
     * Récupérer un candidat spécifique
     */
    public function show($id)
    {
        try {
            $candidat = User::select('id', 'nom', 'prenoms', 'email', 'sexe', 'photo_url', 
                                   'ethnie', 'universite', 'filiere', 'date_naissance')
                ->where('type_compte', 'candidat')
                ->with(['candidatures' => function($query) {
                    $query->with(['category:id,nom', 'edition:id,nom,annee'])
                          ->withCount('votes');
                }])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'candidat' => $candidat
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Candidat non trouvé'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Erreur récupération candidat: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Récupérer les candidats par catégorie
     */
    public function getCandidatsByCategory($categoryId){
        try {
            $candidatures = Candidature::select('id', 'candidat_id', 'video_url')
                ->where('categorie_id', $categoryId)
                ->whereHas('edition', function($query) {
                    $query->where('statut', 'active');
                })
                ->where('statut', 'validee')
                ->with(['candidat' => function($query) {
                    $query->select('id', 'nom', 'prenoms', 'sexe', 'photo_url', 'ethnie', 
                                 'universite', 'filiere');
                }])
                ->withCount('votes')
                ->orderByDesc('votes_count')
                ->get();

            $candidats = $candidatures->map(function($candidature) {
                if (!$candidature->candidat) return null;
                
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
            })->filter()->values();

            return response()->json([
                'success' => true,
                'candidats' => $candidats
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur récupération candidats par catégorie: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
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

            $candidats = User::select('id', 'nom', 'prenoms', 'sexe', 'photo_url', 'ethnie', 
                                    'universite', 'filiere')
                ->where('type_compte', 'candidat')
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
                    ->where('statut', 'validee');
                })
                ->with(['candidatures' => function($query) {
                    $query->whereHas('edition', function($q) {
                        $q->where('statut', 'active');
                    })
                    ->with('category:id,nom')
                    ->withCount('votes')
                    ->limit(1);
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
                'message' => 'Erreur serveur'
            ], 500);
        }
    }
    
    /**
     * Voter pour un candidat avec transaction robuste
     */
    public function voter(Request $request){
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'candidat_id' => 'required|exists:users,id',
                'categorie_id' => 'required|exists:categories,id',
            ]);
            
            $userId = $request->user()->id;
            
            // Vérifier l'édition active
            $edition = Edition::where('statut', 'active')
                ->where('statut_votes', 'en_cours')
                ->first();
            
            if (!$edition) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune édition en cours de vote'
                ], 400);
            }
            
            // Vérifier si les votes sont ouverts
            $now = Carbon::now();
            if ($edition->date_debut_votes && $now->lt(Carbon::parse($edition->date_debut_votes))) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Les votes ne sont pas encore ouverts'
                ], 400);
            }
            
            if ($edition->date_fin_votes && $now->gt(Carbon::parse($edition->date_fin_votes))) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Les votes sont terminés'
                ], 400);
            }
            
            // Vérifier si le candidat appartient à la catégorie dans cette édition
            $candidature = Candidature::where('edition_id', $edition->id)
                ->where('candidat_id', $validated['candidat_id'])
                ->where('categorie_id', $validated['categorie_id'])
                ->where('statut', 'validee')
                ->first();
            
            if (!$candidature) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Candidat non trouvé dans cette catégorie'
                ], 404);
            }
            
            // Vérifier si l'utilisateur a déjà voté dans cette catégorie
            $voteExistant = Vote::where('edition_id', $edition->id)
                ->where('categorie_id', $validated['categorie_id'])
                ->where('user_id', $userId)
                ->first();
            
            if ($voteExistant) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Vous avez déjà voté dans cette catégorie'
                ], 400);
            }
            
            // Créer le vote
            $vote = Vote::create([
                'user_id' => $userId,
                'candidat_id' => $validated['candidat_id'],
                'edition_id' => $edition->id,
                'categorie_id' => $validated['categorie_id'],
                'date_vote' => $now,
            ]);
            
            // Mettre à jour le compteur de votes du candidat
            $candidature->increment('nombre_votes');
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Vote enregistré avec succès',
                'vote' => $vote,
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur vote: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du vote'
            ], 500);
        }
    }
    
    /**
     * Vérifier si l'utilisateur a déjà voté
     */
    public function checkVote(Request $request, $candidatId){
        try {
            $edition = Edition::where('statut', 'active')
                ->where('statut_votes', 'en_cours')
                ->first();
            
            if (!$edition || !$request->user()) {
                return response()->json([
                    'has_voted' => false
                ]);
            }
            
            $vote = Vote::where('edition_id', $edition->id)
                ->where('candidat_id', $candidatId)
                ->where('user_id', $request->user()->id)
                ->first();
            
            return response()->json([
                'has_voted' => $vote !== null,
                'vote' => $vote
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur check vote: ' . $e->getMessage());
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
                    'success' => true,
                    'statistiques' => [
                        'total_votes' => 0,
                        'votes_par_categorie' => [],
                        'top_candidats' => [],
                    ]
                ]);
            }
            
            // Utiliser des requêtes optimisées
            $totalVotes = Vote::where('edition_id', $edition->id)->count();
            
            $votesParCategorie = Category::select('categories.id', 'categories.nom', 
                DB::raw('COUNT(votes.id) as total'))
                ->leftJoin('votes', function($join) use ($edition) {
                    $join->on('categories.id', '=', 'votes.categorie_id')
                         ->where('votes.edition_id', $edition->id);
                })
                ->where('categories.edition_id', $edition->id)
                ->where('categories.active', true)
                ->groupBy('categories.id', 'categories.nom')
                ->get();
            
            // Top 5 candidats
            $topCandidats = Vote::select('candidat_id', DB::raw('COUNT(*) as votes'))
                ->where('edition_id', $edition->id)
                ->groupBy('candidat_id')
                ->orderBy('votes', 'desc')
                ->limit(5)
                ->with(['candidat:id,nom,prenoms'])
                ->get()
                ->map(function($vote) {
                    return [
                        'candidat_id' => $vote->candidat_id,
                        'votes' => $vote->votes,
                        'candidat' => $vote->candidat ? [
                            'nom_complet' => $vote->candidat->prenoms . ' ' . $vote->candidat->nom
                        ] : null
                    ];
                });
            
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
            $edition = Edition::select('id', 'nom', 'annee', 'statut_votes')
                ->find($editionId);
            
            if (!$edition) {
                return response()->json([
                    'success' => false,
                    'message' => 'Édition non trouvée'
                ], 404);
            }
            
            $categories = Category::select('id', 'nom')
                ->where('edition_id', $edition->id)
                ->where('active', true)
                ->with(['candidatures' => function($query) use ($edition) {
                    $query->where('edition_id', $edition->id)
                        ->where('statut', 'validee')
                        ->with(['candidat:id,nom,prenoms,sexe,photo_url,ethnie,universite,filiere'])
                        ->withCount('votes')
                        ->orderBy('votes_count', 'desc');
                }])
                ->orderBy('ordre_affichage')
                ->get();
            
            $totalVotes = Vote::where('edition_id', $edition->id)->count();
            
            $categoriesData = $categories->map(function($categorie) use ($totalVotes) {
                $candidats = $categorie->candidatures->map(function($candidature) use ($totalVotes) {
                    if (!$candidature->candidat) return null;
                    
                    return [
                        'id' => $candidature->candidat->id,
                        'nom_complet' => $candidature->candidat->prenoms . ' ' . $candidature->candidat->nom,
                        'sexe' => $candidature->candidat->sexe,
                        'photo' => $candidature->candidat->photo_url,
                        'ethnie' => $candidature->candidat->ethnie,
                        'universite' => $candidature->candidat->universite,
                        'filiere' => $candidature->candidat->filiere,
                        'nombre_votes' => $candidature->votes_count ?? 0,
                        'pourcentage' => $totalVotes > 0 
                            ? round(($candidature->votes_count / $totalVotes) * 100, 2) 
                            : 0,
                    ];
                })->filter()->values();
                
                return [
                    'id' => $categorie->id,
                    'nom' => $categorie->nom,
                    'candidats' => $candidats,
                ];
            });
            
            return response()->json([
                'success' => true,
                'edition' => $edition,
                'statistiques' => [
                    'total_votes' => $totalVotes,
                ],
                'categories' => $categoriesData,
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