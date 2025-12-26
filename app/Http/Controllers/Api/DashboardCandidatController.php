<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Models\Vote;
use App\Models\Payment;
use App\Models\Edition;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CandidatVotesExport;
use Illuminate\Support\Facades\Validator;

class DashboardCandidatController extends Controller
{
    /**
     * Obtenir les statistiques globales du candidat
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            // Vérifier si l'utilisateur est un candidat
            if (!$user->hasRole('candidat') && $user->type !== 'candidat') {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès réservé aux candidats'
                ], 403);
            }

            // Récupérer toutes les candidatures du candidat
            $candidatures = Candidature::where('candidat_id', $user->id)
                ->with(['edition', 'category'])
                ->get();

            // Statistiques globales
            $stats = $this->calculateGlobalStats($user->id, $candidatures);

            // Candidatures actives pour le dashboard
            $activeCandidatures = $candidatures->filter(function ($c) {
                return in_array($c->statut, ['validee', 'preselectionne', 'finaliste', 'gagnant']);
            })->values();

            // Derniers votes reçus
            $lastVotes = Vote::where('candidat_id', $user->id)
                ->where('is_paid', true)
                ->with(['edition', 'category'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Classement actuel (si candidature active)
            $ranking = null;
            $activeCandidature = $activeCandidatures->first();
            if ($activeCandidature) {
                $ranking = $this->getCandidatRanking($user->id, $activeCandidature->edition_id, $activeCandidature->category_id);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'global_stats' => $stats,
                    'candidatures' => $activeCandidatures,
                    'last_votes' => $lastVotes,
                    'ranking' => $ranking,
                    'candidat' => [
                        'id' => $user->id,
                        'nom_complet' => $user->nom_complet ?? $user->nom . ' ' . $user->prenoms,
                        'email' => $user->email,
                        'telephone' => $user->telephone,
                        'photo_url' => $user->photo_url,
                        'bio' => $user->bio,
                        'ville' => $user->ville
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération dashboard stats', [
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
     * Calculer les statistiques globales
     */
    private function calculateGlobalStats(int $candidatId, $candidatures): array
    {
        $totalVotes = Vote::where('candidat_id', $candidatId)
            ->where('is_paid', true)
            ->count();

        $totalAmount = Vote::where('candidat_id', $candidatId)
            ->where('is_paid', true)
            ->sum('montant');

        $todayVotes = Vote::where('candidat_id', $candidatId)
            ->where('is_paid', true)
            ->whereDate('created_at', Carbon::today())
            ->count();

        $monthVotes = Vote::where('candidat_id', $candidatId)
            ->where('is_paid', true)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();

        $activeCandidatures = $candidatures->filter(function ($c) {
            return in_array($c->statut, ['validee', 'preselectionne', 'finaliste', 'gagnant']);
        })->count();

        $totalCandidatures = $candidatures->count();

        $uniqueVoters = Vote::where('candidat_id', $candidatId)
            ->where('is_paid', true)
            ->distinct('email_votant')
            ->count('email_votant');

        // Classement moyen
        $averageRanking = $this->calculateAverageRanking($candidatId, $candidatures);

        return [
            'total_votes' => $totalVotes,
            'total_amount' => (float) $totalAmount,
            'today_votes' => $todayVotes,
            'month_votes' => $monthVotes,
            'active_candidatures' => $activeCandidatures,
            'total_candidatures' => $totalCandidatures,
            'unique_voters' => $uniqueVoters,
            'average_ranking' => $averageRanking,
            'vote_avg' => $totalVotes > 0 ? round($totalAmount / $totalVotes, 2) : 0
        ];
    }

    /**
     * Calculer le classement moyen
     */
    private function calculateAverageRanking(int $candidatId, $candidatures): float
    {
        $totalRanking = 0;
        $count = 0;

        foreach ($candidatures as $candidature) {
            $ranking = $this->getCandidatRanking($candidatId, $candidature->edition_id, $candidature->category_id);
            if ($ranking && $ranking['position'] > 0) {
                $totalRanking += $ranking['position'];
                $count++;
            }
        }

        return $count > 0 ? round($totalRanking / $count, 1) : 0;
    }

    /**
     * Obtenir les statistiques détaillées par édition/catégorie
     */
    public function getDetailedStats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'nullable|exists:categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            $editionId = $request->edition_id;
            $categoryId = $request->category_id;

            // Vérifier que le candidat participe
            $participation = Candidature::where('candidat_id', $user->id)
                ->where('edition_id', $editionId)
                ->when($categoryId, function ($query) use ($categoryId) {
                    $query->where('category_id', $categoryId);
                })
                ->first();

