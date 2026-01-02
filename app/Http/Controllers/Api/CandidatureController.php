<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Models\Edition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CandidatureController extends Controller{
    // Récupérer les candidatures de l'utilisateur connecté
    public function getMesCandidatures(){
        $user = Auth::user();
        
        $candidatures = Candidature::where('candidat_id', $user->id)
            ->with(['edition', 'category', 'validateur'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($candidature) {
                return [
                    'id' => $candidature->id,
                    'candidat_id' => $candidature->candidat_id,
                    'edition_id' => $candidature->edition_id,
                    'category_id' => $candidature->category_id,
                    'video_url' => $candidature->video_url,
                    'description_talent' => $candidature->description_talent,
                    'statut' => $candidature->statut,
                    'phase_actuelle' => $candidature->phase_actuelle ?? 1,
                    'note_jury' => $candidature->note_jury,
                    'nombre_votes' => $candidature->nombre_votes ?? 0,
                    'motif_refus' => $candidature->motif_refus,
                    'valide_par' => $candidature->valide_par,
                    'valide_le' => $candidature->valide_le,
                    'created_at' => $candidature->created_at,
                    'updated_at' => $candidature->updated_at,
                    'edition' => $candidature->edition ? [
                        'id' => $candidature->edition->id,
                        'nom' => $candidature->edition->nom,
                        'annee' => $candidature->edition->annee,
                        'numero_edition' => $candidature->edition->numero_edition,
                    ] : null,
                    'category' => $candidature->category ? [
                        'id' => $candidature->category->id,
                        'nom' => $candidature->category->nom,
                    ] : null,
                ];
            });
        
        return response()->json([
            'success' => true,
            'data' => $candidatures
        ]);
    }

    // Récupérer les statistiques du candidat
    public function getStatistiques(){
        $user = Auth::user();
        
        // Candidatures de l'utilisateur
        $candidatures = Candidature::where('candidat_id', $user->id)->get();
        
        // Éditions ouvertes
        $editionsOuvertes = Edition::where('statut', 'ouvert')
            ->where('date_fin_inscriptions', '>=', now())
            ->count();
        
        // Candidature active
        $activeCandidature = $candidatures->firstWhere('statut', 'validee');
        
        // Total des votes
        $totalVotes = $candidatures->sum('nombre_votes');
        
        // Statistiques
        $stats = [
            'candidatures_actives' => $candidatures->whereIn('statut', ['validee', 'preselectionne', 'finaliste'])->count(),
            'total_votes' => $totalVotes,
            'phase_actuelle' => $activeCandidature ? ($activeCandidature->phase_actuelle ?? 1) : 1,
            'editions_ouvertes' => $editionsOuvertes,
            'total_candidatures' => $candidatures->count(),
            'active_candidature' => $activeCandidature ? [
                'id' => $activeCandidature->id,
                'edition_nom' => $activeCandidature->edition->nom ?? '',
                'category_nom' => $activeCandidature->category->nom ?? '',
                'phase' => $activeCandidature->phase_actuelle ?? 1,
                'votes' => $activeCandidature->nombre_votes ?? 0,
            ] : null,
        ];
        
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    // Créer une nouvelle candidature
    public function store(Request $request)
    {
        $request->validate([
            'edition_id' => 'required|exists:editions,id',
            'category_id' => 'required|exists:categories,id',
            'video_url' => 'required|url',
            'description_talent' => 'required|string|min:10|max:500',
        ]);
        
        $user = Auth::user();
        
        // Vérifier si le candidat a déjà postulé à cette édition
        $existingCandidature = Candidature::where('candidat_id', $user->id)
            ->where('edition_id', $request->edition_id)
            ->first();
            
        if ($existingCandidature) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà postulé à cette édition'
            ], 400);
        }
        
        // Créer la candidature
        $candidature = Candidature::create([
            'candidat_id' => $user->id,
            'edition_id' => $request->edition_id,
            'category_id' => $request->category_id,
            'video_url' => $request->video_url,
            'description_talent' => $request->description_talent,
            'statut' => 'en_attente',
            'phase_actuelle' => 1,
            'nombre_votes' => 0,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Candidature soumise avec succès',
            'data' => $candidature
        ]);
    }
}