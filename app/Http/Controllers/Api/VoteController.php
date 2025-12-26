<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vote;
use App\Models\VoteSetting;
use App\Models\Candidature;
use App\Models\Edition;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class VoteController extends Controller
{
    /**
     * Voter pour un candidat
     */
    public function vote(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'candidat_id' => 'required|exists:users,id',
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'required|exists:categories,id',
                'votes_count' => 'integer|min:1|max:100|default:1'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $data = $validator->validated();
            $votesCount = $data['votes_count'] ?? 1;

            DB::beginTransaction();

            // Vérifier les paramètres de vote
            $voteSetting = VoteSetting::where('edition_id', $data['edition_id'])
                ->where(function($q) use ($data) {
                    $q->where('category_id', $data['category_id'])
                      ->orWhereNull('category_id');
                })
                ->first();

            if (!$voteSetting) {
                throw new \Exception('Paramètres de vote non configurés pour cette catégorie.');
            }

            if (!$voteSetting->isVotePeriodActive()) {
                throw new \Exception('La période de vote est terminée.');
            }

            // Vérifier les limites
            $this->validateVoteLimits($user->id, $data, $voteSetting, $votesCount);

            // Traiter selon le type de vote
            if ($voteSetting->is_paid) {
                // Vote payant - créer un paiement en attente
                return $this->handlePaidVote($user, $data, $votesCount, $voteSetting, $request);
            } else {
                // Vote gratuit
                return $this->handleFreeVote($user->id, $data, $votesCount, $voteSetting, $request);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erreur vote', [
                'user_id' => auth()->id(),
                'data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Historique des votes
     */
    public function voteHistory(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $perPage = $request->get('per_page', 10);
            
            $votes = Vote::where('votant_id', $user->id)
                ->with(['candidat', 'edition', 'category', 'payment'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $votes
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur historique votes', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique'
            ], 500);
        }
    }

    /**
     * Statistiques de l'utilisateur
     */
    public function getUserStatistics(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $editionId = $request->get('edition_id');

            $query = Vote::where('votant_id', $user->id);
            
            if ($editionId) {
                $query->where('edition_id', $editionId);
            }

            $statistics = [
                'total_votes' => $query->count(),
                'paid_votes' => $query->where('is_paid', true)->count(),
                'free_votes' => $query->where('is_paid', false)->count(),
                'total_amount' => $query->where('is_paid', true)->sum('amount'),
                'votes_today' => $query->whereDate('created_at', Carbon::today())->count(),
                'favorite_category' => $query->select('categorie_id')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('categorie_id')
                    ->orderByDesc('count')
                    ->first(),
                'favorite_candidat' => $query->select('candidat_id')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('candidat_id')
                    ->orderByDesc('count')
                    ->first()
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur statistiques utilisateur', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Obtenir les candidats d'une édition
     */
    public function getCandidats($editionId): JsonResponse
    {
        try {
            $edition = Edition::with(['categories.candidats' => function($query) {
                $query->with(['user', 'votes']);
            }])->findOrFail($editionId);

            $candidats = $edition->categories->map(function($category) {
                return [
                    'category_id' => $category->id,
                    'category_name' => $category->nom,
                    'candidats' => $category->candidats->map(function($candidature) {
                        return [
                            'id' => $candidature->candidat_id,
                            'user' => $candidature->user->only(['id', 'nom', 'prenoms', 'photo_url', 'universite']),
                            'votes_count' => $candidature->nombre_votes,
                            'rank' => $candidature->rank,
                            'video_url' => $candidature->video_url,
                            'description' => $candidature->description
                        ];
                    })->sortByDesc('votes_count')->values()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'edition' => $edition->only(['id', 'nom', 'annee', 'statut_votes']),
                    'categories' => $candidats
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération candidats', [
                'edition_id' => $editionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des candidats'
            ], 500);
        }
    }

    /**
     * Statistiques de l'édition
     */
    public function getEditionStatistics($editionId): JsonResponse
    {
        try {
            $edition = Edition::findOrFail($editionId);

            $statistics = [
                'total_votes' => Vote::where('edition_id', $editionId)->count(),
                'total_candidats' => Candidature::where('edition_id', $editionId)->count(),
                'total_categories' => Category::where('edition_id', $editionId)->count(),
                'total_paid_votes' => Vote::where('edition_id', $editionId)->where('is_paid', true)->count(),
                'total_amount' => Vote::where('edition_id', $editionId)->where('is_paid', true)->sum('amount'),
                'votes_today' => Vote::where('edition_id', $editionId)
                    ->whereDate('created_at', Carbon::today())
                    ->count(),
                'top_candidats' => Vote::where('edition_id', $editionId)
                    ->select('candidat_id')
                    ->selectRaw('COUNT(*) as votes_count')
                    ->groupBy('candidat_id')
                    ->orderByDesc('votes_count')
                    ->limit(5)
                    ->with('candidat.user')
                    ->get(),
                'vote_distribution' => Vote::where('edition_id', $editionId)
                    ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
                    ->whereDate('created_at', Carbon::today())
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->get()
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur statistiques édition', [
                'edition_id' => $editionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Obtenir les catégories
     */
    public function getCategories(Request $request): JsonResponse
    {
        try {
            $editionId = $request->get('edition_id');
            
            $query = Category::query();
            
            if ($editionId) {
                $query->where('edition_id', $editionId);
            }

            $categories = $query->withCount('candidatures')
                ->orderBy('nom')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération catégories', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des catégories'
            ], 500);
        }
    }

    /**
     * Méthodes privées
     */
    private function validateVoteLimits($userId, array $data, VoteSetting $voteSetting, $votesCount): void
    {
        // Limite par utilisateur
        if ($voteSetting->max_votes_per_user) {
            $userVotesCount = Vote::where('votant_id', $userId)
                ->where('edition_id', $data['edition_id'])
                ->count();

            if (($userVotesCount + $votesCount) > $voteSetting->max_votes_per_user) {
                throw new \Exception('Vous avez atteint la limite de votes pour cette édition.');
            }
        }

        // Limite par candidat
        if ($voteSetting->max_votes_per_candidat) {
            $candidatVotesCount = Vote::where('candidat_id', $data['candidat_id'])
                ->where('edition_id', $data['edition_id'])
                ->where('categorie_id', $data['category_id'])
                ->count();

            if (($candidatVotesCount + $votesCount) > $voteSetting->max_votes_per_candidat) {
                throw new \Exception('Ce candidat a atteint la limite de votes pour cette catégorie.');
            }
        }

        // Vérifier si l'utilisateur a déjà voté pour ce candidat dans cette catégorie
        if (!$voteSetting->allow_multiple_votes) {
            $alreadyVoted = Vote::where('votant_id', $userId)
                ->where('candidat_id', $data['candidat_id'])
                ->where('edition_id', $data['edition_id'])
                ->where('categorie_id', $data['category_id'])
                ->exists();

            if ($alreadyVoted) {
                throw new \Exception('Vous avez déjà voté pour ce candidat dans cette catégorie.');
            }
        }
    }

    private function handlePaidVote($user, array $data, $votesCount, VoteSetting $voteSetting, Request $request): JsonResponse
    {
        // Vérifier les informations de paiement
        if (!$user->email || !$user->telephone) {
            throw new \Exception('Veuillez compléter vos informations de contact (email et téléphone) avant de voter.');
        }

        // Créer une demande de paiement
        $paymentData = [
            'candidat_id' => $data['candidat_id'],
            'edition_id' => $data['edition_id'],
            'category_id' => $data['category_id'],
            'votes_count' => $votesCount,
            'email' => $user->email,
            'phone' => $user->telephone,
            'firstname' => $user->prenoms,
            'lastname' => $user->nom
        ];

        // Appeler le contrôleur de paiement
        $paymentController = new PaymentController();
        $paymentResponse = $paymentController->initiatePayment(new Request($paymentData));

        if (!$paymentResponse->getData()->success) {
            throw new \Exception($paymentResponse->getData()->message ?? 'Erreur lors de la création du paiement');
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Redirection vers le paiement',
            'data' => [
                'payment_token' => $paymentResponse->getData()->data->payment_token,
                'amount' => $paymentResponse->getData()->data->amount,
                'votes_count' => $votesCount,
                'redirect_url' => route('payment.process'),
                'next_step' => 'process_payment'
            ]
        ]);
    }

    private function handleFreeVote($userId, array $data, $votesCount, VoteSetting $voteSetting, Request $request): JsonResponse
    {
        // Vérifier les votes gratuits disponibles
        if (!$voteSetting->canUserVoteForFree(User::find($userId))) {
            throw new \Exception('Vous avez utilisé tous vos votes gratuits pour cette catégorie.');
        }

        // Créer les votes gratuits
        for ($i = 0; $i < $votesCount; $i++) {
            Vote::create([
                'edition_id' => $data['edition_id'],
                'candidat_id' => $data['candidat_id'],
                'votant_id' => $userId,
                'categorie_id' => $data['category_id'],
                'candidature_id' => Candidature::where('candidat_id', $data['candidat_id'])
                    ->where('edition_id', $data['edition_id'])
                    ->where('category_id', $data['category_id'])
                    ->value('id'),
                'is_paid' => false,
                'amount' => 0,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
        }

        // Mettre à jour le compteur de votes
        $candidature = Candidature::where('candidat_id', $data['candidat_id'])
            ->where('edition_id', $data['edition_id'])
            ->where('category_id', $data['category_id'])
            ->first();

        if ($candidature) {
            $candidature->increment('nombre_votes', $votesCount);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Vote(s) enregistré(s) avec succès',
            'data' => [
                'votes_count' => $votesCount,
                'free_votes_remaining' => $voteSetting->getUserRemainingFreeVotes(User::find($userId)),
                'candidat_votes' => $candidature->nombre_votes
            ]
        ]);

    }


    // Enregistrer un vote
    public function store(Request $request)
    {
        $request->validate([
            'candidature_id' => 'required|exists:candidatures,id',
            'categorie_id' => 'required|exists:categories,id',
            'edition_id' => 'required|exists:editions,id',
            'is_paid' => 'boolean',
            'vote_price' => 'numeric|min:0',
        ]);
        
        $user = Auth::user();
        
        // Récupérer la candidature
        $candidature = Candidature::find($request->candidature_id);
        
        // Vérifier si l'utilisateur a déjà voté pour cette candidature aujourd'hui
        $hasVotedToday = Vote::where('votant_id', $user->id)
            ->where('candidature_id', $request->candidature_id)
            ->whereDate('created_at', today())
            ->exists();
            
        if ($hasVotedToday) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà voté pour ce candidat aujourd\'hui'
            ], 400);
        }
        
        // Créer le vote
        $vote = Vote::create([
            'edition_id' => $request->edition_id,
            'candidat_id' => $candidature->candidat_id,
            'votant_id' => $user->id,
            'categorie_id' => $request->categorie_id,
            'candidature_id' => $request->candidature_id,
            'is_paid' => $request->is_paid ?? false,
            'vote_price' => $request->vote_price ?? 0,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        
        // Mettre à jour le compteur de votes de la candidature
        $candidature->increment('nombre_votes');
        
        return response()->json([
            'success' => true,
            'message' => 'Vote enregistré avec succès',
            'data' => $vote
        ]);
    }

    // Obtenir les statistiques de votes pour un candidat
    public function getStats($candidatureId)
    {
        $candidature = Candidature::with(['edition', 'category'])->findOrFail($candidatureId);
        $user = Auth::user();
        
        // Vérifier que le candidat a accès à ces statistiques
        if ($candidature->candidat_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé'
            ], 403);
        }
        
        $stats = [
            'total_votes' => $candidature->nombre_votes,
            'votes_payants' => Vote::where('candidature_id', $candidatureId)
                ->where('is_paid', true)
                ->count(),
            'votes_gratuits' => Vote::where('candidature_id', $candidatureId)
                ->where('is_paid', false)
                ->count(),
            'votes_aujourdhui' => Vote::where('candidature_id', $candidatureId)
                ->whereDate('created_at', today())
                ->count(),
            'votes_7jours' => Vote::where('candidature_id', $candidatureId)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'votes_30jours' => Vote::where('candidature_id', $candidatureId)
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
        ];
        
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // Obtenir la liste des votes pour un candidat (avec pagination)
    public function getVotesList(Request $request, $editionId = null, $categoryId = null)
    {
        $user = Auth::user();
        
        // Récupérer les candidatures de l'utilisateur
        $query = Candidature::where('candidat_id', $user->id);
        
        if ($editionId) {
            $query->where('edition_id', $editionId);
        }
        
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        
        $candidatures = $query->pluck('id');
        
        if ($candidatures->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => 15,
                    'current_page' => 1,
                    'last_page' => 1
                ]
            ]);
        }
        
        // Récupérer les votes avec pagination
        $votes = Vote::whereIn('candidature_id', $candidatures)
            ->with(['votant', 'edition', 'categorie', 'payment'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        
        return response()->json([
            'success' => true,
            'data' => $votes->items(),
            'pagination' => [
                'total' => $votes->total(),
                'per_page' => $votes->perPage(),
                'current_page' => $votes->currentPage(),
                'last_page' => $votes->lastPage()
            ]
        ]);
    }

    // Obtenir le classement pour une édition/catégorie
    public function getClassement(Request $request, $editionId = null, $categoryId = null)
    {
        $user = Auth::user();
        
        // Récupérer les candidatures de l'utilisateur
        $userCandidatures = Candidature::where('candidat_id', $user->id)
            ->when($editionId, function($q) use ($editionId) {
                return $q->where('edition_id', $editionId);
            })
            ->when($categoryId, function($q) use ($categoryId) {
                return $q->where('category_id', $categoryId);
            })
            ->get();
        
        if ($userCandidatures->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'user_position' => null
            ]);
        }
        
        // Récupérer le classement complet
        $query = Candidature::where('statut', 'validee')
            ->with(['candidat', 'category']);
            
        if ($editionId) {
            $query->where('edition_id', $editionId);
        }
        
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        
        $classement = $query->orderBy('nombre_votes', 'desc')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($candidature, $index) {
                return [
                    'position' => $index + 1,
                    'candidat_id' => $candidature->candidat_id,
                    'candidat_nom' => $candidature->candidat->nom,
                    'candidat_prenoms' => $candidature->candidat->prenoms,
                    'candidature_id' => $candidature->id,
                    'votes' => $candidature->nombre_votes,
                    'category' => $candidature->category->nom ?? 'Non spécifiée',
                    'photo_url' => $candidature->candidat->photo_url,
                ];
            });
        
        // Trouver la position de l'utilisateur
        $userPosition = null;
        foreach ($classement as $item) {
            if ($userCandidatures->contains('id', $item['candidature_id'])) {
                $userPosition = $item;
                break;
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $classement,
            'user_position' => $userPosition,
            'total_participants' => $classement->count()
        ]);
    }

    // Récupérer les votes pour un candidat (vue candidat)
    public function getVotesForCandidat(Request $request, $editionId = null, $categoryId = null)
    {
        return $this->getVotesList($request, $editionId, $categoryId);
    }
}