            if (!$participation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne participez pas à cette édition/catégorie'
                ], 403);
            }

            // Statistiques détaillées
            $stats = $this->getCandidatStats($user->id, $editionId, $categoryId);
            $periodStats = $this->getPeriodStats($user->id, $editionId, $categoryId);
            $ranking = $this->getCandidatRanking($user->id, $editionId, $categoryId);

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'period_stats' => $periodStats,
                    'ranking' => $ranking,
                    'edition' => Edition::find($editionId),
                    'category' => $categoryId ? Category::find($categoryId) : null,
                    'candidature' => $participation
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération stats détaillées', [
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
     * Obtenir les statistiques détaillées du candidat
     */
    private function getCandidatStats(int $candidatId, int $editionId, ?int $categoryId = null): array
    {
        // Requête de base pour les votes
        $voteQuery = Vote::where('candidat_id', $candidatId)
            ->where('edition_id', $editionId)
            ->where('is_paid', true);
        
        if ($categoryId) {
            $voteQuery->where('categorie_id', $categoryId);
        }

        $totalVotes = $voteQuery->count();
        $totalAmount = $voteQuery->sum('montant');
        
        // Votes aujourd'hui
        $todayVotes = $voteQuery->clone()->whereDate('created_at', Carbon::today())->count();
        
        // Votes cette semaine
        $weekVotes = $voteQuery->clone()
            ->whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ])
            ->count();
        
        // Votes ce mois
        $monthVotes = $voteQuery->clone()
            ->whereBetween('created_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth()
            ])
            ->count();
        
        // Top votants
        $topVotants = $voteQuery->clone()
            ->select('email_votant', 'customer_phone', DB::raw('COUNT(*) as vote_count'), DB::raw('SUM(montant) as total_amount'))
            ->groupBy('email_votant', 'customer_phone')
            ->orderBy('vote_count', 'desc')
            ->limit(10)
            ->get();

        // Distribution par heure
        $hourlyDistribution = Vote::where('candidat_id', $candidatId)
            ->where('edition_id', $editionId)
            ->where('is_paid', true)
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('categorie_id', $categoryId);
            })
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as votes'))
            ->groupBy(DB::raw('HOUR(created_at)'))
            ->orderBy('hour')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->hour => $item->votes];
            });

        // Méthodes de paiement
        $paymentMethods = Payment::where('candidat_id', $candidatId)
            ->where('edition_id', $editionId)
            ->whereIn('status', ['approved', 'completed', 'paid', 'success'])
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get();

        // Pays des votants
        $votersByCountry = Vote::where('candidat_id', $candidatId)
            ->where('edition_id', $editionId)
            ->where('is_paid', true)
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('categorie_id', $categoryId);
            })
            ->whereNotNull('country')
            ->select('country', DB::raw('COUNT(*) as votes'))
            ->groupBy('country')
            ->orderBy('votes', 'desc')
            ->limit(10)
            ->get();

        return [
            'total_votes' => $totalVotes,
            'total_amount' => (float) $totalAmount,
            'today_votes' => $todayVotes,
            'week_votes' => $weekVotes,
            'month_votes' => $monthVotes,
            'vote_avg' => $totalVotes > 0 ? round($totalAmount / $totalVotes, 2) : 0,
            'top_votants' => $topVotants,
            'hourly_distribution' => $hourlyDistribution,
            'payment_methods' => $paymentMethods,
            'voters_by_country' => $votersByCountry
        ];
    }

    /**
     * Obtenir les statistiques par période
     */
    private function getPeriodStats(int $candidatId, int $editionId, ?int $categoryId = null): array
    {
        $periods = [];
        
        // 7 derniers jours
        $last7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();
            
            $votesCount = Vote::where('candidat_id', $candidatId)
                ->where('edition_id', $editionId)
                ->when($categoryId, function ($query) use ($categoryId) {
                    $query->where('categorie_id', $categoryId);
                })
                ->where('is_paid', true)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();
                
            $amount = Vote::where('candidat_id', $candidatId)
                ->where('edition_id', $editionId)
                ->when($categoryId, function ($query) use ($categoryId) {
                    $query->where('categorie_id', $categoryId);
                })
                ->where('is_paid', true)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->sum('amount');
            
            $last7Days[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('d/m'),
                'votes' => $votesCount,
                'amount' => (float) $amount
            ];
        }
        
        // 12 derniers mois
        $last12Months = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $monthStart = $date->copy()->startOfMonth();
            $monthEnd = $date->copy()->endOfMonth();
            
            $votesCount = Vote::where('candidat_id', $candidatId)
                ->where('edition_id', $editionId)
                ->when($categoryId, function ($query) use ($categoryId) {
                    $query->where('categorie_id', $categoryId);
                })
                ->where('is_paid', true)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();
                
            $amount = Vote::where('candidat_id', $candidatId)
                ->where('edition_id', $editionId)
                ->when($categoryId, function ($query) use ($categoryId) {
                    $query->where('categorie_id', $categoryId);
                })
                ->where('is_paid', true)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('amount');
            
            $last12Months[] = [
                'date' => $date->format('Y-m'),
                'label' => $date->format('M Y'),
                'votes' => $votesCount,
                'amount' => (float) $amount
            ];
        }
        
        return [
            'last_7_days' => $last7Days,
            'last_12_months' => $last12Months
        ];
    }

    /**
     * Obtenir le classement du candidat
     */
    private function getCandidatRanking(int $candidatId, int $editionId, ?int $categoryId = null): ?array
    {
        // Requête pour les candidatures classées
        $query = Candidature::where('edition_id', $editionId)
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->orderBy('nombre_votes', 'desc')
            ->with(['candidat']);

        $candidatures = $query->get();
        
        // Trouver la position du candidat
        $position = null;
        $totalParticipants = $candidatures->count();
        
        foreach ($candidatures as $index => $candidature) {
            if ($candidature->candidat_id == $candidatId) {
                $position = $index + 1;
                break;
            }
        }

        if (!$position) {
            return null;
        }

        // Participants devant/derrière
        $ahead = $position - 1;
        $behind = $totalParticipants - $position;

        // Progression sur 30 jours
        $last30DaysVotes = Vote::where('candidat_id', $candidatId)
            ->where('edition_id', $editionId)
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('categorie_id', $categoryId);
            })
            ->where('is_paid', true)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        return [
            'position' => $position,
            'total_participants' => $totalParticipants,
            'ahead' => $ahead,
            'behind' => $behind,
            'percentage' => $totalParticipants > 0 ? round(($position / $totalParticipants) * 100, 1) : 0,
            'last_30_days_votes' => $last30DaysVotes,
            'top_10' => $candidatures->take(10)->values()
        ];
    }

    /**
     * Obtenir la liste des votes
     */
    public function getVotesList(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'nullable|exists:categories,id',
                'search' => 'nullable|string',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'sort_by' => 'nullable|in:created_at,amount,email_votant',
                'sort_order' => 'nullable|in:asc,desc'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier la participation
            $participation = Candidature::where('candidat_id', $user->id)
                ->where('edition_id', $request->edition_id)
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('category_id', $request->category_id);
                })
                ->first();

            if (!$participation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne participez pas à cette édition/catégorie'
                ], 403);
            }

            // Requête pour les votes
            $votesQuery = Vote::where('candidat_id', $user->id)
                ->where('edition_id', $request->edition_id)
                ->where('is_paid', true)
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('categorie_id', $request->category_id);
                })
                ->when($request->search, function ($query) use ($request) {
                    $query->where(function ($q) use ($request) {
                        $q->where('email_votant', 'like', "%{$request->search}%")
                          ->orWhere('customer_phone', 'like', "%{$request->search}%");
                    });
                })
                ->when($request->date_from, function ($query) use ($request) {
                    $query->whereDate('created_at', '>=', $request->date_from);
                })
                ->when($request->date_to, function ($query) use ($request) {
                    $query->whereDate('created_at', '<=', $request->date_to);
                })
                ->with(['edition:id,nom', 'category:id,nom', 'payment:id,reference,payment_method']);

            // Tri
            $sortBy = $request->sort_by ?? 'created_at';
            $sortOrder = $request->sort_order ?? 'desc';
            $votesQuery->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->per_page ?? 20;
            $votes = $votesQuery->paginate($perPage);

            // Statistiques de la période
            $statsQuery = Vote::where('candidat_id', $user->id)
                ->where('edition_id', $request->edition_id)
                ->where('is_paid', true)
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('categorie_id', $request->category_id);
                })
                ->when($request->date_from, function ($query) use ($request) {
                    $query->whereDate('created_at', '>=', $request->date_from);
                })
                ->when($request->date_to, function ($query) use ($request) {
                    $query->whereDate('created_at', '<=', $request->date_to);
                });

            $stats = [
                'total_votes' => $statsQuery->count(),
                'total_amount' => (float) $statsQuery->sum('amount'),
                'unique_voters' => $statsQuery->distinct('email_votant')->count('email_votant'),
                'avg_vote_amount' => $statsQuery->avg('montant') ? round($statsQuery->avg('montant'), 2) : 0
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'votes' => $votes,
                    'stats' => $stats,
                    'filters' => $request->only(['edition_id', 'category_id', 'search', 'date_from', 'date_to', 'sort_by', 'sort_order'])
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération liste votes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des votes'
            ], 500);
        }
    }

    /**
     * Exporter les votes en Excel
     */
    public function exportVotes(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'nullable|exists:categories,id',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier la participation
            $participation = Candidature::where('candidat_id', $user->id)
                ->where('edition_id', $request->edition_id)
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('category_id', $request->category_id);
                })
                ->first();

            if (!$participation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne participez pas à cette édition/catégorie'
                ], 403);
            }

            $filename = 'votes_' . $user->id . '_' . Carbon::now()->format('Y-m-d_H-i-s') . '.xlsx';
            
            return Excel::download(new CandidatVotesExport(
                $user->id,
                $request->edition_id,
                $request->category_id,
                $request->date_from,
                $request->date_to
            ), $filename);

        } catch (\Exception $e) {
            Log::error('Erreur export votes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'export des votes'
            ], 500);
        }
    }

    /**
     * Obtenir les paiements associés
     */
    public function getPayments(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $paymentsQuery = Payment::where('candidat_id', $user->id)
                ->when($request->edition_id, function ($query) use ($request) {
                    $query->where('edition_id', $request->edition_id);
                })
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('category_id', $request->category_id);
                })
                ->when($request->status, function ($query) use ($request) {
                    $query->where('status', $request->status);
                })
                ->with(['edition:id,nom', 'category:id,nom'])
                ->orderBy('created_at', 'desc');

            $perPage = $request->per_page ?? 20;
            $payments = $paymentsQuery->paginate($perPage);

            // Statistiques
            $statsQuery = Payment::where('candidat_id', $user->id)
                ->when($request->edition_id, function ($query) use ($request) {
                    $query->where('edition_id', $request->edition_id);
                })
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('category_id', $request->category_id);
                });

            $stats = [
                'total_payments' => $statsQuery->count(),
                'successful_payments' => $statsQuery->whereIn('status', ['approved', 'completed', 'paid', 'success'])->count(),
                'total_amount' => (float) $statsQuery->whereIn('status', ['approved', 'completed', 'paid', 'success'])->sum('amount'),
                'mobile_money' => $statsQuery->where('payment_method', 'mobile_money')->count(),
                'card' => $statsQuery->where('payment_method', 'card')->count()
            ];

            // Distribution par statut
            $statusDistribution = Payment::where('candidat_id', $user->id)
                ->when($request->edition_id, function ($query) use ($request) {
                    $query->where('edition_id', $request->edition_id);
                })
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('category_id', $request->category_id);
                })
                ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total_amount'))
                ->groupBy('status')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'payments' => $payments,
                    'stats' => $stats,
                    'status_distribution' => $statusDistribution
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération paiements', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des paiements'
            ], 500);
        }
    }

    /**
     * Obtenir les top votants
     */
    public function getTopVotants(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'nullable|exists:categories,id',
                'limit' => 'nullable|integer|min:1|max:50'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier la participation
            $participation = Candidature::where('candidat_id', $user->id)
                ->where('edition_id', $request->edition_id)
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('category_id', $request->category_id);
                })
                ->first();

            if (!$participation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne participez pas à cette édition/catégorie'
                ], 403);
            }

            $limit = $request->limit ?? 10;

            $topVotants = Vote::where('candidat_id', $user->id)
                ->where('edition_id', $request->edition_id)
                ->where('is_paid', true)
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('categorie_id', $request->category_id);
                })
                ->select(
                    'email_votant',
                    'customer_phone',
                    'customer_firstname',
                    'customer_lastname',
                    DB::raw('COUNT(*) as vote_count'),
                    DB::raw('SUM(amount) as total_amount'),
                    DB::raw('MAX(created_at) as last_vote_date')
                )
                ->groupBy('email_votant', 'customer_phone', 'customer_firstname', 'customer_lastname')
                ->orderBy('vote_count', 'desc')
                ->orderBy('total_amount', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'top_votants' => $topVotants,
                    'edition' => Edition::find($request->edition_id),
                    'category' => $request->category_id ? Category::find($request->category_id) : null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération top votants', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des top votants'
            ], 500);
        }
    }

    /**
     * Obtenir l'évolution des votes
     */
    public function getVotesEvolution(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'nullable|exists:categories,id',
                'period' => 'nullable|in:day,week,month',
                'days' => 'nullable|integer|min:1|max:365'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier la participation
            $participation = Candidature::where('candidat_id', $user->id)
                ->where('edition_id', $request->edition_id)
                ->when($request->category_id, function ($query) use ($request) {
                    $query->where('category_id', $request->category_id);
                })
                ->first();

            if (!$participation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne participez pas à cette édition/catégorie'
                ], 403);
            }

            $period = $request->period ?? 'day';
            $days = $request->days ?? 30;

            $evolution = [];
            $currentDate = Carbon::now();

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = $currentDate->copy()->subDays($i);
                
                $periodStart = $date->copy()->startOfDay();
                $periodEnd = $date->copy()->endOfDay();

                if ($period === 'week') {
                    $periodStart = $date->copy()->startOfWeek();
                    $periodEnd = $date->copy()->endOfWeek();
                } elseif ($period === 'month') {
                    $periodStart = $date->copy()->startOfMonth();
                    $periodEnd = $date->copy()->endOfMonth();
                }

                $votesQuery = Vote::where('candidat_id', $user->id)
                    ->where('edition_id', $request->edition_id)
                    ->where('is_paid', true)
                    ->when($request->category_id, function ($query) use ($request) {
                        $query->where('categorie_id', $request->category_id);
                    })
                    ->whereBetween('created_at', [$periodStart, $periodEnd]);

                $votesCount = $votesQuery->count();
                $amount = $votesQuery->sum('amount');

                $evolution[] = [
                    'date' => $date->format('Y-m-d'),
                    'label' => $period === 'month' ? $date->format('M Y') : 
                              ($period === 'week' ? 'Semaine ' . $date->weekOfYear : $date->format('d/m')),
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->format('Y-m-d'),
                    'votes' => $votesCount,
                    'amount' => (float) $amount,
                    'avg_per_vote' => $votesCount > 0 ? round($amount / $votesCount, 2) : 0
                ];

                // Avancer à la période suivante
                if ($period === 'week') {
                    $currentDate->subWeek();
                } elseif ($period === 'month') {
                    $currentDate->subMonth();
                }
            }

            // Tendance
            $trend = $this->calculateTrend($evolution);

            return response()->json([
                'success' => true,
                'data' => [
                    'evolution' => $evolution,
                    'trend' => $trend,
                    'period' => $period,
                    'days' => $days,
                    'edition' => Edition::find($request->edition_id),
                    'category' => $request->category_id ? Category::find($request->category_id) : null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur récupération évolution votes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'évolution'
            ], 500);
        }
    }

    /**
     * Calculer la tendance
     */
    private function calculateTrend(array $evolution): array
    {
        if (count($evolution) < 2) {
            return [
                'direction' => 'stable',
                'percentage' => 0,
                'description' => 'Données insuffisantes'
            ];
        }

        $firstPeriod = $evolution[0];
        $lastPeriod = $evolution[count($evolution) - 1];

        $votesChange = $lastPeriod['votes'] - $firstPeriod['votes'];
        $amountChange = $lastPeriod['amount'] - $firstPeriod['amount'];

        $votesPercentage = $firstPeriod['votes'] > 0 ? 
            round(($votesChange / $firstPeriod['votes']) * 100, 2) : 0;
        
        $amountPercentage = $firstPeriod['amount'] > 0 ? 
            round(($amountChange / $firstPeriod['amount']) * 100, 2) : 0;

        $direction = 'stable';
        if ($votesPercentage > 5) $direction = 'up';
        if ($votesPercentage < -5) $direction = 'down';

        return [
            'direction' => $direction,
            'votes_change' => $votesChange,
            'votes_percentage' => $votesPercentage,
            'amount_change' => $amountChange,
            'amount_percentage' => $amountPercentage,
            'description' => $this->getTrendDescription($direction, $votesPercentage)
        ];
    }

    /**
     * Obtenir la description de la tendance
     */
    private function getTrendDescription(string $direction, float $percentage): string
    {
        $absPercentage = abs($percentage);
        
        if ($direction === 'up') {
            if ($absPercentage > 50) return 'Croissance exceptionnelle';
            if ($absPercentage > 20) return 'Forte croissance';
            if ($absPercentage > 5) return 'Croissance modérée';
            return 'Légère croissance';
        } elseif ($direction === 'down') {
            if ($absPercentage > 50) return 'Déclin important';
            if ($absPercentage > 20) return 'Baisse significative';
            if ($absPercentage > 5) return 'Légère baisse';
            return 'Légère diminution';
        }
        
        return 'Stable';
    }
}