<?php

namespace App\Http\Controllers\Api\Promoteur;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEditionRequest;
use App\Http\Requests\CreateCategoryRequest;
use App\Http\Resources\EditionResource;
use App\Http\Resources\CandidatureResource;
use App\Models\Edition;
use App\Models\Category;
use App\Models\Candidature;
use App\Models\Partenaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromoteurController extends Controller{
    
    // Gestion des éditions
    public function getMyEditions(Request $request){
        $user = $request->user();
        $editions = Edition::where('promoteur_id', $user->id)
                          ->with(['categories', 'phases', 'partenaires'])
                          ->orderBy('created_at', 'desc')
                          ->paginate(15);

        return EditionResource::collection($editions);
    }

    public function createEdition(CreateEditionRequest $request){
        $edition = Edition::create([
            'nom' => $request->nom,
            'annee' => $request->annee,
            'numero_edition' => $request->numero_edition,
            'description' => $request->description,
            'statut' => 'brouillon',
            'promoteur_id' => $request->user()->id,
            'date_debut_inscriptions' => $request->date_debut_inscriptions,
            'date_fin_inscriptions' => $request->date_fin_inscriptions,
        ]);

        // Créer les 4 phases par défaut
        $phases = [
            ['nom' => 'Présélection', 'numero_phase' => 1],
            ['nom' => 'Phase Intermédiaire 1', 'numero_phase' => 2],
            ['nom' => 'Phase Intermédiaire 2', 'numero_phase' => 3],
            ['nom' => 'Finale', 'numero_phase' => 4],
        ];

        foreach ($phases as $phase) {
            $edition->phases()->create($phase);
        }

        return response()->json([
            'message' => 'Édition créée avec succès.',
            'edition' => new EditionResource($edition->load(['categories', 'phases', 'partenaires']))
        ], 201);
    }

    public function updateEdition(Request $request, $id){
        $edition = Edition::where('promoteur_id', $request->user()->id)
                         ->findOrFail($id);

        // Empêcher la modification si l'édition est active ou terminée
        if (in_array($edition->statut, ['active', 'terminee'])) {
            return response()->json(['message' => 'Impossible de modifier une édition active ou terminée.'], 400);
        }

        $request->validate([
            'nom' => 'sometimes|string|max:200',
            'description' => 'sometimes|string|max:2000',
            'statut' => 'sometimes|in:brouillon,active,terminee,archivee',
        ]);

        $edition->update($request->only(['nom', 'description', 'statut']));

        return response()->json([
            'message' => 'Édition mise à jour avec succès.',
            'edition' => new EditionResource($edition->load(['categories', 'phases', 'partenaires']))
        ]);
    }

    // Gestion des inscriptions
    public function openRegistrations(Request $request, $editionId){
        $edition = Edition::where('promoteur_id', $request->user()->id)
                         ->findOrFail($editionId);

        if ($edition->statut !== 'active') {
            return response()->json(['message' => 'L\'édition doit être active pour ouvrir les inscriptions.'], 400);
        }

        $request->validate([
            'date_debut' => 'required|date|after:today',
            'date_fin' => 'required|date|after:date_debut',
        ]);

        $edition->update([
            'inscriptions_ouvertes' => true,
            'date_debut_inscriptions' => $request->date_debut,
            'date_fin_inscriptions' => $request->date_fin,
        ]);

        return response()->json([
            'message' => 'Inscriptions ouvertes avec succès.',
            'edition' => new EditionResource($edition)
        ]);
    }

    public function closeRegistrations(Request $request, $editionId){
        $edition = Edition::where('promoteur_id', $request->user()->id)
                         ->findOrFail($editionId);

        $edition->update([
            'inscriptions_ouvertes' => false,
        ]);

        return response()->json([
            'message' => 'Inscriptions fermées avec succès..',
            'edition' => new EditionResource($edition)
        ]);
    }

    // Gestion des catégories
    public function createCategory(CreateCategoryRequest $request, Edition $edition){
        $category = $edition->categories()->create([
            'nom' => $request->nom,
            'description' => $request->description,
            'ordre_affichage' => $request->ordre_affichage ?? 0,
            'active' => $request->active ?? true,
        ]);

        return response()->json([
            'message' => 'Catégorie créée avec succès.',
            'category' => $category
        ], 201);
    }

    // Gestion des candidatures
    public function getCandidatures(Request $request, $editionId){
        $edition = Edition::where('promoteur_id', $request->user()->id)
                         ->findOrFail($editionId);

        $query = $edition->candidatures()->with(['candidat', 'category']);

        // Filtres
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('candidat', function($q) use ($search) {
                $q->where('nom', 'like', "%$search%")
                  ->orWhere('prenoms', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%");
            });
        }

        $candidatures = $query->orderBy('created_at', 'desc')->paginate(20);

        return CandidatureResource::collection($candidatures);
    }

    public function validateCandidature(Request $request, $candidatureId){
        $candidature = Candidature::findOrFail($candidatureId);
        
        // Vérifier que le promoteur gère cette édition
        if ($candidature->edition->promoteur_id !== $request->user()->id) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'statut' => 'required|in:validee,refusee',
        ]);

        if($request->statut === 'refusee'){
            $request->validate([
                'motif_refus' => 'required_if:statut,refusee|string|max:500',
            ]);
        }

        $candidature->update([
            'statut' => $request->statut,
            'motif_refus' => $request->statut === 'refusee' ? $request->motif_refus : null,
            'valide_par' => $request->user()->id,
            'valide_le' => now(),
        ]);

        // Si la candidature est validée, activer le compte candidat
        if ($request->statut === 'validee') {
            $candidature->candidat->update(['compte_actif' => true]);
        }

        return response()->json([
            'message' => 'Candidature ' . ($request->statut === 'validee' ? 'validée' : 'refusée') . ' avec succès.',
            'candidature' => new CandidatureResource($candidature->load(['candidat', 'category']))
        ]);
    }

    public function configurerVotes(Request $request, $editionId)
    {
        try {
            DB::beginTransaction();

            $edition = Edition::where('promoteur_id', $request->user()->id)
                            ->findOrFail($editionId);

            // Vérifier que l'édition est active
            if ($edition->statut !== 'active') {
                return response()->json([
                    'message' => 'L\'édition doit être active pour configurer les votes',
                    'errors' => ['edition' => ['Édition non active']]
                ], 422);
            }

            // Vérifier que l'édition peut configurer les votes
            if (!$edition->peutConfigurerVotes) {
                return response()->json([
                    'message' => 'Impossible de configurer les votes pour cette édition',
                    'errors' => ['statut' => ['Statut des votes incompatible']]
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'date_debut_votes' => 'required|date|after_or_equal:now',
                'date_fin_votes' => 'required|date|after:date_debut_votes',
            ], [
                'date_debut_votes.required' => 'La date de début des votes est requise',
                'date_debut_votes.date' => 'Format de date invalide pour le début des votes',
                'date_debut_votes.after_or_equal' => 'La date de début doit être aujourd\'hui ou une date future',
                'date_fin_votes.required' => 'La date de fin des votes est requise',
                'date_fin_votes.date' => 'Format de date invalide pour la fin des votes',
                'date_fin_votes.after' => 'La date de fin doit être après la date de début',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Mettre à jour les dates de vote
            $edition->update([
                'date_debut_votes' => $request->date_debut_votes,
                'date_fin_votes' => $request->date_fin_votes,
                'statut_votes' => 'en_attente',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Dates de vote configurées avec succès',
                'edition' => $edition->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Édition non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur configuration votes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue lors de la configuration des votes'
            ], 500);
        }
    }

    /**
     * Démarrer les votes
     */
    public function demarrerVotes(Request $request, $editionId)
    {
        try {
            DB::beginTransaction();

            $edition = Edition::where('promoteur_id', $request->user()->id)
                            ->findOrFail($editionId);

            // Vérifier que l'édition peut démarrer les votes
            if (!$edition->peutDemarrerVotes) {
                return response()->json([
                    'message' => 'Impossible de démarrer les votes pour cette édition',
                    'errors' => ['statut' => ['Conditions non remplies pour démarrer les votes']]
                ], 422);
            }

            // Vérifier que la date de début est atteinte ou peut être anticipée
            $now = now();
            $dateDebut = new \Carbon\Carbon($edition->date_debut_votes);

            if ($now->lessThan($dateDebut)) {
                // On peut anticiper le début si c'est proche (moins de 24h)
                if ($dateDebut->diffInHours($now) > 24) {
                    return response()->json([
                        'message' => 'La date de début des votes n\'est pas encore atteinte',
                        'errors' => ['date' => ['Trop tôt pour démarrer les votes']]
                    ], 422);
                }
            }

            // Mettre à jour le statut
            $edition->update([
                'statut_votes' => 'en_cours',
                'date_debut_votes' => $now, // Mettre à jour avec la date réelle de début
            ]);

            // Log l'action
            activity()
                ->performedOn($edition)
                ->causedBy($request->user())
                ->log('Démarrage des votes pour l\'édition ' . $edition->nom);

            DB::commit();

            return response()->json([
                'message' => 'Votes démarrés avec succès',
                'edition' => $edition->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Édition non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur démarrage votes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue lors du démarrage des votes'
            ], 500);
        }
    }

    /**
     * Suspendre les votes
     */
    public function suspendreVotes(Request $request, $editionId)
    {
        try {
            DB::beginTransaction();

            $edition = Edition::where('promoteur_id', $request->user()->id)
                            ->findOrFail($editionId);

            // Vérifier que l'édition peut suspendre les votes
            if (!$edition->peutSuspendreVotes) {
                return response()->json([
                    'message' => 'Impossible de suspendre les votes pour cette édition',
                    'errors' => ['statut' => ['Les votes ne sont pas en cours']]
                ], 422);
            }

            // Mettre à jour le statut
            $edition->update([
                'statut_votes' => 'suspendu',
            ]);

            // Log l'action
            activity()
                ->performedOn($edition)
                ->causedBy($request->user())
                ->log('Suspension des votes pour l\'édition ' . $edition->nom);

            DB::commit();

            return response()->json([
                'message' => 'Votes suspendus avec succès',
                'edition' => $edition->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Édition non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur suspension votes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suspension des votes'
            ], 500);
        }
    }

    /**
     * Relancer les votes
     */
    public function relancerVotes(Request $request, $editionId)
    {
        try {
            DB::beginTransaction();

            $edition = Edition::where('promoteur_id', $request->user()->id)
                            ->findOrFail($editionId);

            // Vérifier que l'édition peut relancer les votes
            if (!$edition->peutRelancerVotes) {
                return response()->json([
                    'message' => 'Impossible de relancer les votes pour cette édition',
                    'errors' => ['statut' => ['Les votes ne sont pas suspendus']]
                ], 422);
            }

            // Vérifier que la date de fin n'est pas dépassée
            if (now()->greaterThan($edition->date_fin_votes)) {
                return response()->json([
                    'message' => 'La période de vote est terminée',
                    'errors' => ['date' => ['Date de fin des votes dépassée']]
                ], 422);
            }

            // Mettre à jour le statut
            $edition->update([
                'statut_votes' => 'en_cours',
            ]);

            // Log l'action
            activity()
                ->performedOn($edition)
                ->causedBy($request->user())
                ->log('Relance des votes pour l\'édition ' . $edition->nom);

            DB::commit();

            return response()->json([
                'message' => 'Votes relancés avec succès',
                'edition' => $edition->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Édition non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur relance votes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue lors de la relance des votes'
            ], 500);
        }
    }

    /**
     * Terminer les votes
     */
    public function terminerVotes(Request $request, $editionId)
    {
        try {
            DB::beginTransaction();

            $edition = Edition::where('promoteur_id', $request->user()->id)
                            ->findOrFail($editionId);

            // Vérifier que l'édition peut terminer les votes
            if (!$edition->peutTerminerVotes) {
                return response()->json([
                    'message' => 'Impossible de terminer les votes pour cette édition',
                    'errors' => ['statut' => ['Conditions non remplies pour terminer les votes']]
                ], 422);
            }

            // Mettre à jour le statut
            $edition->update([
                'statut_votes' => 'termine',
                'date_fin_votes' => now(), // Mettre à jour avec la date réelle de fin
            ]);

            // Log l'action
            activity()
                ->performedOn($edition)
                ->causedBy($request->user())
                ->log('Fin des votes pour l\'édition ' . $edition->nom);

            DB::commit();

            return response()->json([
                'message' => 'Votes terminés avec succès',
                'edition' => $edition->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Édition non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur fin votes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue lors de la fin des votes'
            ], 500);
        }
    }

    /**
     * Modifier les dates de vote
     */
    public function modifierDatesVotes(Request $request, $editionId)
    {
        try {
            DB::beginTransaction();

            $edition = Edition::where('promoteur_id', $request->user()->id)
                            ->findOrFail($editionId);

            // Vérifier que l'édition est active
            if ($edition->statut !== 'active') {
                return response()->json([
                    'message' => 'L\'édition doit être active pour modifier les dates de vote',
                    'errors' => ['edition' => ['Édition non active']]
                ], 422);
            }

            // Vérifier que les votes ne sont pas en cours
            if ($edition->statut_votes === 'en_cours') {
                return response()->json([
                    'message' => 'Impossible de modifier les dates pendant la période de vote',
                    'errors' => ['statut' => ['Les votes sont en cours']]
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'date_debut_votes' => 'required|date|after_or_equal:now',
                'date_fin_votes' => 'required|date|after:date_debut_votes',
            ], [
                'date_debut_votes.required' => 'La date de début des votes est requise',
                'date_debut_votes.date' => 'Format de date invalide pour le début des votes',
                'date_debut_votes.after_or_equal' => 'La date de début doit être aujourd\'hui ou une date future',
                'date_fin_votes.required' => 'La date de fin des votes est requise',
                'date_fin_votes.date' => 'Format de date invalide pour la fin des votes',
                'date_fin_votes.after' => 'La date de fin doit être après la date de début',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation échouée',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Si les votes étaient terminés, les remettre en attente
            $nouveauStatut = $edition->statut_votes === 'termine' ? 'en_attente' : $edition->statut_votes;

            // Mettre à jour les dates de vote
            $edition->update([
                'date_debut_votes' => $request->date_debut_votes,
                'date_fin_votes' => $request->date_fin_votes,
                'statut_votes' => $nouveauStatut,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Dates de vote modifiées avec succès',
                'edition' => $edition->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Édition non trouvée'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur modification dates votes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue lors de la modification des dates de vote'
            ], 500);
        }
    }

    /**
     * Obtenir les informations de vote d'une édition
     */
    public function getInfoVotes($editionId)
    {
        try {
            $edition = Edition::findOrFail($editionId);

            return response()->json([
                'data' => [
                    'id' => $edition->id,
                    'nom' => $edition->nom,
                    'date_debut_votes' => $edition->date_debut_votes,
                    'date_fin_votes' => $edition->date_fin_votes,
                    'statut_votes' => $edition->statut_votes,
                    'statut' => $edition->statut,
                    'votes_ouverts' => $edition->votes_ouverts,
                    'peut_configurer_votes' => $edition->peutConfigurerVotes,
                    'peut_demarrer_votes' => $edition->peutDemarrerVotes,
                    'peut_suspendre_votes' => $edition->peutSuspendreVotes,
                    'peut_relancer_votes' => $edition->peutRelancerVotes,
                    'peut_terminer_votes' => $edition->peutTerminerVotes,
                    'est_active' => $edition->est_active,
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Édition non trouvée'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Erreur récupération info votes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue lors de la récupération des informations'
            ], 500);
        }
    }
}