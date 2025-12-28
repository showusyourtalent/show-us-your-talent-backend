<?php

namespace App\Http\Controllers\Api\Candidat;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterCandidatRequest;
use App\Http\Resources\EditionResource;
use App\Http\Resources\CandidatureResource;
use App\Models\Edition;
use App\Models\Candidature;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Cloudinary\Cloudinary;         // SDK principal
use Cloudinary\Api\Upload\UploadApi; // API upload

class CandidatController extends Controller{

    public function getEditionsOuvertes()
    {
        try {
            $editions = Edition::with(['categories' => function($query) {
                $query->where('active', true)
                    ->orderBy('ordre_affichage');
            }])
            ->where('inscriptions_ouvertes', true)
            ->where('statut', 'active')
            ->where('date_fin_inscriptions', '>=', now())
            ->orderBy('date_fin_inscriptions')
            ->get(['id', 'nom', 'annee', 'numero_edition', 'description', 
                'date_debut_inscriptions', 'date_fin_inscriptions', 
                'statut', 'inscriptions_ouvertes']);

            return response()->json([
                'success' => true,
                'data' => $editions,
                'message' => 'Éditions ouvertes récupérées avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des éditions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function postuler(Request $request){
        DB::beginTransaction();

        try {
    
            $validator = Validator::make($request->all(), [
                'nom' => 'required|string|min:2|max:50',
                'prenoms' => 'required|string|min:2|max:100',
                'email' => 'required|email',
                'date_naissance' => 'required|date|before:-16 years',
                'sexe' => 'required|in:M,F,Autre',
                'telephone' => 'required|string|min:8|max:20',
                'origine' => 'required|string|max:100',
                'ethnie' => 'nullable|string|max:100',
                'universite' => 'required|string|max:200',
                'filiere' => 'required|string|max:200',
                'annee_etude' => 'required|string',

                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'required|exists:categories,id',

                // lien vidéo envoyé par le frontend
                'video_url' => 'required|url|max:500',

                'description_talent' => 'required|string|max:2000',

                // photo fichier
                'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            $edition = Edition::findOrFail($data['edition_id']);

            if (
                !$edition->inscriptions_ouvertes ||
                $edition->date_fin_inscriptions < now()
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les inscriptions sont fermées pour cette édition.'
                ], 400);
            }

            $category = Category::where([
                'id' => $data['category_id'],
                'edition_id' => $edition->id,
                'active' => true
            ])->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Catégorie invalide pour cette édition.'
                ], 400);
            }

            $uploadApi = new \Cloudinary\Api\Upload\UploadApi();

            $upload = $uploadApi->upload(
                $request->file('photo')->getRealPath(),
                [
                    'folder' => 'candidats/photos',
                    'public_id' => uniqid('photo_'),
                    'overwrite' => true
                ]
            );

            $photoCloudUrl = $upload['secure_url'];

            $user = User::where('email', $data['email'])->first();

            if (!$user) {
                $user = User::create([
                    'nom' => $data['nom'],
                    'name' => $data['nom'],
                    'prenoms' => $data['prenoms'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['nom']), // temporaire
                    'date_naissance' => $data['date_naissance'],
                    'sexe' => $data['sexe'],
                    'telephone' => $data['telephone'],
                    'origine' => $data['origine'],
                    'ethnie' => $data['ethnie'],
                    'universite' => $data['universite'],
                    'filiere' => $data['filiere'],
                    'annee_etude' => $data['annee_etude'],
                    'type_compte' => 'candidat',
                    'compte_actif' => false,

                    'photo_url' => $photoCloudUrl,
                ]);

                $user->assignRole('candidat');

                $user->matricule = $this->generateMatricule(
                    $edition->numero_edition,
                    $edition->annee,
                    $user->id
                );
                $user->save();
            }

            $alreadyExists = Candidature::where([
                'candidat_id' => $user->id,
                'edition_id' => $edition->id,
                'category_id' => $category->id
            ])->exists();

            if ($alreadyExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous avez déjà postulé à cette catégorie.'
                ], 400);
            }

            $candidature = Candidature::create([
                'candidat_id' => $user->id,
                'edition_id' => $edition->id,
                'category_id' => $category->id,

                // lien vidéo du frontend
                'video_url' => $data['video_url'],

                'description_talent' => $data['description_talent'],
                'statut' => 'en_attente',
                'phase_actuelle' => 1,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Candidature soumise avec succès.',
                'data' => [
                    'candidature_id' => $candidature->id,
                    'matricule' => $user->matricule,
                    'photo_url' => $user->photo_url,
                    'video_url' => $candidature->video_url,
                    'edition' => $edition->nom,
                    'categorie' => $category->nom,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la soumission de la candidature.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Générer un matricule unique
     */
    private function generateMatricule($editionNumber, $year, $userId){
        $yearShort = substr($year, -2);
        $prefix = "SYT{$editionNumber}{$yearShort}";
        $userIdPadded = str_pad($userId, 6, '0', STR_PAD_LEFT);
        
        $matricule = $prefix . $userIdPadded;
        
        return $matricule;
    }

    // Voir mes candidatures
    public function getMesCandidatures(Request $request){
        $user = $request->user();
        
        $candidatures = $user->candidatures()
                            ->with(['edition', 'category'])
                            ->orderBy('created_at', 'desc')
                            ->paginate(15);

        return CandidatureResource::collection($candidatures);
    }

    // Mettre à jour mon profil
    public function updateProfil(Request $request){
        $user = $request->user();

        $request->validate([
            'nom' => 'sometimes|string|max:100',
            'prenoms' => 'sometimes|string|max:200',
            'telephone' => 'sometimes|string|max:20',
            'photo_url' => 'sometimes|url|max:500',
            'universite' => 'sometimes|string|max:200',
            'filiere' => 'sometimes|string|max:150',
            'annee_etude' => 'sometimes|string|max:50',
        ]);

        // Empêcher la modification de l'email et date de naissance
        $allowedFields = ['nom', 'prenoms', 'telephone', 'photo_url', 'universite', 'filiere', 'annee_etude'];
        
        $user->update($request->only($allowedFields));

        return response()->json([
            'message' => 'Profil mis à jour avec succès.',
            'user' => $user
        ]);
    }

    // Voter pour un candidat
    public function voter(Request $request){
        $user = $request->user();
        $candidature = Candidature::findOrFail($request->candidature_id);

        // Vérifier que les votes sont ouverts pour cette édition
        if (!$candidature->edition->votes_ouverts) {
            return response()->json(['message' => 'Les votes ne sont pas ouverts pour cette édition.'], 400);
        }

        // Vérifier que le candidat n'est pas dans la même candidature
        if ($candidature->candidat_id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas voter pour vous-même.'], 400);
        }

        // Vérifier si l'utilisateur a déjà voté pour cette candidature
        $existingVote = $candidature->votes()->where('votant_id', $user->id)->first();
        if ($existingVote) {
            return response()->json(['message' => 'Vous avez déjà voté pour ce candidat.'], 400);
        }

        // TODO: Implémenter le système de paiement
        // Pour l'instant, on crée juste le vote sans paiement

        $vote = $candidature->votes()->create([
            'votant_id' => $user->id,
            'montant' => $request->montant ?? 0,
        ]);

        // Mettre à jour le nombre de votes
        $candidature->increment('nombre_votes');

        return response()->json([
            'message' => 'Vote enregistré avec succès.',
            'vote' => $vote
        ]);
    }
}