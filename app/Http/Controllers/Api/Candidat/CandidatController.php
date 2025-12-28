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

    public function postuler(Request $request)
    {
        DB::beginTransaction();

        try {
            \Log::info('=== NOUVELLE CANDIDATURE ===');
            \Log::info('Headers:', $request->headers->all());
            \Log::info('Tous les champs reçus:', $request->all());
            \Log::info('Fichier photo:', ['has_file' => $request->hasFile('photo')]);
            
            if ($request->hasFile('photo')) {
                \Log::info('Photo info:', [
                    'name' => $request->file('photo')->getClientOriginalName(),
                    'size' => $request->file('photo')->getSize(),
                    'mime' => $request->file('photo')->getMimeType(),
                ]);
            }

            $validator = Validator::make($request->all(), [
                'nom' => 'required|string|min:2|max:50',
                'prenoms' => 'required|string|min:2|max:100',
                'email' => 'required|email',
                'date_naissance' => 'required|date|before:-10 years',
                'sexe' => 'required|in:M,F,Autre',
                'telephone' => 'required|string|min:8|max:20',
                'origine' => 'required|string|max:100',
                'ethnie' => 'nullable|string|max:100',
                'universite' => 'required|string|max:200',
                'filiere' => 'required|string|max:200',
                'annee_etude' => 'required|string',
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'required|exists:categories,id',
                'video_url' => 'required|url|max:500',
                'description_talent' => 'nullable|string|max:2000',
                'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            ]);

            if ($validator->fails()) {
                \Log::error('Validation failed', ['errors' => $validator->errors()->toArray()]);
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            \Log::info('Données validées:', $data);

            $edition = Edition::findOrFail($data['edition_id']);
            \Log::info('Édition trouvée:', ['id' => $edition->id, 'nom' => $edition->nom]);

            if (!$edition->inscriptions_ouvertes || $edition->date_fin_inscriptions < now()) {
                \Log::warning('Inscriptions fermées pour édition', ['edition_id' => $edition->id]);
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
                \Log::warning('Catégorie invalide', [
                    'category_id' => $data['category_id'],
                    'edition_id' => $edition->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Catégorie invalide pour cette édition.'
                ], 400);
            }

            \Log::info('Catégorie trouvée:', ['id' => $category->id, 'nom' => $category->nom]);

            // Upload vers Cloudinary
            \Log::info('Début upload Cloudinary...');
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
            \Log::info('Photo uploadée', ['url' => $photoCloudUrl]);

            // Chercher ou créer l'utilisateur
            $user = User::where('email', $data['email'])->first();

            if (!$user) {
                \Log::info('Création d\'un nouvel utilisateur...');
                
                // Vérifier les champs requis pour PostgreSQL
                $userData = [
                    'nom' => $data['nom'],
                    'prenoms' => $data['prenoms'],
                    'email' => $data['email'],
                    'password' => Hash::make(Str::random(12)), // Mot de passe aléatoire
                    'date_naissance' => $data['date_naissance'],
                    'sexe' => $data['sexe'],
                    'telephone' => $data['telephone'],
                    'origine' => $data['origine'],
                    'ethnie' => $data['ethnie'] ?? null,
                    'universite' => $data['universite'],
                    'filiere' => $data['filiere'],
                    'annee_etude' => $data['annee_etude'],
                    'type_compte' => 'candidat',
                    'compte_actif' => false,
                    'photo_url' => $photoCloudUrl,
                    'email_verified_at' => null,
                    'remember_token' => null,
                ];
                
                \Log::info('Données utilisateur à créer:', $userData);
                
                $user = User::create($userData);
                \Log::info('Utilisateur créé', ['id' => $user->id]);

                $user->assignRole('candidat');

                $user->matricule = $this->generateMatricule(
                    $edition->numero_edition,
                    $edition->annee,
                    $user->id
                );
                $user->save();
                \Log::info('Matricule généré', ['matricule' => $user->matricule]);
            } else {
                \Log::info('Utilisateur existant trouvé', ['id' => $user->id, 'email' => $user->email]);
                
                // Mettre à jour la photo si elle a changé
                $user->photo_url = $photoCloudUrl;
                $user->save();
            }

            // Vérifier si candidature existe déjà
            $alreadyExists = Candidature::where([
                'candidat_id' => $user->id,
                'edition_id' => $edition->id,
                'category_id' => $category->id
            ])->exists();

            if ($alreadyExists) {
                \Log::warning('Candidature existe déjà', [
                    'user_id' => $user->id,
                    'edition_id' => $edition->id,
                    'category_id' => $category->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Vous avez déjà postulé à cette catégorie.'
                ], 400);
            }

            \Log::info('Création de la candidature...');
            $candidatureData = [
                'candidat_id' => $user->id,
                'edition_id' => $edition->id,
                'category_id' => $category->id,
                'video_url' => $data['video_url'],
                'description_talent' => $data['description_talent'] ?? null,
                'statut' => 'en_attente',
                'phase_actuelle' => 1,
            ];
            
            \Log::info('Données candidature:', $candidatureData);
            
            $candidature = Candidature::create($candidatureData);
            \Log::info('Candidature créée', ['id' => $candidature->id]);

            DB::commit();

            \Log::info('=== CANDIDATURE SUCCESS ===', [
                'candidature_id' => $candidature->id,
                'user_id' => $user->id,
                'matricule' => $user->matricule
            ]);

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
            \Log::error('=== ERREUR CANDIDATURE ===');
            \Log::error('Message', ['error' => $e->getMessage()]);
            \Log::error('File', ['file' => $e->getFile()]);
            \Log::error('Line', ['line' => $e->getLine()]);
            \Log::error('Trace', ['trace' => $e->getTraceAsString()]);
            
            // Log spécifique pour les erreurs SQL
            if (str_contains($e->getMessage(), 'SQLSTATE')) {
                \Log::error('SQL ERROR DETAILS', [
                    'full_message' => $e->getMessage(),
                    'sql_state' => $e->getCode()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la soumission de la candidature.'.$e->getMessage(),
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Erreur interne du serveur'
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