<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Login user and create token
     */
    public function login(Request $request)
    {
        try {
            Log::info('Login attempt', ['email' => $request->email]);
            
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required'
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Rechercher l'utilisateur par email
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                Log::warning('User not found', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'message' => 'Email ou mot de passe incorrect'
                ], 401);
            }

            // Vérifier le mot de passe
            if (!Hash::check($request->password, $user->password)) {
                Log::warning('Password mismatch', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'message' => 'Email ou mot de passe incorrect'
                ], 401);
            }

            // Vérifier si le compte est actif
            if (!$user->compte_actif) {
                Log::warning('Account inactive', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'message' => 'Votre compte est désactivé. Contactez l\'administrateur.'
                ], 403);
            }

            // Créer un token Sanctum avec un nom explicite
            Log::info('Creating token for user', ['user_id' => $user->id]);
            
            // CORRECTION ICI : Ajouter un nom de token explicite
            $token = $user->createToken('auth_token')->plainTextToken;

            // Charger les rôles
            $user->load('roles');
            
            // Formater les rôles pour le frontend
            $roles = $user->roles->pluck('name')->toArray();
            
            // Créer une réponse simple sans problèmes de sérialisation
            $userData = [
                'id' => $user->id,
                'nom' => $user->nom,
                'prenoms' => $user->prenoms,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'type_compte' => $user->type_compte,
                'photo_url' => $user->photo_url,
                'universite' => $user->universite,
                'compte_actif' => $user->compte_actif,
                'roles' => $roles,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at
            ];

            Log::info('Login successful', ['user_id' => $user->id]);
            
            return response()->json([
                'success' => true,
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $userData
            ]);

        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'email' => $request->email ?? 'unknown'
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            // Charger les rôles
            $user->load('roles');
            
            // Formater les rôles
            $roles = $user->roles->pluck('name')->toArray();
            
            // Créer une réponse simple
            $userData = [
                'id' => $user->id,
                'nom' => $user->nom,
                'prenoms' => $user->prenoms,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'type_compte' => $user->type_compte,
                'photo_url' => $user->photo_url,
                'universite' => $user->universite,
                'compte_actif' => $user->compte_actif,
                'roles' => $roles,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at
            ];

            return response()->json([
                'success' => true,
                'user' => $userData
            ]);

        } catch (\Exception $e) {
            Log::error('Get user error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Logout user (revoke token)
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            
            if ($user) {
                // Révoquer le token courant
                $request->user()->currentAccessToken()->delete();
                Log::info('User logged out', ['user_id' => $user->id]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie'
            ]);

        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }
}