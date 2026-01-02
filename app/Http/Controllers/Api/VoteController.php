<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vote;
use App\Models\Payment;
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
    public function vote(Request $request): JsonResponse{
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
    public function voteHistory(Request $request): JsonResponse{
        try {
            $user = auth()->user();
            $perPage = $request->get('per_page', 10);
            
            // Récupérer les paiements où l'utilisateur est le candidat
            $payments = Payment::where('candidat_id', $user->id)
                ->with(['edition', 'candidat', 'category'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Transformer les données pour garder le même format que l'ancien code
            $transformedPayments = $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'payment_method' => $payment->payment_method,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                    'edition' => $payment->edition ? [
                        'id' => $payment->edition->id,
                        'nom' => $payment->edition->nom,
                        'annee' => $payment->edition->annee
                    ] : null,
                    'candidat' => $payment->candidat ? [
                        'id' => $payment->candidat->id,
                        'nom' => $payment->candidat->nom,
                        'prenoms' => $payment->candidat->prenoms,
                        'photo_url' => $payment->candidat->photo_url
                    ] : null,
                    'category' => $payment->category ? [
                        'id' => $payment->category->id,
                        'nom' => $payment->category->nom,
                        'slug' => $payment->category->slug
                    ] : null,
                    // Informations supplémentaires du metadata
                    'metadata' => $payment->metadata,
                    'votes_count' => $payment->metadata['votes_count'] ?? 1,
                    'is_paid' => $payment->status === 'approved',
                    'votant' => [
                        'email' => $payment->customer_email,
                        'phone' => $payment->customer_phone,
                        'firstname' => $payment->customer_firstname,
                        'lastname' => $payment->customer_lastname
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $transformedPayments,
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur historique votes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique'
            ], 500);
        }
    }

    /**
     * Statistiques de l'utilisateur (votant)
     */
    public function getUserStatistics(Request $request): JsonResponse {
        try {
            $user = auth()->user();
            $editionId = $request->get('edition_id');

            // Utiliser l'email de l'utilisateur pour trouver ses paiements
            $query = Payment::where('customer_email', $user->email);
            
            if ($editionId) {
                $query->where('edition_id', $editionId);
            }

            // Calculer les statistiques à partir des paiements
            $allPayments = $query->get();
            $approvedPayments = $allPayments->where('status', 'approved');
            $cancelledPayments = $allPayments->where('status', 'cancelled');
            $failedPayments = $allPayments->whereIn('status', ['failed', 'error']);
            $pendingPayments = $allPayments->where('status', 'pending');

            $totalVotes = $approvedPayments->sum(function ($payment) {
                return $payment->metadata['votes_count'] ?? 1;
            });

            $totalAmount = $approvedPayments->sum('amount');

            $statistics = [
                'total_payments' => $allPayments->count(),
                'total_votes' => $totalVotes,
                'paid_votes' => $totalVotes, // Tous les votes sont payants dans payments
                'free_votes' => 0, // Pas de votes gratuits dans payments
                'total_amount' => $totalAmount,
                'payments_today' => $query->whereDate('created_at', Carbon::today())->count(),
                'approved_payments' => $approvedPayments->count(),
                'cancelled_payments' => $cancelledPayments->count(),
                'failed_payments' => $failedPayments->count(),
                'pending_payments' => $pendingPayments->count(),
                // Catégorie favorite basée sur les votes
                'favorite_category' => $this->getFavoriteCategory($approvedPayments),
                // Candidat favori basé sur les votes
                'favorite_candidat' => $this->getFavoriteCandidat($approvedPayments)
            ];

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur statistiques utilisateur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Obtenir les statistiques de votes pour un candidat
     */
    public function getStats($candidatureId): JsonResponse
    {
        try {
            $candidature = Candidature::with(['edition', 'category'])->findOrFail($candidatureId);
            $user = Auth::user();
            
            // Vérifier que le candidat a accès à ces statistiques
            if ($candidature->candidat_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }
            
            // Récupérer tous les paiements pour cette candidature
            $payments = Payment::where('candidat_id', $user->id)
                ->where('category_id', $candidature->category_id)
                ->where('edition_id', $candidature->edition_id)
                ->get();

            // Calculer le nombre total de votes à partir des paiements approuvés
            $approvedPayments = $payments->where('status', 'approved');
            $totalVotesFromPayments = $approvedPayments->sum(function ($payment) {
                return $payment->metadata['votes_count'] ?? 1;
            });

            $stats = [
                'total_votes' => $candidature->nombre_votes,
                'votes_from_payments' => $totalVotesFromPayments,
                'votes_payants' => $totalVotesFromPayments, // Tous les votes de payments sont payants
                'votes_gratuits' => 0, // Pas de votes gratuits dans payments
                'votes_aujourdhui' => $approvedPayments->where('created_at', '>=', Carbon::today())->sum(function ($payment) {
                    return $payment->metadata['votes_count'] ?? 1;
                }),
                'votes_7jours' => $approvedPayments->where('created_at', '>=', now()->subDays(7))->sum(function ($payment) {
                    return $payment->metadata['votes_count'] ?? 1;
                }),
                'votes_30jours' => $approvedPayments->where('created_at', '>=', now()->subDays(30))->sum(function ($payment) {
                    return $payment->metadata['votes_count'] ?? 1;
                }),
                'total_payments' => $payments->count(),
                'approved_payments' => $approvedPayments->count(),
                'total_amount' => $approvedPayments->sum('amount'),
                'average_amount' => $approvedPayments->avg('amount')
            ];
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur statistiques candidat', [
                'error' => $e->getMessage(),
                'candidature_id' => $candidatureId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques'
            ], 500);
        }
    }

    /**
     * Obtenir la liste des votes pour un candidat (avec pagination)
     */
    public function getVotesList(Request $request, $editionId = null, $categoryId = null): JsonResponse
    {
        try {
            $user = Auth::user();
            $perPage = $request->get('per_page', 15);
            
            // Récupérer les paiements pour ce candidat
            $query = Payment::where('candidat_id', $user->id)
                ->with(['edition', 'category', 'candidat']);
            
            if ($editionId) {
                $query->where('edition_id', $editionId);
            }
            
            if ($categoryId) {
                $query->where('category_id', $categoryId);
            }
            
            $payments = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Transformer les paiements en format "votes" pour le frontend
            $transformedPayments = $payments->map(function ($payment) {
                $votesCount = $payment->metadata['votes_count'] ?? 1;
                
                return [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'amount' => $payment->amount,
                    'votes_count' => $votesCount,
                    'status' => $payment->status,
                    'payment_method' => $payment->payment_method,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                    'is_paid' => $payment->status === 'approved',
                    // Informations de l'édition
                    'edition' => $payment->edition ? [
                        'id' => $payment->edition->id,
                        'nom' => $payment->edition->nom,
                        'annee' => $payment->edition->annee
                    ] : null,
                    // Informations de la catégorie
                    'categorie' => $payment->category ? [
                        'id' => $payment->category->id,
                        'nom' => $payment->category->nom,
                        'slug' => $payment->category->slug
                    ] : null,
                    // Informations du votant
                    'votant' => [
                        'email' => $payment->customer_email,
                        'phone' => $payment->customer_phone,
                        'firstname' => $payment->customer_firstname,
                        'lastname' => $payment->customer_lastname,
                        'fullname' => $payment->customer_firstname . ' ' . $payment->customer_lastname
                    ],
                    // Informations du candidat
                    'candidat' => $payment->candidat ? [
                        'id' => $payment->candidat->id,
                        'nom' => $payment->candidat->nom,
                        'prenoms' => $payment->candidat->prenoms
                    ] : null,
                    // Metadata complet
                    'metadata' => $payment->metadata
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedPayments,
                'pagination' => [
                    'total' => $payments->total(),
                    'per_page' => $payments->perPage(),
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'from' => $payments->firstItem(),
                    'to' => $payments->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur liste votes candidat', [
                'error' => $e->getMessage(),
                'edition_id' => $editionId,
                'category_id' => $categoryId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la liste des votes'
            ], 500);
        }
    }

    /**
     * Obtenir le classement pour une édition/catégorie
     */
    public function getClassement(Request $request, $editionId = null, $categoryId = null): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Récupérer les candidatures de l'utilisateur
            $userCandidatures = Candidature::where('candidat_id', $user->id)
                ->when($editionId, function ($q) use ($editionId) {
                    return $q->where('edition_id', $editionId);
                })
                ->when($categoryId, function ($q) use ($categoryId) {
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
            
            // Récupérer le classement complet des candidatures
            $query = Candidature::where('statut', 'validee')
                ->with(['candidat', 'category', 'edition']);
                
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
                        'edition' => $candidature->edition->nom ?? 'Non spécifiée',
                        'photo_url' => $candidature->candidat->photo_url,
                        // Informations supplémentaires pour le frontend
                        'statut' => $candidature->statut,
                        'created_at' => $candidature->created_at
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

        } catch (\Exception $e) {
            Log::error('Erreur classement', [
                'error' => $e->getMessage(),
                'edition_id' => $editionId,
                'category_id' => $categoryId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du classement'
            ], 500);
        }
    }

    /**
     * Récupérer les votes pour un candidat (vue candidat) - Alias de getVotesList
     */
    public function getVotesForCandidat(Request $request, $editionId = null, $categoryId = null): JsonResponse
    {
        return $this->getVotesList($request, $editionId, $categoryId);
    }

    /**
     * Méthode privée pour obtenir la catégorie favorite d'un utilisateur
     */
    private function getFavoriteCategory($payments)
    {
        if ($payments->isEmpty()) {
            return null;
        }

        $categoryCounts = [];
        
        foreach ($payments as $payment) {
            $categoryId = $payment->category_id;
            $votesCount = $payment->metadata['votes_count'] ?? 1;
            
            if ($categoryId) {
                if (!isset($categoryCounts[$categoryId])) {
                    $categoryCounts[$categoryId] = 0;
                }
                $categoryCounts[$categoryId] += $votesCount;
            }
        }
        
        if (empty($categoryCounts)) {
            return null;
        }
        
        $maxCategoryId = array_keys($categoryCounts, max($categoryCounts))[0];
        
        // Récupérer les informations de la catégorie
        $category = \App\Models\Category::find($maxCategoryId);
        
        if (!$category) {
            return null;
        }
        
        return [
            'id' => $category->id,
            'nom' => $category->nom,
            'count' => $categoryCounts[$maxCategoryId]
        ];
    }

    /**
     * Méthode privée pour obtenir le candidat favori d'un utilisateur
     */
    private function getFavoriteCandidat($payments)
    {
        if ($payments->isEmpty()) {
            return null;
        }

        $candidatCounts = [];
        
        foreach ($payments as $payment) {
            $candidatId = $payment->candidat_id;
            $votesCount = $payment->metadata['votes_count'] ?? 1;
            
            if ($candidatId) {
                if (!isset($candidatCounts[$candidatId])) {
                    $candidatCounts[$candidatId] = 0;
                }
                $candidatCounts[$candidatId] += $votesCount;
            }
        }
        
        if (empty($candidatCounts)) {
            return null;
        }
        
        $maxCandidatId = array_keys($candidatCounts, max($candidatCounts))[0];
        
        // Récupérer les informations du candidat
        $candidat = \App\Models\User::find($maxCandidatId);
        
        if (!$candidat) {
            return null;
        }
        
        return [
            'id' => $candidat->id,
            'nom' => $candidat->nom,
            'prenoms' => $candidat->prenoms,
            'count' => $candidatCounts[$maxCandidatId]
        ];
    }


    /**
     * Obtenir les candidats d'une édition
     */
    public function getCandidats($editionId): JsonResponse {
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

    public function getEditionStatistics($editionId): JsonResponse{
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

    public function getCategories(Request $request): JsonResponse{
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

    private function validateVoteLimits($userId, array $data, VoteSetting $voteSetting, $votesCount): void{
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

    private function handlePaidVote($user, array $data, $votesCount, VoteSetting $voteSetting, Request $request): JsonResponse{
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
}