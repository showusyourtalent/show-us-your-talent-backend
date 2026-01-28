<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class PaymentController extends Controller
{
    private $votePrice = 100;

    /**
     * Initialiser un paiement - Version FORCÉE sans transaction
     */
    public function initiatePayment(Request $request): JsonResponse {
        // FORCER la fin de TOUTES les transactions PostgreSQL
        $this->forceCleanTransactions();
        
        try {
            // Validation MANUELLE ultra-légère sans aucun appel à la base
            $errors = $this->validatePaymentRequestLight($request);
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $errors
                ], 422);
            }

            $data = $request->all();
            
            // Valider le téléphone localement
            $phone = $this->validateAndFormatPhone($data['phone']);
            if (!$phone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Numéro de téléphone invalide'
                ], 422);
            }

            // FORCER une nouvelle connexion propre
            $connection = $this->getFreshConnection();
            
            // Vérifier si l'édition existe avec une connexion FORCÉE
            $editionExists = $this->forceCheckRecordExists($connection, 'editions', $data['edition_id']);
            

            // Vérifier le candidat
            $candidatExists = $this->forceCheckRecordExists($connection, 'users', $data['candidat_id']);
            
            if (!$candidatExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Candidat non trouvé'
                ], 404);
            }

            // Vérifier la catégorie si fournie
            if (!empty($data['category_id'])) {
                $categoryExists = $this->forceCheckRecordExists($connection, 'categories', $data['category_id']);
                
                if (!$categoryExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Catégorie non trouvée'
                    ], 404);
                }
                
                // Vérifier la candidature
                $candidatureExists = $this->forceCheckCandidatureExists(
                    $connection,
                    $data['candidat_id'],
                    $data['edition_id'],
                    $data['category_id']
                );
                    
                if (!$candidatureExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ce candidat ne participe pas à cette catégorie.'
                    ], 400);
                }
            }

            // Récupérer les infos pour le paiement (nom, etc.)
            $editionInfo = $this->forceGetRecordInfo($connection, 'editions', $data['edition_id'], ['nom', 'date_debut_vote', 'date_fin_vote']);
            $candidatInfo = $this->forceGetRecordInfo($connection, 'users', $data['candidat_id'], ['nom', 'prenoms', 'nom_complet']);
            
            $categoryName = 'Non spécifiée';
            if (!empty($data['category_id'])) {
                $categoryInfo = $this->forceGetRecordInfo($connection, 'categories', $data['category_id'], ['nom']);
                $categoryName = $categoryInfo->nom ?? 'Non spécifiée';
            }

            // Vérifier si les votes sont ouverts
            $isVoteOpen = false;
            $editionName = $editionInfo->nom ?? 'Édition';
            
            if ($editionInfo) {
                $dateDebut = $editionInfo->date_debut_vote ? Carbon::parse($editionInfo->date_debut_vote) : null;
                $dateFin = $editionInfo->date_fin_vote ? Carbon::parse($editionInfo->date_fin_vote) : null;
                
                $now = Carbon::now();
                if ($dateDebut && $dateFin) {
                    $isVoteOpen = $now->between($dateDebut, $dateFin);
                }
            }

            $amount = $this->votePrice * $data['votes_count'];
            $candidatName = $candidatInfo->nom_complet ?? ($candidatInfo->nom ?? '') . ' ' . ($candidatInfo->prenoms ?? '');

            // Créer le paiement avec connexion FORCÉE
            $paymentData = $this->forceCreatePayment($connection, [
                'reference' => 'VOTE-' . strtoupper(Str::random(10)),
                'user_id' => null,
                'edition_id' => $data['edition_id'],
                'candidat_id' => $data['candidat_id'],
                'category_id' => !empty($data['category_id']) ? $data['category_id'] : null,
                'amount' => $amount,
                'currency' => 'XOF',
                'payment_token' => Str::uuid(),
                'customer_email' => $data['email'],
                'customer_phone' => $phone,
                'customer_firstname' => $data['firstname'],
                'customer_lastname' => $data['lastname'],
                'votes_count' => $data['votes_count'],
                'candidat_name' => $candidatName,
                'edition_name' => $editionName,
                'category_name' => $categoryName,
                'is_vote_open' => $isVoteOpen,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            if (!$paymentData) {
                throw new \Exception('Échec de la création du paiement');
            }

            // Fermer la connexion proprement
            $this->closeConnection($connection);

            $responseData = [
                'success' => true,
                'message' => 'Paiement initialisé avec succès',
                'data' => [
                    'payment_token' => $paymentData['payment_token'],
                    'amount' => $amount,
                    'currency' => 'XOF',
                    'votes_count' => $data['votes_count'],
                    'candidat_name' => $candidatName,
                    'edition_name' => $editionName,
                    'category_name' => $categoryName,
                    'is_vote_open' => $isVoteOpen,
                    'expires_at' => $paymentData['expires_at'],
                    'check_status_url' => url("/api/payments/{$paymentData['payment_token']}/status"),
                    'success_url' => url("/payments/{$paymentData['payment_token']}/success/redirect"),
                    'failed_url' => url("/payments/{$paymentData['payment_token']}/failed/redirect")
                ]
            ];

            // Avertissement si votes fermés
            if (!$isVoteOpen) {
                $responseData['warning'] = 'Les votes ne sont actuellement pas ouverts pour cette édition. Le paiement peut être effectué, mais les votes seront comptabilisés uniquement lorsque les votes seront ouverts.';
                $responseData['data']['vote_status'] = 'closed';
            } else {
                $responseData['data']['vote_status'] = 'open';
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            Log::error('Erreur initiation paiement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'initialisation du paiement'
            ], 500);
        }
    }

    /**
     * FORCER le nettoyage des transactions PostgreSQL
     */
    private function forceCleanTransactions(): void {
        try {
            // Obtenir une connexion fraîche
            $pdo = DB::connection('pgsql')->getPdo();
            
            // 1. Rollback de TOUTES les transactions en cours
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            // 2. Exécuter une commande PostgreSQL pour nettoyer
            $pdo->exec("DISCARD ALL");
            
            // 3. Réinitialiser la connexion
            DB::purge('pgsql');
            
            Log::info('Transactions PostgreSQL nettoyées forcément');
            
        } catch (\Exception $e) {
            Log::warning('Erreur nettoyage transactions', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Obtenir une connexion FRAÎCHE sans transaction
     */
    private function getFreshConnection() {
        try {
            // Purger la connexion existante
            DB::purge('pgsql');
            
            // Obtenir une nouvelle connexion
            $connection = DB::connection('pgsql')->getPdo();
            
            // S'assurer qu'il n'y a pas de transaction
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            
            // Désactiver l'auto-commit pour contrôler manuellement
            $connection->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
            
            return $connection;
            
        } catch (\Exception $e) {
            Log::error('Erreur obtention connexion fraîche', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Fermer une connexion proprement
     */
    private function closeConnection($connection): void {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $connection = null;
        } catch (\Exception $e) {
            // Ignorer les erreurs de fermeture
        }
    }

    /**
     * Validation ultra-légère sans base de données
     */
    private function validatePaymentRequestLight(Request $request): array {
        $errors = [];
        $data = $request->all();

        // Validation simple
        if (empty($data['candidat_id']) || !is_numeric($data['candidat_id'])) {
            $errors['candidat_id'] = ['Le champ candidat est requis et doit être numérique.'];
        }

        if (empty($data['edition_id']) || !is_numeric($data['edition_id'])) {
            $errors['edition_id'] = ['Le champ édition est requis et doit être numérique.'];
        }

        if (!empty($data['category_id']) && !is_numeric($data['category_id'])) {
            $errors['category_id'] = ['Le champ catégorie doit être numérique.'];
        }

        if (empty($data['votes_count']) || !is_numeric($data['votes_count']) || $data['votes_count'] < 1 || $data['votes_count'] > 100) {
            $errors['votes_count'] = ['Le nombre de votes doit être un nombre entre 1 et 100.'];
        }

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 100) {
            $errors['email'] = ['L\'email doit être une adresse valide et ne pas dépasser 100 caractères.'];
        }

        if (empty($data['phone']) || strlen($data['phone']) < 8 || strlen($data['phone']) > 15) {
            $errors['phone'] = ['Le téléphone doit avoir entre 8 et 15 caractères.'];
        }

        if (empty($data['firstname']) || strlen($data['firstname']) > 50) {
            $errors['firstname'] = ['Le prénom est requis et ne doit pas dépasser 50 caractères.'];
        }

        if (empty($data['lastname']) || strlen($data['lastname']) > 50) {
            $errors['lastname'] = ['Le nom est requis et ne doit pas dépasser 50 caractères.'];
        }

        return $errors;
    }

    /**
     * Vérifier si un enregistrement existe avec connexion FORCÉE
     */
    private function forceCheckRecordExists($connection, string $table, $id): bool {
        try {
            // S'assurer qu'il n'y a pas de transaction
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            
            $stmt = $connection->prepare("SELECT 1 FROM {$table} WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            return (bool) $stmt->fetch(\PDO::FETCH_COLUMN);
            
        } catch (\Exception $e) {
            Log::warning('Erreur vérification existence', [
                'table' => $table,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupérer des infos d'un enregistrement avec connexion FORCÉE
     */
    private function forceGetRecordInfo($connection, string $table, $id, array $columns) {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            
            $columnsStr = implode(', ', $columns);
            $stmt = $connection->prepare("SELECT {$columnsStr} FROM {$table} WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            return $stmt->fetch(\PDO::FETCH_OBJ);
            
        } catch (\Exception $e) {
            Log::warning('Erreur récupération infos', [
                'table' => $table,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Vérifier une candidature avec connexion FORCÉE
     */
    private function forceCheckCandidatureExists($connection, $candidatId, $editionId, $categoryId): bool {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            
            $stmt = $connection->prepare("
                SELECT 1 FROM candidatures 
                WHERE candidat_id = :candidat_id 
                AND edition_id = :edition_id 
                AND category_id = :category_id 
                LIMIT 1
            ");
            
            $stmt->execute([
                ':candidat_id' => $candidatId,
                ':edition_id' => $editionId,
                ':category_id' => $categoryId
            ]);
            
            return (bool) $stmt->fetch(\PDO::FETCH_COLUMN);
            
        } catch (\Exception $e) {
            Log::warning('Erreur vérification candidature', [
                'candidat_id' => $candidatId,
                'edition_id' => $editionId,
                'category_id' => $categoryId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Créer un paiement avec connexion FORCÉE
     */
    private function forceCreatePayment($connection, array $data): ?array {
        try {
            // S'assurer qu'il n'y a pas de transaction
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            
            $paymentToken = $data['payment_token'] ?? Str::uuid();
            $metadata = json_encode([
                'votes_count' => $data['votes_count'],
                'vote_price' => $this->votePrice,
                'candidat_name' => $data['candidat_name'],
                'edition_name' => $data['edition_name'],
                'category_name' => $data['category_name'],
                'is_vote_open' => $data['is_vote_open'],
                'ip_address' => $data['ip_address'],
                'user_agent' => $data['user_agent'],
                'created_at' => Carbon::now()->toISOString()
            ]);
            
            $expiresAt = Carbon::now()->addMinutes(30);
            $now = Carbon::now();
            
            $sql = "
                INSERT INTO payments (
                    reference, user_id, edition_id, candidat_id, category_id,
                    transaction_id, amount, montant, currency, status,
                    payment_token, payment_method, customer_email, email_payeur,
                    customer_phone, customer_firstname, customer_lastname,
                    metadata, expires_at, created_at, updated_at
                ) VALUES (
                    :reference, :user_id, :edition_id, :candidat_id, :category_id,
                    :transaction_id, :amount, :montant, :currency, :status,
                    :payment_token, :payment_method, :customer_email, :email_payeur,
                    :customer_phone, :customer_firstname, :customer_lastname,
                    :metadata, :expires_at, :created_at, :updated_at
                ) RETURNING id, payment_token, expires_at
            ";
            
            $stmt = $connection->prepare($sql);
            $stmt->execute([
                ':reference' => $data['reference'],
                ':user_id' => $data['user_id'],
                ':edition_id' => $data['edition_id'],
                ':candidat_id' => $data['candidat_id'],
                ':category_id' => $data['category_id'],
                ':transaction_id' => null,
                ':amount' => $data['amount'],
                ':montant' => $data['amount'],
                ':currency' => $data['currency'],
                ':status' => 'pending',
                ':payment_token' => $paymentToken,
                ':payment_method' => null,
                ':customer_email' => $data['customer_email'],
                ':email_payeur' => $data['customer_email'],
                ':customer_phone' => $data['customer_phone'],
                ':customer_firstname' => $data['customer_firstname'],
                ':customer_lastname' => $data['customer_lastname'],
                ':metadata' => $metadata,
                ':expires_at' => $expiresAt,
                ':created_at' => $now,
                ':updated_at' => $now
            ]);
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'id' => $result['id'] ?? null,
                'payment_token' => $result['payment_token'] ?? $paymentToken,
                'expires_at' => $result['expires_at'] ?? $expiresAt->toISOString()
            ];
            
        } catch (\Exception $e) {
            Log::error('Erreur création paiement', [
                'error' => $e->getMessage(),
                'data_keys' => array_keys($data)
            ]);
            return null;
        }
    }

    /**
     * Valider et formater le téléphone (inchangé)
     */
    private function validateAndFormatPhone($phone): ?string {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        
        if (empty($cleanPhone) || strlen($cleanPhone) < 8) {
            return null;
        }

        if (str_starts_with($cleanPhone, '0') && strlen($cleanPhone) === 10) {
            return '229' . substr($cleanPhone, 1);
        }

        if (str_starts_with($cleanPhone, '229')) {
            if (strlen($cleanPhone) === 11) {
                return $cleanPhone;
            }
            if (strlen($cleanPhone) > 11) {
                return substr($cleanPhone, 0, 11);
            }
        }

        if (strlen($cleanPhone) >= 8 && strlen($cleanPhone) <= 9) {
            $base = substr($cleanPhone, 0, 8);
            return '229' . $base;
        }

        return null;
    }

    /**
     * Pour les autres méthodes, utiliser le même pattern
     * 1. forceCleanTransactions() au début
     * 2. getFreshConnection() pour chaque opération
     * 3. closeConnection() à la fin
     */
    
    /**
     * Vérifier le statut du paiement
     */
    public function checkPaymentStatus($token): JsonResponse{
        $this->forceCleanTransactions();
        $connection = $this->getFreshConnection();
        
        try {
            $payment = $this->forceGetPaymentByToken($connection, $token);
            
            if (!$payment) {
                $this->closeConnection($connection);
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé'
                ], 404);
            }
            
            $metadata = json_decode($payment->metadata, true) ?? [];
            
            // Vérifier l'expiration
            $isExpired = $payment->expires_at && Carbon::parse($payment->expires_at)->isPast();
            if ($isExpired && $payment->status === 'pending') {
                $this->forceUpdatePaymentStatus($connection, $payment->id, 'expired');
                $payment->status = 'expired';
            }
            
            $this->closeConnection($connection);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $payment->status,
                    'is_successful' => in_array($payment->status, ['approved', 'completed', 'paid', 'success']),
                    'is_expired' => $isExpired,
                    'amount' => $payment->amount,
                    'votes_count' => $metadata['votes_count'] ?? 1,
                    'candidat_name' => $metadata['candidat_name'] ?? '',
                    'created_at' => Carbon::parse($payment->created_at)->toISOString(),
                    'paid_at' => $payment->paid_at ? Carbon::parse($payment->paid_at)->toISOString() : null,
                    'payment_method' => $payment->payment_method,
                    'transaction_id' => $payment->transaction_id,
                    'reference' => $payment->reference,
                    'payment_token' => $payment->payment_token,
                    'check_interval' => 3000
                ]
            ]);

        } catch (\Exception $e) {
            $this->closeConnection($connection);
            Log::error('Erreur vérification statut paiement', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification du statut'
            ], 500);
        }
    }
    
    /**
     * Récupérer un paiement par token
     */
    private function forceGetPaymentByToken($connection, string $token) {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            
            $stmt = $connection->prepare("SELECT * FROM payments WHERE payment_token = :token LIMIT 1");
            $stmt->execute([':token' => $token]);
            return $stmt->fetch(\PDO::FETCH_OBJ);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération paiement', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Mettre à jour le statut d'un paiement
     */
    private function forceUpdatePaymentStatus($connection, $paymentId, $status): bool {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            
            $stmt = $connection->prepare("
                UPDATE payments 
                SET status = :status, updated_at = :updated_at 
                WHERE id = :id
            ");
            
            return $stmt->execute([
                ':status' => $status,
                ':updated_at' => Carbon::now(),
                ':id' => $paymentId
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur mise à jour statut', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * REDÉMARRER COMPLÈTEMENT la connexion PostgreSQL
     * À exécuter MANUELLEMENT si l'erreur persiste
     */
    public function resetPostgresConnection(): JsonResponse {
        try {
            // 1. Fermer toutes les connexions existantes
            DB::disconnect('pgsql');
            
            // 2. Purger complètement
            DB::purge('pgsql');
            
            // 3. Recréer la connexion
            DB::reconnect('pgsql');
            
            // 4. Exécuter une requête de test
            $test = DB::select('SELECT 1 as test');
            
            return response()->json([
                'success' => true,
                'message' => 'Connexion PostgreSQL redémarrée avec succès',
                'test' => $test
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Échec du redémarrage: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Validation manuelle SANS transaction
     */
    private function validatePaymentRequest(Request $request): array {
        $errors = [];
        $data = $request->all();

        // Validation du candidat
        if (empty($data['candidat_id'])) {
            $errors['candidat_id'] = ['Le champ candidat est requis.'];
        } else {
            // Vérification directe SANS Eloquent
            $candidatExists = $this->recordExists('users', $data['candidat_id']);
            if (!$candidatExists) {
                $errors['candidat_id'] = ['Le candidat sélectionné n\'existe pas.'];
            }
        }

        // Validation de l'édition
        if (empty($data['edition_id'])) {
            $errors['edition_id'] = ['Le champ édition est requis.'];
        } else {
            $editionExists = $this->recordExists('editions', $data['edition_id']);
            if (!$editionExists) {
                $errors['edition_id'] = ['L\'édition sélectionnée n\'existe pas.'];
            }
        }

        // Validation de la catégorie (optionnelle)
        if (!empty($data['category_id'])) {
            $categoryExists = $this->recordExists('categories', $data['category_id']);
            if (!$categoryExists) {
                $errors['category_id'] = ['La catégorie sélectionnée n\'existe pas.'];
            }
        }

        // Validation des votes
        if (empty($data['votes_count'])) {
            $errors['votes_count'] = ['Le nombre de votes est requis.'];
        } elseif (!is_numeric($data['votes_count']) || $data['votes_count'] < 1 || $data['votes_count'] > 100) {
            $errors['votes_count'] = ['Le nombre de votes doit être entre 1 et 100.'];
        }

        // Validation de l'email
        if (empty($data['email'])) {
            $errors['email'] = ['L\'email est requis.'];
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['L\'email doit être une adresse email valide.'];
        } elseif (strlen($data['email']) > 100) {
            $errors['email'] = ['L\'email ne doit pas dépasser 100 caractères.'];
        }

        // Validation du téléphone
        if (empty($data['phone'])) {
            $errors['phone'] = ['Le téléphone est requis.'];
        } elseif (strlen($data['phone']) < 8 || strlen($data['phone']) > 15) {
            $errors['phone'] = ['Le téléphone doit avoir entre 8 et 15 caractères.'];
        }

        // Validation du prénom
        if (empty($data['firstname'])) {
            $errors['firstname'] = ['Le prénom est requis.'];
        } elseif (strlen($data['firstname']) > 50) {
            $errors['firstname'] = ['Le prénom ne doit pas dépasser 50 caractères.'];
        }

        // Validation du nom
        if (empty($data['lastname'])) {
            $errors['lastname'] = ['Le nom est requis.'];
        } elseif (strlen($data['lastname']) > 50) {
            $errors['lastname'] = ['Le nom ne doit pas dépasser 50 caractères.'];
        }

        return $errors;
    }

    /**
     * Vérifier si un enregistrement existe SANS transaction
     */
    private function recordExists(string $table, $id): bool {
        try {
            // Utiliser une nouvelle connexion PDO directe pour éviter les transactions
            $pdo = DB::connection()->getPdo();
            
            // Désactiver les éventuelles transactions en cours
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $stmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM {$table} WHERE id = :id)");
            $stmt->execute([':id' => $id]);
            return (bool) $stmt->fetchColumn();
            
        } catch (\Exception $e) {
            Log::warning('Erreur vérification existence enregistrement', [
                'table' => $table,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupérer une édition SANS transaction
     */
    private function getEditionSafely($id) {
        try {
            $pdo = DB::connection()->getPdo();
            
            // S'assurer qu'il n'y a pas de transaction en cours
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM editions WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::warning('Erreur récupération édition', [
                'edition_id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupérer un candidat SANS transaction
     */
    private function getCandidatSafely($id) {
        try {
            $pdo = DB::connection()->getPdo();
            
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::warning('Erreur récupération candidat', [
                'candidat_id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupérer une catégorie SANS transaction
     */
    private function getCategorySafely($id) {
        try {
            $pdo = DB::connection()->getPdo();
            
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::warning('Erreur récupération catégorie', [
                'category_id' => $id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Vérifier si une candidature existe SANS transaction
     */
    private function checkCandidatureExists($candidatId, $editionId, $categoryId): bool {
        try {
            $pdo = DB::connection()->getPdo();
            
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $stmt = $pdo->prepare("
                SELECT EXISTS(
                    SELECT 1 FROM candidatures 
                    WHERE candidat_id = :candidat_id 
                    AND edition_id = :edition_id 
                    AND category_id = :category_id
                )
            ");
            
            $stmt->execute([
                ':candidat_id' => $candidatId,
                ':edition_id' => $editionId,
                ':category_id' => $categoryId
            ]);
            
            return (bool) $stmt->fetchColumn();
            
        } catch (\Exception $e) {
            Log::warning('Erreur vérification candidature', [
                'candidat_id' => $candidatId,
                'edition_id' => $editionId,
                'category_id' => $categoryId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Créer un paiement SANS transaction
     */
    private function createPaymentSafely(array $data): ?array {
        try {
            $pdo = DB::connection()->getPdo();
            
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $paymentToken = $data['payment_token'] ?? Str::uuid();
            $metadata = json_encode([
                'votes_count' => $data['votes_count'],
                'vote_price' => $data['vote_price'],
                'candidat_name' => $data['candidat_name'],
                'edition_name' => $data['edition_name'],
                'category_name' => $data['category_name'],
                'is_vote_open' => $data['is_vote_open'],
                'ip_address' => $data['ip_address'],
                'user_agent' => $data['user_agent'],
                'created_at' => Carbon::now()->toISOString()
            ]);
            
            $expiresAt = Carbon::now()->addMinutes(30);
            
            $sql = "
                INSERT INTO payments (
                    reference, user_id, edition_id, candidat_id, category_id,
                    transaction_id, amount, montant, currency, status,
                    payment_token, payment_method, customer_email, email_payeur,
                    customer_phone, customer_firstname, customer_lastname,
                    metadata, expires_at, created_at, updated_at
                ) VALUES (
                    :reference, :user_id, :edition_id, :candidat_id, :category_id,
                    :transaction_id, :amount, :montant, :currency, :status,
                    :payment_token, :payment_method, :customer_email, :email_payeur,
                    :customer_phone, :customer_firstname, :customer_lastname,
                    :metadata, :expires_at, :created_at, :updated_at
                ) RETURNING id
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':reference' => $data['reference'],
                ':user_id' => $data['user_id'],
                ':edition_id' => $data['edition_id'],
                ':candidat_id' => $data['candidat_id'],
                ':category_id' => $data['category_id'],
                ':transaction_id' => null,
                ':amount' => $data['amount'],
                ':montant' => $data['amount'],
                ':currency' => $data['currency'],
                ':status' => 'pending',
                ':payment_token' => $paymentToken,
                ':payment_method' => null,
                ':customer_email' => $data['customer_email'],
                ':email_payeur' => $data['customer_email'],
                ':customer_phone' => $data['customer_phone'],
                ':customer_firstname' => $data['customer_firstname'],
                ':customer_lastname' => $data['customer_lastname'],
                ':metadata' => $metadata,
                ':expires_at' => $expiresAt,
                ':created_at' => Carbon::now(),
                ':updated_at' => Carbon::now()
            ]);
            
            $paymentId = $stmt->fetchColumn();
            
            return [
                'id' => $paymentId,
                'payment_token' => $paymentToken,
                'expires_at' => $expiresAt
            ];
            
        } catch (\Exception $e) {
            Log::error('Erreur création paiement', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            return null;
        }
    }

    /**
     * Traiter le paiement
     */
    public function processPayment(Request $request): JsonResponse{
        try {
            // Validation basique
            if (empty($request->payment_token) || empty($request->payment_method)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de paiement et méthode de paiement sont requis'
                ], 422);
            }
            
            if (!in_array($request->payment_method, ['mobile_money', 'card'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Méthode de paiement invalide'
                ], 422);
            }

            // Récupérer le paiement SANS transaction
            $payment = $this->getPaymentByToken($request->payment_token);
            
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé'
                ], 404);
            }

            // Vérifier les statuts qui permettent de retenter le paiement
            $allowedStatuses = ['pending', 'processing', 'failed', 'cancelled', 'expired'];
            
            if (!in_array($payment->status, $allowedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce paiement ne peut pas être retenté. Statut: ' . $payment->status
                ], 400);
            }

            // Vérifier si le paiement a expiré
            if ($payment->expires_at && Carbon::parse($payment->expires_at)->isPast()) {
                $this->updatePaymentStatus($payment->id, 'expired');
                return response()->json([
                    'success' => false,
                    'message' => 'Le paiement a expiré'
                ], 400);
            }

            // Vérifier si les votes sont ouverts
            $metadata = json_decode($payment->metadata, true) ?? [];
            $isVoteOpen = $metadata['is_vote_open'] ?? false;

            // Si le paiement était annulé ou échoué, le réinitialiser
            if (in_array($payment->status, ['cancelled', 'failed', 'expired'])) {
                $metadata['previous_status'] = $payment->status;
                $metadata['retry_count'] = ($metadata['retry_count'] ?? 0) + 1;
                $metadata['retry_at'] = Carbon::now()->toISOString();
                
                $this->updatePayment($payment->id, [
                    'status' => 'pending',
                    'transaction_id' => null,
                    'payment_method' => null,
                    'metadata' => json_encode($metadata),
                    'expires_at' => Carbon::now()->addMinutes(30)
                ]);
                
                $payment->status = 'pending';
            }

            return $this->processFedaPayPayment($payment, $request->payment_method);

        } catch (\Exception $e) {
            Log::error('Erreur traitement paiement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du paiement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer un paiement par token SANS transaction
     */
    private function getPaymentByToken(string $token) {
        try {
            $pdo = DB::connection()->getPdo();
            
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE payment_token = :token");
            $stmt->execute([':token' => $token]);
            return $stmt->fetch(\PDO::FETCH_OBJ);
            
        } catch (\Exception $e) {
            Log::error('Erreur récupération paiement', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Mettre à jour le statut d'un paiement SANS transaction
     */
    private function updatePaymentStatus($paymentId, $status): bool {
        return $this->updatePayment($paymentId, ['status' => $status]);
    }

    /**
     * Mettre à jour un paiement SANS transaction
     */
    private function updatePayment($paymentId, array $data): bool {
        try {
            $pdo = DB::connection()->getPdo();
            
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $fields = [];
            $params = [':id' => $paymentId];
            
            foreach ($data as $key => $value) {
                $fields[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            
            $fields[] = "updated_at = :updated_at";
            $params[':updated_at'] = Carbon::now();
            
            $sql = "UPDATE payments SET " . implode(', ', $fields) . " WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
            
        } catch (\Exception $e) {
            Log::error('Erreur mise à jour paiement', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Traiter un paiement FedaPay
     */
    private function processFedaPayPayment($payment, string $paymentMethod): JsonResponse {
        try {
            $apiKey = config('services.fedapay.secret_key');
            $environment = config('services.fedapay.environment', 'live');
            
            if (!$apiKey) {
                throw new \Exception('Clé API FedaPay non configurée');
            }

            $baseUrl = $environment === 'sandbox' 
                ? 'https://sandbox-api.fedapay.com/v1'
                : 'https://api.fedapay.com/v1';

            $phoneNumber = $this->formatPhoneForFedapay($payment->customer_phone);
            
            // URL de callback et de retour
            $callbackUrl = url('/api/payments/webhook');
            $returnUrl = url("/payments/callback?payment_token={$payment->payment_token}");

            if (app()->environment('local')) {
                $ngrokUrl = config('services.ngrok.url');
                if ($ngrokUrl) {
                    $callbackUrl = rtrim($ngrokUrl, '/') . '/api/payments/webhook';
                    $returnUrl = rtrim($ngrokUrl, '/') . "/payments/callback?payment_token={$payment->payment_token}";
                }
            }

            $metadata = json_decode($payment->metadata, true) ?? [];
            
            $transactionData = [
                'description' => sprintf(
                    'Vote pour %s (%d vote%s) - %s',
                    $metadata['candidat_name'] ?? 'Candidat',
                    $metadata['votes_count'] ?? 1,
                    $metadata['votes_count'] > 1 ? 's' : '',
                    $metadata['edition_name'] ?? 'Édition'
                ),
                'amount' => (int) $payment->amount,
                'currency' => ['iso' => 'XOF'],
                'callback_url' => $callbackUrl,
                'cancel_url' => url("/payments/{$payment->payment_token}/cancel"),
                'redirect_url' => $returnUrl,
                'customer' => [
                    'firstname' => substr($payment->customer_firstname, 0, 50),
                    'lastname' => substr($payment->customer_lastname, 0, 50),
                    'email' => $payment->customer_email,
                    'phone_number' => [
                        'number' => $phoneNumber,
                        'country' => 'BJ'
                    ]
                ]
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->timeout(30)->post($baseUrl . '/transactions', $transactionData);

            if (!$response->successful()) {
                $errorDetails = $response->json();
                
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de la création de la transaction',
                    'error' => $errorDetails['message'] ?? 'Erreur inconnue'
                ], $response->status());
            }

            $fedapayData = $response->json();
            $transaction = $fedapayData['v1/transaction'] ?? $fedapayData['data'] ?? null;
            
            if (!$transaction || !isset($transaction['id'])) {
                throw new \Exception('Format de réponse FedaPay invalide');
            }

            $transactionId = $transaction['id'];
            $paymentUrl = $transaction['payment_url'] ?? null;
            
            if (!$paymentUrl) {
                $paymentUrl = $environment === 'sandbox' 
                    ? "https://sandbox-checkout.fedapay.com/{$transactionId}"
                    : "https://process.fedapay.com/{$transaction['payment_token']}";
            }

            // Mettre à jour le paiement
            $metadata = array_merge($metadata, [
                'fedapay_transaction_id' => $transactionId,
                'fedapay_payment_token' => $transaction['payment_token'] ?? null,
                'processed_at' => Carbon::now()->toISOString(),
                'payment_url' => $paymentUrl
            ]);
            
            $this->updatePayment($payment->id, [
                'transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
                'status' => 'processing',
                'metadata' => json_encode($metadata)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transaction créée avec succès',
                'data' => [
                    'redirect_url' => $paymentUrl,
                    'transaction_id' => $transactionId,
                    'payment_token' => $payment->payment_token,
                    'payment_method' => $paymentMethod,
                    'expires_at' => Carbon::parse($payment->expires_at)->toISOString(),
                    'check_status_url' => url("/api/payments/{$payment->payment_token}/status"),
                    'success_redirect_url' => url("/payments/{$payment->payment_token}/success/redirect"),
                    'failed_redirect_url' => url("/payments/{$payment->payment_token}/failed/redirect")
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur FedaPay', [
                'error' => $e->getMessage(),
                'payment_id' => $payment->id ?? 'N/A'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du paiement: ' . $e->getMessage()
            ], 500);
        }
    }


    private function formatPhoneForFedapay($phone): string{
        $phone = $this->validateAndFormatPhone($phone);
        if (!$phone) {
            throw new \Exception('Numéro de téléphone invalide');
        }
        
        if (strlen($phone) !== 11) {
            throw new \Exception('Numéro de téléphone doit être 229 suivi de 8 chiffres');
        }
        
        return '+' . $phone;
    }
   
    /**
     * Mapper le statut FedaPay
     */
    private function mapFedapayStatus($fedapayStatus): string
    {
        $statusMap = [
            'pending' => 'pending',
            'approved' => 'approved',
            'canceled' => 'cancelled',
            'declined' => 'failed',
            'refunded' => 'refunded',
            'transferred' => 'approved'
        ];
        
        return $statusMap[$fedapayStatus] ?? $fedapayStatus;
    }

    /**
     * Page de succès (API)
     */
    public function paymentSuccess($token): JsonResponse{
        try {
            $payment = DB::table('payments')
                ->where('payment_token', $token)
                ->first();
            
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé'
                ], 404);
            }
            
            $payment = (object) $payment;
            $metadata = json_decode($payment->metadata, true) ?? [];

            if (!in_array($payment->status, ['approved', 'completed', 'paid', 'success'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non finalisé',
                    'payment_status' => $payment->status
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Paiement réussi ! Merci pour votre vote.',
                'data' => [
                    'payment' => [
                        'reference' => $payment->reference,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'paid_at' => $payment->paid_at ? Carbon::parse($payment->paid_at)->format('d/m/Y H:i') : null,
                        'payment_method' => $payment->payment_method,
                        'status' => $payment->status
                    ],
                    'vote_details' => [
                        'votes_count' => $metadata['votes_count'] ?? 1,
                        'candidat_name' => $metadata['candidat_name'] ?? '',
                        'edition_name' => $metadata['edition_name'] ?? '',
                        'category_name' => $metadata['category_name'] ?? ''
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur page succès', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement non trouvé'
            ], 404);
        }
    }

    /**
     * Redirection succès (HTML pour FedaPay)
     */
    public function redirectSuccess($token)
    {
        try {
            $payment = DB::table('payments')
                ->where('payment_token', $token)
                ->first();
            
            if (!$payment) {
                return $this->generateAutoClosePage(null, 'error');
            }
            
            $html = $this->generateAutoClosePage((object) $payment, 'success');
            
            return response($html)->header('Content-Type', 'text/html');

        } catch (\Exception $e) {
            Log::error('Erreur redirection succès', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            
            return $this->generateAutoClosePage(null, 'error');
        }
    }

    /**
     * Page d'échec (API)
     */
    public function paymentFailed($token): JsonResponse
    {
        try {
            $payment = DB::table('payments')
                ->where('payment_token', $token)
                ->first();
            
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé'
                ], 404);
            }
            
            $payment = (object) $payment;

            return response()->json([
                'success' => false,
                'message' => 'Paiement échoué ou annulé',
                'data' => [
                    'status' => $payment->status,
                    'can_retry' => in_array($payment->status, ['failed', 'cancelled', 'expired']),
                    'expires_at' => $payment->expires_at ? Carbon::parse($payment->expires_at)->format('d/m/Y H:i') : null,
                    'amount' => $payment->amount,
                    'payment_token' => $payment->payment_token,
                    'reference' => $payment->reference
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur page échec', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement non trouvé'
            ], 404);
        }
    }

    /**
     * Redirection échec (HTML pour FedaPay)
     */
    public function redirectFailed($token)
    {
        try {
            $payment = DB::table('payments')
                ->where('payment_token', $token)
                ->first();
            
            if (!$payment) {
                return $this->generateAutoClosePage(null, 'error');
            }
            
            $html = $this->generateAutoClosePage((object) $payment, 'failed');
            
            return response($html)->header('Content-Type', 'text/html');

        } catch (\Exception $e) {
            Log::error('Erreur redirection échec', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            
            return $this->generateAutoClosePage(null, 'error');
        }
    }


    /**
     * Créer des votes à partir d'un paiement
     */
    private function createVotesFromPayment($payment): bool {
        try {
            $metadata = json_decode($payment->metadata, true) ?? [];
            $votesCount = $metadata['votes_count'] ?? 1;
            
            // Vérifier si les votes sont ouverts
            $isVoteOpen = $metadata['is_vote_open'] ?? false;
            
            // Si les votes ne sont pas ouverts, on ne crée pas de votes mais on garde le paiement comme réussi
            if (!$isVoteOpen) {
                Log::info('Votes non créés car fermés', [
                    'payment_id' => $payment->id,
                    'edition_id' => $payment->edition_id
                ]);
                
                // Marquer dans les métadonnées que les votes n'ont pas été créés
                $metadata['votes_not_created_reason'] = 'votes_closed';
                $metadata['votes_created_at'] = null;
                
                DB::table('payments')
                    ->where('id', $payment->id)
                    ->update([
                        'metadata' => json_encode($metadata),
                        'updated_at' => Carbon::now()
                    ]);
                    
                return true; // Le paiement est toujours réussi
            }

            // Chercher la candidature
            $candidature = DB::table('candidatures')
                ->where('candidat_id', $payment->candidat_id)
                ->where('edition_id', $payment->edition_id)
                ->where('category_id', $payment->category_id)
                ->first();
            
            if (!$candidature) {
                // Créer la candidature si elle n'existe pas
                $candidatureId = DB::table('candidatures')->insertGetId([
                    'candidat_id' => $payment->candidat_id,
                    'edition_id' => $payment->edition_id,
                    'category_id' => $payment->category_id,
                    'nombre_votes' => 0,
                    'status' => 'active',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
                $candidature = (object) ['id' => $candidatureId];
            } else {
                $candidature = (object) $candidature;
            }

            // Créer les votes
            $votesCreated = 0;
            for ($i = 0; $i < $votesCount; $i++) {
                DB::table('votes')->insert([
                    'edition_id' => $payment->edition_id,
                    'candidat_id' => $payment->candidat_id,
                    'votant_id' => $payment->user_id,
                    'categorie_id' => $payment->category_id,
                    'candidature_id' => $candidature->id,
                    'payment_id' => $payment->id,
                    'is_paid' => true,
                    'amount' => $this->votePrice,
                    'customer_email' => $payment->customer_email,
                    'customer_phone' => $payment->customer_phone,
                    'ip_address' => $metadata['ip_address'] ?? null,
                    'user_agent' => $metadata['user_agent'] ?? null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
                $votesCreated++;
            }

            // Mettre à jour le compteur de votes
            if ($votesCreated > 0) {
                DB::table('candidatures')
                    ->where('id', $candidature->id)
                    ->increment('nombre_votes', $votesCreated);
            }

            // Mettre à jour les métadonnées
            $metadata['votes_created'] = $votesCreated;
            $metadata['votes_created_at'] = Carbon::now()->toISOString();
            
            DB::table('payments')
                ->where('id', $payment->id)
                ->update([
                    'metadata' => json_encode($metadata),
                    'updated_at' => Carbon::now()
                ]);

            Log::info('Votes créés avec succès', [
                'payment_id' => $payment->id,
                'votes_created' => $votesCreated,
                'candidature_id' => $candidature->id
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Erreur création des votes', [
                'payment_id' => $payment->id ?? 'N/A',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }

    /**
     * Vérifier un paiement
     */
    public function verifyPayment($token): JsonResponse{
        try {
            $payment = DB::table('payments')
                ->where('payment_token', $token)
                ->first();
            
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé'
                ], 404);
            }
            
            $payment = (object) $payment;
            $metadata = json_decode($payment->metadata, true) ?? [];

            return response()->json([
                'success' => true,
                'data' => [
                    'exists' => true,
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'expires_at' => $payment->expires_at ? Carbon::parse($payment->expires_at)->toISOString() : null,
                    'candidat_name' => $metadata['candidat_name'] ?? '',
                    'votes_count' => $metadata['votes_count'] ?? 1
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement non trouvé'
            ], 404);
        }
    }

    /**
     * Annuler un paiement
     */
    public function cancelPayment($token): JsonResponse{
        try {
            $payment = DB::table('payments')
                ->where('payment_token', $token)
                ->where('status', 'pending')
                ->first();
            
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé ou non annulable'
                ], 404);
            }

            DB::table('payments')
                ->where('id', $payment->id)
                ->update(['status' => 'cancelled']);

            return response()->json([
                'success' => true,
                'message' => 'Paiement annulé avec succès'
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur annulation paiement', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation du paiement'
            ], 500);
        }
    }

    /**
     * Historique des paiements
     */
    public function paymentHistory(Request $request): JsonResponse {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié'
                ], 401);
            }

            $payments = DB::table('payments')
                ->where(function($query) use ($user) {
                    $query->where('customer_email', $user->email)
                          ->orWhere('email_payeur', $user->email);
                })
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'data' => $payments
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur historique paiements', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l\'historique'
            ], 500);
        }
    }

    /**
     * Tester la connexion FedaPay
     */
    public function testConnection(): JsonResponse {
        try {
            $apiKey = config('services.fedapay.secret_key');
            $environment = config('services.fedapay.environment', 'live');
            
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Clé API non configurée',
                    'config' => config('services.fedapay')
                ]);
            }

            $baseUrl = $environment === 'sandbox' 
                ? 'https://sandbox-api.fedapay.com/v1'
                : 'https://api.fedapay.com/v1';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json'
            ])->timeout(10)->get($baseUrl . '/accounts/current');

            return response()->json([
                'success' => $response->successful(),
                'status' => $response->status(),
                'environment' => $environment,
                'response' => $response->successful() ? $response->json() : $response->body()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Générer une page HTML qui ferme automatiquement la fenêtre
     */
    private function generateAutoClosePage(?object $payment, string $type, string $message = ''): \Illuminate\Http\Response{
        $config = [
            'success' => [
                'title' => 'Paiement Réussi',
                'message' => $message ?: 'Merci pour votre vote !',
                'icon' => '✓',
                'color' => '#10B981',
                'iconColor' => '#10B981',
                'gradient' => 'linear-gradient(135deg, #10B981, #059669)',
                'redirectUrl' => config('app.frontend_url') . '/payment/success',
                'closeDelay' => 1500
            ],
            'cancelled' => [
                'title' => 'Paiement Annulé',
                'message' => $message ?: 'Vous avez annulé le paiement.',
                'icon' => '↶',
                'color' => '#F59E0B',
                'iconColor' => '#F59E0B',
                'gradient' => 'linear-gradient(135deg, #F59E0B, #D97706)',
                'redirectUrl' => config('app.frontend_url') . '/payment/failed',
                'closeDelay' => 2000
            ],
            'failed' => [
                'title' => 'Paiement Échoué',
                'message' => $message ?: 'Le paiement a échoué.',
                'icon' => '✗',
                'color' => '#EF4444',
                'iconColor' => '#EF4444',
                'gradient' => 'linear-gradient(135deg, #EF4444, #DC2626)',
                'redirectUrl' => config('app.frontend_url') . '/payment/failed',
                'closeDelay' => 2500
            ],
            'error' => [
                'title' => 'Erreur',
                'message' => $message ?: 'Une erreur est survenue.',
                'icon' => '⚠',
                'color' => '#6B7280',
                'iconColor' => '#6B7280',
                'gradient' => 'linear-gradient(135deg, #6B7280, #4B5563)',
                'redirectUrl' => config('app.frontend_url'),
                'closeDelay' => 3000
            ]
        ];

        $settings = $config[$type] ?? $config['error'];

        // Données paiement
        $paymentData = null;
        $paymentDetailsHtml = '';

        if ($payment) {
            $metadata = json_decode($payment->metadata, true) ?? [];
            $votes = $metadata['votes_count'] ?? 1;
            
            $paymentData = [
                'token' => $payment->payment_token,
                'status' => $payment->status,
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'candidat_name' => $metadata['candidat_name'] ?? '',
                'votes_count' => $votes
            ];

            $paymentDetailsHtml = '
            <div class="payment-details animate-slide-up">
                <h3 class="payment-details-title">Détails du paiement</h3>
                <div class="payment-details-grid">
                    <div class="detail-item">
                        <span class="detail-label">Référence</span>
                        <span class="detail-value">' . htmlspecialchars($payment->reference, ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Montant</span>
                        <span class="detail-value">' . number_format($payment->amount, 0, ',', ' ') . ' XOF</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Candidat</span>
                        <span class="detail-value">' . htmlspecialchars($metadata['candidat_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Votes</span>
                        <span class="detail-value">' . $votes . ' vote' . ($votes > 1 ? 's' : '') . '</span>
                    </div>
                </div>
            </div>';
        }

        // Sécurisation variables JS
        $jsonData      = htmlspecialchars(json_encode($paymentData), ENT_QUOTES, 'UTF-8');
        $typeEscaped   = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
        $titleEscaped  = htmlspecialchars($settings['title'], ENT_QUOTES, 'UTF-8');
        $messageEscaped= htmlspecialchars($settings['message'], ENT_QUOTES, 'UTF-8');
        $iconEscaped   = htmlspecialchars($settings['icon'], ENT_QUOTES, 'UTF-8');
        $iconColor     = htmlspecialchars($settings['iconColor'], ENT_QUOTES, 'UTF-8');
        $gradient      = htmlspecialchars($settings['gradient'], ENT_QUOTES, 'UTF-8');
        $redirectUrl   = htmlspecialchars($settings['redirectUrl'], ENT_QUOTES, 'UTF-8');
        $closeDelay    = (int) $settings['closeDelay'];

        // HTML final
        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$titleEscaped} - FedaPay</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: {$gradient};
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        color: #fff;
        padding: 20px;
        overflow-x: hidden;
    }
    
    .container {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-radius: 24px;
        padding: 48px 40px;
        max-width: 540px;
        width: 100%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.2);
        animation: fadeIn 0.8s cubic-bezier(0.22, 1, 0.36, 1);
    }
    
    .icon-container {
        width: 120px;
        height: 120px;
        margin: 0 auto 32px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        animation: iconBounce 0.8s cubic-bezier(0.22, 1, 0.36, 1);
    }
    
    .icon-container::after {
        content: '';
        position: absolute;
        width: 140px;
        height: 140px;
        border-radius: 50%;
        border: 2px solid rgba(255, 255, 255, 0.3);
        animation: pulse 2s infinite;
    }
    
    .icon {
        font-size: 56px;
        font-weight: bold;
        color: {$iconColor};
    }
    
    h1 {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 16px;
        color: white;
        line-height: 1.2;
    }
    
    .message {
        font-size: 18px;
        line-height: 1.6;
        margin-bottom: 32px;
        color: rgba(255, 255, 255, 0.9);
        font-weight: 400;
    }
    
    .payment-details {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 16px;
        padding: 24px;
        margin: 32px 0;
        border: 1px solid rgba(255, 255, 255, 0.15);
        animation: slideUp 0.6s cubic-bezier(0.22, 1, 0.36, 1);
    }
    
    .payment-details-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        color: white;
        text-align: left;
    }
    
    .payment-details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
    
    .detail-item {
        text-align: left;
        padding: 12px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .detail-item:last-child {
        border-bottom: none;
    }
    
    .detail-label {
        display: block;
        font-size: 14px;
        color: rgba(255, 255, 255, 0.7);
        margin-bottom: 4px;
        font-weight: 500;
    }
    
    .detail-value {
        display: block;
        font-size: 16px;
        font-weight: 600;
        color: white;
    }
    
    .countdown-container {
        margin: 32px 0;
        padding: 20px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 16px;
        animation: fadeIn 1s ease-out;
    }
    
    .countdown {
        font-size: 64px;
        font-weight: 800;
        color: white;
        margin-bottom: 8px;
        font-feature-settings: "tnum";
        font-variant-numeric: tabular-nums;
    }
    
    .loading-text {
        font-size: 16px;
        color: rgba(255, 255, 255, 0.8);
        margin-bottom: 32px;
        font-weight: 500;
    }
    
    .close-button {
        background: white;
        color: {$iconColor};
        border: none;
        padding: 18px 40px;
        border-radius: 50px;
        font-size: 18px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        animation: fadeIn 1.2s ease-out;
    }
    
    .close-button:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
    }
    
    .close-button:active {
        transform: translateY(-1px);
    }
    
    .close-button i {
        font-size: 20px;
    }
    
    .footer-note {
        margin-top: 32px;
        font-size: 14px;
        color: rgba(255, 255, 255, 0.6);
        font-weight: 500;
    }
    
    /* Animations */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(40px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes iconBounce {
        0%, 100% {
            transform: scale(1);
        }
        50% {
            transform: scale(1.1);
        }
    }
    
    @keyframes pulse {
        0% {
            transform: scale(0.95);
            opacity: 0.5;
        }
        70% {
            transform: scale(1.1);
            opacity: 0;
        }
        100% {
            transform: scale(1.1);
            opacity: 0;
        }
    }
    
    /* Responsive */
    @media (max-width: 600px) {
        .container {
            padding: 32px 24px;
            margin: 10px;
        }
        
        h1 {
            font-size: 28px;
        }
        
        .message {
            font-size: 16px;
        }
        
        .payment-details-grid {
            grid-template-columns: 1fr;
        }
        
        .countdown {
            font-size: 56px;
        }
        
        .close-button {
            width: 100%;
            padding: 16px 32px;
        }
    }
    </style>
</head>
<body>

<div class="container">
    <div class="icon-container">
        <div class="icon">{$iconEscaped}</div>
    </div>
    
    <h1>{$titleEscaped}</h1>
    <p class="message">{$messageEscaped}</p>
    
    {$paymentDetailsHtml}
    
    <div class="countdown-container">
        <div class="countdown" id="countdown">3</div>
        <p class="loading-text" id="loadingText">Fermeture automatique dans <span id="secondsText">3</span> seconde(s)</p>
    </div>
    
    <button class="close-button" onclick="closeWindow()" id="closeButton">
        <i class="fas fa-times"></i>
        Fermer maintenant
    </button>
    
    <p class="footer-note">Vous serez redirigé automatiquement...</p>
</div>

<script>
    const paymentData = {$jsonData};
    const type = "{$typeEscaped}";
    const redirectUrl = "{$redirectUrl}";
    const totalSeconds = Math.floor({$closeDelay} / 1000);
    let seconds = totalSeconds;
    let closing = false;
    let autoCloseTimer;
    let countdownInterval;

    function updateCountdown() {
        const countdownEl = document.getElementById('countdown');
        const secondsTextEl = document.getElementById('secondsText');
        
        if (seconds >= 0) {
            countdownEl.textContent = seconds;
            secondsTextEl.textContent = seconds;
            seconds--;
        }
    }

    function sendMessage() {
        try {
            if (window.opener && !window.opener.closed) {
                window.opener.postMessage({
                    type: 'PAYMENT_RESULT',
                    result: type,
                    paymentData: paymentData
                }, '*');
            }
        } catch (e) {
            console.log('Message sending failed:', e);
        }
    }

    function closeWindow() {
        if (closing) return;
        
        closing = true;
        
        // Clear timers
        if (autoCloseTimer) clearTimeout(autoCloseTimer);
        if (countdownInterval) clearInterval(countdownInterval);
        
        // Update UI
        document.getElementById('countdown').textContent = '0';
        document.getElementById('loadingText').textContent = 'Fermeture en cours...';
        document.getElementById('closeButton').disabled = true;
        document.getElementById('closeButton').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fermeture...';
        
        // Send message to opener
        sendMessage();
        
        // Try to close window or redirect
        setTimeout(() => {
            try {
                // Try to close the window
                if (window.history.length > 1) {
                    window.history.back();
                }
                
                // Fallback: redirect
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 300);
                
                // Last resort: try window.close()
                setTimeout(() => {
                    try {
                        window.close();
                    } catch (e) {
                        // If window.close() fails, just redirect
                        window.location.href = redirectUrl;
                    }
                }, 500);
                
            } catch (e) {
                // If anything fails, just redirect
                window.location.href = redirectUrl;
            }
        }, 800);
    }

    // Initialize countdown
    document.addEventListener('DOMContentLoaded', function() {
        // Start countdown interval
        countdownInterval = setInterval(updateCountdown, 1000);
        
        // Set auto close timer
        autoCloseTimer = setTimeout(closeWindow, {$closeDelay});
        
        // Update immediately
        updateCountdown();
    });

    // Handle beforeunload
    window.addEventListener('beforeunload', function() {
        sendMessage();
    });
</script>

</body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Webhook FedaPay - Gère à la fois POST (webhook réel) et GET (annulation/retour)
     */
    public function webhook(Request $request)
    {
        Log::info('Webhook FedaPay reçu', [
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'query' => $request->query(),
            'payload' => $request->all()
        ]);

        // Si c'est une requête GET, c'est probablement un retour après paiement ou annulation
        if ($request->isMethod('GET')) {
            return $this->handleFedapayRedirect($request);
        }

        // Sinon, c'est le webhook POST normal
        return $this->handleFedapayWebhook($request);
    }

    /**
     * Gérer le webhook POST de FedaPay
     */
    private function handleFedapayWebhook(Request $request): JsonResponse
    {
        try {
            // Récupérer l'événement FedaPay
            $event = $request->input('event');
            $data = $request->input('data', []);
            
            if (!$event || !isset($data['transaction'])) {
                Log::warning('Format webhook POST invalide', $request->all());
                return response()->json(['success' => false, 'message' => 'Format POST invalide'], 400);
            }

            $transaction = $data['transaction'];
            $transactionId = $transaction['id'] ?? null;
            
            if (!$transactionId) {
                return response()->json(['success' => false, 'message' => 'Transaction ID manquant'], 400);
            }

            // Chercher le paiement
            $payment = DB::table('payments')
                ->where('transaction_id', $transactionId)
                ->first();
            
            if (!$payment) {
                Log::warning('Paiement non trouvé pour webhook POST', ['transaction_id' => $transactionId]);
                return response()->json(['success' => false, 'message' => 'Paiement non trouvé'], 404);
            }
            
            $payment = (object) $payment;
            $metadata = json_decode($payment->metadata, true) ?? [];

            // Mettre à jour le statut selon l'événement
            switch ($event) {
                case 'transaction.approved':
                    $newStatus = 'approved';
                    break;
                case 'transaction.canceled':
                    $newStatus = 'cancelled';
                    break;
                case 'transaction.declined':
                    $newStatus = 'failed';
                    break;
                case 'transaction.pending':
                    // Si c'est encore pending, vérifier si close=true
                    $close = $request->input('data.close', false);
                    if ($close === true || $close === 'true') {
                        $newStatus = 'cancelled';
                    } else {
                        $newStatus = 'pending';
                    }
                    break;
                default:
                    $newStatus = $payment->status;
            }

            if ($newStatus !== $payment->status) {
                // Mise à jour sans transaction
                $metadata = array_merge($metadata, [
                    'webhook_received_at' => Carbon::now()->toISOString(),
                    'webhook_event' => $event,
                    'webhook_method' => 'POST',
                    'webhook_data' => $data
                ]);
                
                $updateData = [
                    'status' => $newStatus,
                    'metadata' => json_encode($metadata),
                    'updated_at' => Carbon::now()
                ];
                
                // Si le paiement est réussi, marquer la date de paiement
                if (in_array($newStatus, ['approved', 'completed', 'paid', 'success'])) {
                    $updateData['paid_at'] = Carbon::now();
                }
                
                DB::table('payments')
                    ->where('id', $payment->id)
                    ->update($updateData);
                
                // Si le paiement est réussi, créer les votes
                if (in_array($newStatus, ['approved', 'completed', 'paid', 'success']) && 
                    !in_array($payment->status, ['approved', 'completed', 'paid', 'success'])) {
                    
                    // Appel SÉCURISÉ qui ne provoque pas d'erreur transaction
                    $this->createVotesFromPayment($payment);
                }
                
                Log::info('Statut paiement mis à jour via webhook POST', [
                    'payment_id' => $payment->id,
                    'old_status' => $payment->status,
                    'new_status' => $newStatus,
                    'event' => $event
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Webhook POST traité']);

        } catch (\Exception $e) {
            Log::error('Erreur webhook POST FedaPay', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur'
            ], 500);
        }
    }

    /**
     * Gérer la redirection GET de FedaPay (annulation ou retour)
     */
    private function handleFedapayRedirect(Request $request)
    {
        try {
            $transactionId = $request->query('id');
            $status = $request->query('status', 'pending');
            $close = $request->query('close', 'false');
            
            Log::info('Redirection FedaPay GET reçue', [
                'transaction_id' => $transactionId,
                'status' => $status,
                'close' => $close,
                'is_cancelled' => ($status === 'pending' && $close === 'true') || $status === 'canceled'
            ]);

            if (!$transactionId) {
                Log::warning('Redirection GET sans transaction ID', $request->query());
                return $this->generateAutoClosePage(null, 'error', 'Transaction ID manquant');
            }

            // Chercher le paiement
            $payment = DB::table('payments')
                ->where('transaction_id', $transactionId)
                ->first();
            
            if (!$payment) {
                Log::warning('Paiement non trouvé pour redirection GET', [
                    'transaction_id' => $transactionId
                ]);
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé');
            }
            
            $payment = (object) $payment;
            $metadata = json_decode($payment->metadata, true) ?? [];

            // Déterminer le statut
            $isCancelled = ($status === 'pending' && $close === 'true') || 
                        $status === 'canceled' || 
                        $status === 'cancelled' ||
                        str_contains(strtolower($request->fullUrl()), 'cancel');

            if ($isCancelled) {
                // Mettre à jour le statut comme annulé
                $metadata = array_merge($metadata, [
                    'redirect_received_at' => Carbon::now()->toISOString(),
                    'redirect_status' => $status,
                    'redirect_close' => $close,
                    'redirect_url' => $request->fullUrl(),
                    'cancelled_at' => Carbon::now()->toISOString(),
                    'cancellation_source' => 'fedapay_redirect'
                ]);
                
                DB::table('payments')
                    ->where('id', $payment->id)
                    ->update([
                        'status' => 'cancelled',
                        'metadata' => json_encode($metadata),
                        'updated_at' => Carbon::now()
                    ]);
                
                Log::info('Paiement marqué comme annulé via redirection GET', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $transactionId,
                    'status' => $status,
                    'close' => $close
                ]);
                
                return $this->generateAutoClosePage($payment, 'cancelled');
            }

            // Si c'est un retour normal, synchroniser avec FedaPay
            $this->syncPaymentStatusWithFedapay($payment);

            // Recharger le paiement
            $payment = DB::table('payments')
                ->where('id', $payment->id)
                ->first();
                
            if (!$payment) {
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé après synchronisation');
            }
            
            $payment = (object) $payment;

            // Déterminer le type de page à afficher
            if (in_array($payment->status, ['approved', 'completed', 'paid', 'success'])) {
                return $this->generateAutoClosePage($payment, 'success');
            } else {
                return $this->generateAutoClosePage($payment, 'failed');
            }

        } catch (\Exception $e) {
            Log::error('Erreur redirection GET FedaPay', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->generateAutoClosePage(null, 'error', $e->getMessage());
        }
    }

    /**
     * Synchroniser le statut avec FedaPay
     */
    private function syncPaymentStatusWithFedapay($payment): void
    {
        try {
            $apiKey = config('services.fedapay.secret_key');
            $environment = config('services.fedapay.environment', 'live');
            
            if (!$apiKey || !$payment->transaction_id) {
                return;
            }

            $baseUrl = $environment === 'sandbox' 
                ? 'https://sandbox-api.fedapay.com/v1'
                : 'https://api.fedapay.com/v1';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json'
            ])->timeout(10)->get($baseUrl . '/transactions/' . $payment->transaction_id);
            
            if ($response->successful()) {
                $fedapayData = $response->json();
                $transaction = $fedapayData['v1/transaction'] ?? $fedapayData['data'] ?? null;
                
                if ($transaction && isset($transaction['status'])) {
                    $newStatus = $this->mapFedapayStatus($transaction['status']);
                    
                    if ($newStatus !== $payment->status) {
                        // Mise à jour SANS transaction pour éviter les problèmes
                        $metadata = json_decode($payment->metadata, true) ?? [];
                        $metadata = array_merge($metadata, [
                            'last_sync_at' => Carbon::now()->toISOString(),
                            'fedapay_sync_status' => $transaction['status']
                        ]);
                        
                        $updateData = [
                            'status' => $newStatus,
                            'metadata' => json_encode($metadata),
                            'updated_at' => Carbon::now()
                        ];
                        
                        // Si le paiement est réussi, marquer la date de paiement
                        if (in_array($newStatus, ['approved', 'completed', 'paid', 'success'])) {
                            $updateData['paid_at'] = Carbon::now();
                        }
                        
                        DB::table('payments')
                            ->where('id', $payment->id)
                            ->update($updateData);
                        
                        // Si le paiement est réussi, créer les votes
                        if (in_array($newStatus, ['approved', 'completed', 'paid', 'success']) && 
                            !in_array($payment->status, ['approved', 'completed', 'paid', 'success'])) {
                            
                            // Recharger le paiement pour avoir les données à jour
                            $updatedPayment = DB::table('payments')
                                ->where('id', $payment->id)
                                ->first();
                                
                            if ($updatedPayment) {
                                // Appel SÉCURISÉ sans transaction
                                $this->createVotesFromPayment((object) $updatedPayment);
                            }
                        }
                        
                        Log::info('Statut synchronisé avec FedaPay', [
                            'payment_id' => $payment->id,
                            'old_status' => $payment->status,
                            'new_status' => $newStatus,
                            'fedapay_status' => $transaction['status']
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erreur synchronisation statut FedaPay', [
                'error' => $e->getMessage(),
                'transaction_id' => $payment->transaction_id
            ]);
        }
    }

    public function fedapayCallback(Request $request){
        Log::info('Callback FedaPay GET', [
            'query' => $request->query(),
            'full_url' => $request->fullUrl()
        ]);

        try {
            $paymentToken = $request->query('payment_token');
            $transactionId = $request->query('transaction_id');
            $status = $request->query('status', 'pending');
            
            // Si c'est une annulation directe
            if ($status === 'cancelled') {
                return $this->handleCancellationCallback($request);
            }

            if (!$paymentToken && !$transactionId) {
                Log::warning('Callback sans identifiant', $request->all());
                return $this->generateAutoClosePage(null, 'error', 'Identifiant manquant');
            }

            // Chercher le paiement
            $payment = null;
            if ($paymentToken) {
                $payment = DB::table('payments')
                    ->where('payment_token', $paymentToken)
                    ->first();
            }
            
            if (!$payment && $transactionId) {
                $payment = DB::table('payments')
                    ->where('transaction_id', $transactionId)
                    ->first();
            }

            if (!$payment) {
                Log::warning('Paiement non trouvé pour callback', [
                    'payment_token' => $paymentToken,
                    'transaction_id' => $transactionId
                ]);
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé');
            }
            
            $payment = (object) $payment;

            // Synchroniser le statut
            if (in_array($payment->status, ['pending', 'processing'])) {
                $this->syncPaymentStatusWithFedapay($payment);
                // Recharger le paiement
                $payment = DB::table('payments')
                    ->where('id', $payment->id)
                    ->first();
                if ($payment) {
                    $payment = (object) $payment;
                }
            }

            if (!$payment) {
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé après synchronisation');
            }

            // Déterminer le type de page à afficher
            if (in_array($payment->status, ['approved', 'completed', 'paid', 'success'])) {
                return $this->generateAutoClosePage($payment, 'success');
            } else {
                return $this->generateAutoClosePage($payment, 'failed');
            }

        } catch (\Exception $e) {
            Log::error('Erreur callback FedaPay', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->generateAutoClosePage(null, 'error', $e->getMessage());
        }
    }

    private function handleCancellationCallback(Request $request)
    {
        $paymentToken = $request->query('payment_token');
        
        if (!$paymentToken) {
            return $this->generateAutoClosePage(null, 'error', 'Token de paiement manquant');
        }
        
        try {
            $payment = DB::table('payments')
                ->where('payment_token', $paymentToken)
                ->first();
            
            if (!$payment) {
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé');
            }
            
            $payment = (object) $payment;
            $metadata = json_decode($payment->metadata, true) ?? [];
            
            // Mettre à jour le statut
            $metadata = array_merge($metadata, [
                'callback_cancelled_at' => Carbon::now()->toISOString(),
                'callback_url' => $request->fullUrl()
            ]);
            
            DB::table('payments')
                ->where('id', $payment->id)
                ->update([
                    'status' => 'cancelled',
                    'metadata' => json_encode($metadata),
                    'updated_at' => Carbon::now()
                ]);
            
            Log::info('Paiement annulé via callback URL', [
                'payment_id' => $payment->id,
                'payment_token' => $paymentToken
            ]);
            
            return $this->generateAutoClosePage($payment, 'cancelled', 'Paiement annulé par l\'utilisateur');
            
        } catch (\Exception $e) {
            Log::error('Erreur traitement annulation callback', [
                'error' => $e->getMessage(),
                'payment_token' => $paymentToken
            ]);
            
            return $this->generateAutoClosePage(null, 'error', 'Erreur lors de l\'annulation');
        }
    }
}