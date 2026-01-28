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
    
    // Ajouter un état de contrôle de connexion
    private $connectionActive = true;
    
    /**
     * CONSTRUCTEUR - Force la configuration initiale
     */
    public function __construct()
    {
        // Désactiver complètement le mode transactionnel
        $this->disableTransactionMode();
    }
    
    /**
     * DÉSACTIVER COMPLÈTEMENT le mode transactionnel
     */
    private function disableTransactionMode(): void
    {
        try {
            if (app()->environment('production')) {
                // En production, forcer AUTOCOMMIT
                DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
                DB::statement('SET idle_in_transaction_session_timeout = 10000');
                DB::statement('SET statement_timeout = 30000');
            }
        } catch (\Exception $e) {
            // Ignorer silencieusement les erreurs de configuration
        }
    }
    
    /**
     * VALIDATION ULTRA-SIMPLE - Pas de base de données
     */
    private function ultraSimpleValidation(array $data): array
    {
        $errors = [];
        $validated = [];
        
        // 1. Candidat ID
        if (empty($data['candidat_id']) || !is_numeric($data['candidat_id']) || $data['candidat_id'] < 1) {
            $errors['candidat_id'] = ['ID candidat invalide'];
        } else {
            $validated['candidat_id'] = (int) $data['candidat_id'];
        }
        
        // 2. Edition ID
        if (empty($data['edition_id']) || !is_numeric($data['edition_id']) || $data['edition_id'] < 1) {
            $errors['edition_id'] = ['ID édition invalide'];
        } else {
            $validated['edition_id'] = (int) $data['edition_id'];
        }
        
        // 3. Category ID (optionnel)
        if (!empty($data['category_id'])) {
            if (!is_numeric($data['category_id']) || $data['category_id'] < 1) {
                $errors['category_id'] = ['ID catégorie invalide'];
            } else {
                $validated['category_id'] = (int) $data['category_id'];
            }
        }
        
        // 4. Votes count
        if (empty($data['votes_count']) || !is_numeric($data['votes_count']) || 
            $data['votes_count'] < 1 || $data['votes_count'] > 1000) {
            $errors['votes_count'] = ['Nombre de votes invalide (1-1000)'];
        } else {
            $validated['votes_count'] = (int) $data['votes_count'];
        }
        
        // 5. Email
        if (empty($data['email']) || strlen($data['email']) > 100) {
            $errors['email'] = ['Email invalide ou trop long'];
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['Format email invalide'];
        } else {
            $validated['email'] = substr(trim($data['email']), 0, 100);
        }
        
        // 6. Téléphone
        if (empty($data['phone']) || strlen($data['phone']) < 8) {
            $errors['phone'] = ['Téléphone invalide'];
        } else {
            $phone = $this->cleanPhone($data['phone']);
            if (!$phone) {
                $errors['phone'] = ['Format téléphone invalide'];
            } else {
                $validated['phone'] = $phone;
            }
        }
        
        // 7. Prénom
        if (empty($data['firstname']) || strlen($data['firstname']) > 50) {
            $errors['firstname'] = ['Prénom invalide ou trop long'];
        } else {
            $validated['firstname'] = substr(trim($data['firstname']), 0, 50);
        }
        
        // 8. Nom
        if (empty($data['lastname']) || strlen($data['lastname']) > 50) {
            $errors['lastname'] = ['Nom invalide ou trop long'];
        } else {
            $validated['lastname'] = substr(trim($data['lastname']), 0, 50);
        }
        
        return [
            'success' => empty($errors),
            'errors' => $errors,
            'data' => $validated
        ];
    }
    
    /**
     * INITIATION DE PAIEMENT - Version SIMPLIFIÉE et ROBUSTE
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        Log::info('=== INITIATION PAIEMENT - DÉBUT ===', [
            'ip' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 100)
        ]);
        
        try {
            // 1. Validation ultra simple (sans base)
            $validation = $this->ultraSimpleValidation($request->all());
            
            if (!$validation['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée',
                    'errors' => $validation['errors']
                ], 422);
            }
            
            $data = $validation['data'];
            
            Log::info('Validation réussie', [
                'candidat_id' => $data['candidat_id'],
                'edition_id' => $data['edition_id'],
                'votes_count' => $data['votes_count']
            ]);
            
            // 2. Vérifications SÉPARÉES avec connexion INDÉPENDANTE
            // Vérifier l'édition
            $editionCheck = $this->simpleCheckExists('editions', $data['edition_id']);
            if (!$editionCheck['exists']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Édition non trouvée'
                ], 404);
            }
            
            // Vérifier le candidat
            $candidatCheck = $this->simpleCheckExists('users', $data['candidat_id']);
            if (!$candidatCheck['exists']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Candidat non trouvé'
                ], 404);
            }
            
            // Vérifier la catégorie si fournie
            $categoryName = 'Non spécifiée';
            if (!empty($data['category_id'])) {
                $categoryCheck = $this->simpleCheckExists('categories', $data['category_id']);
                if (!$categoryCheck['exists']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Catégorie non trouvée'
                    ], 404);
                }
                $categoryName = $categoryCheck['name'] ?? 'Catégorie';
                
                // Vérifier la candidature
                $candidatureExists = $this->checkCandidatureSimple(
                    $data['candidat_id'],
                    $data['edition_id'],
                    $data['category_id']
                );
                
                if (!$candidatureExists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ce candidat ne participe pas à cette catégorie'
                    ], 400);
                }
            }
            
            // 3. Calculs simples
            $totalAmount = $this->votePrice * $data['votes_count'];
            $reference = 'VOTE-' . strtoupper(Str::random(10));
            $paymentToken = Str::uuid();
            $expiresAt = Carbon::now()->addMinutes(30);
            $now = Carbon::now();
            
            // 4. Création du paiement - SIMPLE INSERT
            $paymentId = $this->createSimplePayment([
                'reference' => $reference,
                'payment_token' => $paymentToken,
                'edition_id' => $data['edition_id'],
                'candidat_id' => $data['candidat_id'],
                'category_id' => $data['category_id'] ?? null,
                'amount' => $totalAmount,
                'customer_email' => $data['email'],
                'customer_phone' => $data['phone'],
                'customer_firstname' => $data['firstname'],
                'customer_lastname' => $data['lastname'],
                'candidat_name' => $candidatCheck['name'] ?? 'Candidat',
                'edition_name' => $editionCheck['name'] ?? 'Édition',
                'category_name' => $categoryName,
                'votes_count' => $data['votes_count'],
                'expires_at' => $expiresAt,
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 200)
            ]);
            
            if (!$paymentId) {
                throw new \Exception('Échec création paiement');
            }
            
            Log::info('Paiement créé avec succès', [
                'payment_id' => $paymentId,
                'payment_token' => $paymentToken,
                'reference' => $reference
            ]);
            
            // 5. Réponse
            return response()->json([
                'success' => true,
                'message' => 'Paiement initialisé avec succès',
                'data' => [
                    'payment_token' => $paymentToken,
                    'amount' => $totalAmount,
                    'currency' => 'XOF',
                    'votes_count' => $data['votes_count'],
                    'candidat_name' => $candidatCheck['name'] ?? 'Candidat',
                    'edition_name' => $editionCheck['name'] ?? 'Édition',
                    'category_name' => $categoryName,
                    'expires_at' => $expiresAt->toISOString(),
                    'reference' => $reference,
                    'check_status_url' => url("/api/payments/{$paymentToken}/status"),
                    'process_url' => url("/api/payments/process"),
                    'success_redirect' => url("/payments/{$paymentToken}/success/redirect"),
                    'failed_redirect' => url("/payments/{$paymentToken}/failed/redirect")
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur initiation paiement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->except(['_token', 'password'])
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'initialisation du paiement',
                'technical_error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        } finally {
            Log::info('=== INITIATION PAIEMENT - FIN ===');
        }
    }
    
    /**
     * VÉRIFIER SIMPLEMENT si un enregistrement existe
     */
    private function simpleCheckExists(string $table, $id): array
    {
        // Créer une connexion TEMPORAIRE et ISOLÉE
        $pdo = $this->createTempConnection();
        
        try {
            $sql = match($table) {
                'editions' => "SELECT id, nom as name FROM editions WHERE id = :id LIMIT 1",
                'users' => "SELECT id, CONCAT(nom, ' ', prenoms) as name FROM users WHERE id = :id LIMIT 1",
                'categories' => "SELECT id, nom as name FROM categories WHERE id = :id LIMIT 1",
                default => "SELECT id FROM {$table} WHERE id = :id LIMIT 1"
            };
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$result) {
                return ['exists' => false];
            }
            
            return [
                'exists' => true,
                'id' => $result['id'],
                'name' => $result['name'] ?? 'Non spécifié'
            ];
            
        } catch (\Exception $e) {
            Log::warning("Erreur vérification {$table}", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return ['exists' => false];
        } finally {
            // TOUJOURS fermer la connexion
            $pdo = null;
        }
    }
    
    /**
     * VÉRIFIER SIMPLEMENT une candidature
     */
    private function checkCandidatureSimple($candidatId, $editionId, $categoryId): bool
    {
        $pdo = $this->createTempConnection();
        
        try {
            $stmt = $pdo->prepare("
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
                'error' => $e->getMessage()
            ]);
            return false;
        } finally {
            $pdo = null;
        }
    }
    
    /**
     * CRÉER UN PAIEMENT SIMPLE
     */
    private function createSimplePayment(array $data): ?int
    {
        $pdo = $this->createTempConnection();
        
        try {
            // Préparer les métadonnées
            $metadata = json_encode([
                'votes_count' => $data['votes_count'],
                'vote_price' => $this->votePrice,
                'candidat_name' => $data['candidat_name'],
                'edition_name' => $data['edition_name'],
                'category_name' => $data['category_name'],
                'ip_address' => $data['ip_address'] ?? '',
                'user_agent' => $data['user_agent'] ?? '',
                'created_at' => Carbon::now()->toISOString()
            ]);
            
            // SQL SIMPLE - un seul INSERT
            $sql = "
                INSERT INTO payments (
                    reference, payment_token, edition_id, candidat_id, category_id,
                    amount, currency, status, customer_email, customer_phone,
                    customer_firstname, customer_lastname, metadata, expires_at,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $data['reference'],
                $data['payment_token'],
                $data['edition_id'],
                $data['candidat_id'],
                $data['category_id'],
                $data['amount'],
                'XOF',
                'pending',
                $data['customer_email'],
                $data['customer_phone'],
                $data['customer_firstname'],
                $data['customer_lastname'],
                $metadata,
                $data['expires_at'],
                Carbon::now(),
                Carbon::now()
            ]);
            
            $paymentId = $stmt->fetch(\PDO::FETCH_COLUMN);
            
            Log::info('Paiement inséré', [
                'payment_id' => $paymentId,
                'reference' => $data['reference']
            ]);
            
            return $paymentId ? (int) $paymentId : null;
            
        } catch (\Exception $e) {
            Log::error('Erreur insertion paiement', [
                'error' => $e->getMessage(),
                'reference' => $data['reference'] ?? 'N/A'
            ]);
            return null;
        } finally {
            $pdo = null;
        }
    }
    
    /**
     * CRÉER UNE CONNEXION TEMPORAIRE ISOLÉE
     */
    private function createTempConnection(): \PDO
    {
        $config = config('database.connections.pgsql');
        
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
        
        return new \PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_PERSISTENT => false,
                \PDO::ATTR_EMULATE_PREPARES => true,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // DÉSACTIVER LES TRANSACTIONS
                \PDO::ATTR_AUTOCOMMIT => true
            ]
        );
    }
    
    /**
     * NETTOYER LE NUMÉRO DE TÉLÉPHONE
     */
    private function cleanPhone(string $phone): ?string
    {
        // Enlever tous les caractères non numériques
        $clean = preg_replace('/[^0-9]/', '', $phone);
        
        if (empty($clean) || strlen($clean) < 8) {
            return null;
        }
        
        // Format Bénin: 229XXXXXXXX
        if (strlen($clean) === 8) {
            return '229' . $clean;
        }
        
        // Déjà format 229
        if (strlen($clean) === 11 && strpos($clean, '229') === 0) {
            return $clean;
        }
        
        // Si commence par 0 (10 chiffres) -> 229 + 9 chiffres
        if (strlen($clean) === 10 && strpos($clean, '0') === 0) {
            return '229' . substr($clean, 1);
        }
        
        // Sinon, prendre les derniers 8 chiffres
        if (strlen($clean) >= 8) {
            return '229' . substr($clean, -8);
        }
        
        return null;
    }
    
    /**
     * VÉRIFIER LE STATUT D'UN PAIEMENT
     */
    public function checkPaymentStatus($token): JsonResponse
    {
        $pdo = $this->createTempConnection();
        
        try {
            $stmt = $pdo->prepare("
                SELECT id, status, amount, expires_at, created_at, 
                       paid_at, payment_method, reference, metadata
                FROM payments 
                WHERE payment_token = :token 
                LIMIT 1
            ");
            
            $stmt->execute([':token' => $token]);
            $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé'
                ], 404);
            }
            
            // Vérifier l'expiration
            $isExpired = false;
            if ($payment['expires_at']) {
                $expiresAt = Carbon::parse($payment['expires_at']);
                $isExpired = $expiresAt->isPast() && $payment['status'] === 'pending';
                
                if ($isExpired) {
                    // Mettre à jour le statut
                    $this->updateSimpleStatus($payment['id'], 'expired');
                    $payment['status'] = 'expired';
                }
            }
            
            $metadata = json_decode($payment['metadata'] ?? '{}', true);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $payment['status'],
                    'is_successful' => in_array($payment['status'], ['approved', 'completed', 'paid', 'success']),
                    'is_expired' => $isExpired,
                    'amount' => (float) $payment['amount'],
                    'votes_count' => $metadata['votes_count'] ?? 1,
                    'candidat_name' => $metadata['candidat_name'] ?? '',
                    'created_at' => Carbon::parse($payment['created_at'])->toISOString(),
                    'paid_at' => $payment['paid_at'] ? Carbon::parse($payment['paid_at'])->toISOString() : null,
                    'payment_method' => $payment['payment_method'],
                    'reference' => $payment['reference'],
                    'payment_token' => $token,
                    'check_interval' => 3000
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur vérification statut', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification'
            ], 500);
        } finally {
            $pdo = null;
        }
    }
    
    /**
     * METTRE À JOUR SIMPLEMENT UN STATUT
     */
    private function updateSimpleStatus($paymentId, $status): bool
    {
        $pdo = $this->createTempConnection();
        
        try {
            $stmt = $pdo->prepare("
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
            Log::warning('Erreur mise à jour statut', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            return false;
        } finally {
            $pdo = null;
        }
    }
    
    /**
     * TRAITER UN PAIEMENT (créer transaction FedaPay)
     */
    public function processPayment(Request $request): JsonResponse
    {
        try {
            // Validation simple
            $paymentToken = $request->input('payment_token');
            $paymentMethod = $request->input('payment_method');
            
            if (empty($paymentToken) || empty($paymentMethod)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token et méthode de paiement requis'
                ], 422);
            }
            
            if (!in_array($paymentMethod, ['mobile_money', 'card'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Méthode de paiement invalide'
                ], 422);
            }
            
            // Récupérer le paiement
            $pdo = $this->createTempConnection();
            $stmt = $pdo->prepare("
                SELECT * FROM payments 
                WHERE payment_token = :token 
                LIMIT 1
            ");
            $stmt->execute([':token' => $paymentToken]);
            $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
            $pdo = null;
            
            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non trouvé'
                ], 404);
            }
            
            // Vérifier les statuts
            $allowedStatuses = ['pending', 'failed', 'cancelled', 'expired'];
            if (!in_array($payment['status'], $allowedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce paiement ne peut pas être retenté. Statut: ' . $payment['status']
                ], 400);
            }
            
            // Vérifier expiration
            if ($payment['expires_at'] && Carbon::parse($payment['expires_at'])->isPast()) {
                $this->updateSimpleStatus($payment['id'], 'expired');
                return response()->json([
                    'success' => false,
                    'message' => 'Le paiement a expiré'
                ], 400);
            }
            
            // Créer la transaction FedaPay
            return $this->createFedapayTransaction($payment, $paymentMethod);
            
        } catch (\Exception $e) {
            Log::error('Erreur traitement paiement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du paiement'
            ], 500);
        }
    }
    
    /**
     * CRÉER UNE TRANSACTION FEDAPAY
     */
    private function createFedapayTransaction(array $payment, string $paymentMethod): JsonResponse
    {
        try {
            $apiKey = config('services.fedapay.secret_key');
            $environment = config('services.fedapay.environment', 'live');
            
            if (!$apiKey) {
                throw new \Exception('Clé API FedaPay non configurée');
            }
            
            $baseUrl = $environment === 'sandbox' 
                ? 'https://sandbox-api.fedapay.com/v1'
                : 'https://api.fedapay.com/v1';
            
            $metadata = json_decode($payment['metadata'] ?? '{}', true);
            $phone = $this->formatPhoneForFedapay($payment['customer_phone']);
            
            // URLs de callback
            $callbackUrl = url('/api/payments/webhook');
            $returnUrl = url("/payments/callback?payment_token={$payment['payment_token']}");
            
            $transactionData = [
                'description' => sprintf(
                    'Vote pour %s - %d vote(s) - %s',
                    $metadata['candidat_name'] ?? 'Candidat',
                    $metadata['votes_count'] ?? 1,
                    $metadata['edition_name'] ?? 'Édition'
                ),
                'amount' => (int) $payment['amount'],
                'currency' => ['iso' => 'XOF'],
                'callback_url' => $callbackUrl,
                'redirect_url' => $returnUrl,
                'customer' => [
                    'firstname' => substr($payment['customer_firstname'], 0, 50),
                    'lastname' => substr($payment['customer_lastname'], 0, 50),
                    'email' => $payment['customer_email'],
                    'phone_number' => [
                        'number' => $phone,
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
                $error = $response->json();
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur FedaPay: ' . ($error['message'] ?? 'Inconnue')
                ], $response->status());
            }
            
            $fedapayData = $response->json();
            $transaction = $fedapayData['v1/transaction'] ?? $fedapayData['data'] ?? null;
            
            if (!$transaction || !isset($transaction['id'])) {
                throw new \Exception('Format réponse FedaPay invalide');
            }
            
            $transactionId = $transaction['id'];
            $paymentUrl = $transaction['payment_url'] ?? 
                ($environment === 'sandbox' 
                    ? "https://sandbox-checkout.fedapay.com/{$transactionId}"
                    : "https://process.fedapay.com/{$transaction['payment_token']}");
            
            // Mettre à jour le paiement
            $metadata['fedapay_transaction_id'] = $transactionId;
            $metadata['fedapay_payment_token'] = $transaction['payment_token'] ?? null;
            $metadata['processed_at'] = Carbon::now()->toISOString();
            $metadata['payment_url'] = $paymentUrl;
            
            $this->updateSimplePayment($payment['id'], [
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
                    'payment_token' => $payment['payment_token'],
                    'payment_method' => $paymentMethod,
                    'expires_at' => Carbon::parse($payment['expires_at'])->toISOString(),
                    'check_status_url' => url("/api/payments/{$payment['payment_token']}/status"),
                    'success_redirect_url' => url("/payments/{$payment['payment_token']}/success/redirect"),
                    'failed_redirect_url' => url("/payments/{$payment['payment_token']}/failed/redirect")
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur création transaction FedaPay', [
                'error' => $e->getMessage(),
                'payment_id' => $payment['id'] ?? 'N/A'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur création transaction: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * METTRE À JOUR SIMPLEMENT UN PAIEMENT
     */
    private function updateSimplePayment($paymentId, array $data): bool
    {
        $pdo = $this->createTempConnection();
        
        try {
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
            $result = $stmt->execute($params);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Erreur mise à jour paiement', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            return false;
        } finally {
            $pdo = null;
        }
    }
    
    /**
     * FORMATER LE TÉLÉPHONE POUR FEDAPAY
     */
    private function formatPhoneForFedapay($phone): string
    {
        $phone = $this->cleanPhone($phone);
        if (!$phone) {
            throw new \Exception('Numéro de téléphone invalide');
        }
        
        if (strlen($phone) !== 11) {
            throw new \Exception('Numéro de téléphone doit être 229 suivi de 8 chiffres');
        }
        
        return '+' . $phone;
    }
    
    /**
     * WEBHOOK FEDAPAY - Version SIMPLIFIÉE
     */
    public function webhook(Request $request)
    {
        Log::info('Webhook FedaPay reçu', [
            'method' => $request->method(),
            'query' => $request->query()
        ]);
        
        // Si GET = redirection, si POST = webhook réel
        if ($request->isMethod('GET')) {
            return $this->handleRedirect($request);
        }
        
        return $this->handleWebhookPost($request);
    }
    
    /**
     * TRAITER REDIRECTION (GET)
     */
    private function handleRedirect(Request $request)
    {
        try {
            $transactionId = $request->query('id');
            $status = $request->query('status', 'pending');
            $close = $request->query('close', 'false');
            
            if (!$transactionId) {
                return $this->generateSimplePage('error', 'Transaction ID manquant');
            }
            
            // Chercher le paiement
            $pdo = $this->createTempConnection();
            $stmt = $pdo->prepare("
                SELECT * FROM payments 
                WHERE transaction_id = :transaction_id 
                LIMIT 1
            ");
            $stmt->execute([':transaction_id' => $transactionId]);
            $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
            $pdo = null;
            
            if (!$payment) {
                Log::warning('Paiement non trouvé pour redirection', ['transaction_id' => $transactionId]);
                return $this->generateSimplePage('error', 'Paiement non trouvé');
            }
            
            // Vérifier si annulation
            $isCancelled = ($status === 'pending' && $close === 'true') || 
                         $status === 'canceled' || 
                         str_contains(strtolower($request->fullUrl()), 'cancel');
            
            if ($isCancelled) {
                $this->updateSimplePayment($payment['id'], [
                    'status' => 'cancelled',
                    'metadata' => json_encode(array_merge(
                        json_decode($payment['metadata'] ?? '{}', true),
                        [
                            'redirect_cancelled_at' => Carbon::now()->toISOString(),
                            'redirect_url' => $request->fullUrl()
                        ]
                    ))
                ]);
                
                Log::info('Paiement annulé via redirection', [
                    'payment_id' => $payment['id'],
                    'transaction_id' => $transactionId
                ]);
                
                return $this->generateSimplePage('cancelled', 'Paiement annulé', $payment);
            }
            
            // Synchroniser avec FedaPay
            $this->syncWithFedapay($payment);
            
            // Recharger le paiement
            $pdo = $this->createTempConnection();
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $payment['id']]);
            $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
            $pdo = null;
            
            if (!$payment) {
                return $this->generateSimplePage('error', 'Paiement non trouvé après synchronisation');
            }
            
            // Déterminer le type de page
            if (in_array($payment['status'], ['approved', 'completed', 'paid', 'success'])) {
                return $this->generateSimplePage('success', 'Paiement réussi', $payment);
            }
            
            return $this->generateSimplePage('failed', 'Paiement échoué', $payment);
            
        } catch (\Exception $e) {
            Log::error('Erreur traitement redirection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->generateSimplePage('error', $e->getMessage());
        }
    }
    
    /**
     * TRAITER WEBHOOK POST
     */
    private function handleWebhookPost(Request $request): JsonResponse
    {
        try {
            $event = $request->input('event');
            $data = $request->input('data', []);
            
            if (!$event || !isset($data['transaction'])) {
                Log::warning('Format webhook POST invalide', $request->all());
                return response()->json(['success' => false, 'message' => 'Format invalide'], 400);
            }
            
            $transaction = $data['transaction'];
            $transactionId = $transaction['id'] ?? null;
            
            if (!$transactionId) {
                return response()->json(['success' => false, 'message' => 'Transaction ID manquant'], 400);
            }
            
            // Chercher le paiement
            $pdo = $this->createTempConnection();
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE transaction_id = :transaction_id LIMIT 1");
            $stmt->execute([':transaction_id' => $transactionId]);
            $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
            $pdo = null;
            
            if (!$payment) {
                Log::warning('Paiement non trouvé pour webhook', ['transaction_id' => $transactionId]);
                return response()->json(['success' => false, 'message' => 'Paiement non trouvé'], 404);
            }
            
            // Déterminer le nouveau statut
            $newStatus = match($event) {
                'transaction.approved' => 'approved',
                'transaction.canceled' => 'cancelled',
                'transaction.declined' => 'failed',
                'transaction.pending' => ($request->input('data.close', false) ? 'cancelled' : 'pending'),
                default => $payment['status']
            };
            
            if ($newStatus !== $payment['status']) {
                $updateData = [
                    'status' => $newStatus,
                    'metadata' => json_encode(array_merge(
                        json_decode($payment['metadata'] ?? '{}', true),
                        [
                            'webhook_received_at' => Carbon::now()->toISOString(),
                            'webhook_event' => $event
                        ]
                    )),
                    'updated_at' => Carbon::now()
                ];
                
                // Si réussi, marquer la date de paiement et créer les votes
                if (in_array($newStatus, ['approved', 'completed', 'paid', 'success'])) {
                    $updateData['paid_at'] = Carbon::now();
                    
                    // Créer les votes (asynchrone pour éviter les erreurs)
                    $this->createVotesAsync($payment['id']);
                }
                
                $this->updateSimplePayment($payment['id'], $updateData);
                
                Log::info('Statut mis à jour via webhook', [
                    'payment_id' => $payment['id'],
                    'old_status' => $payment['status'],
                    'new_status' => $newStatus,
                    'event' => $event
                ]);
            }
            
            return response()->json(['success' => true, 'message' => 'Webhook traité']);
            
        } catch (\Exception $e) {
            Log::error('Erreur webhook POST', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['success' => false, 'message' => 'Erreur serveur'], 500);
        }
    }
    
    /**
     * SYNCHRONISER AVEC FEDAPAY
     */
    private function syncWithFedapay(array $payment): void
    {
        try {
            $apiKey = config('services.fedapay.secret_key');
            $environment = config('services.fedapay.environment', 'live');
            
            if (!$apiKey || empty($payment['transaction_id'])) {
                return;
            }
            
            $baseUrl = $environment === 'sandbox' 
                ? 'https://sandbox-api.fedapay.com/v1'
                : 'https://api.fedapay.com/v1';
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json'
            ])->timeout(10)->get($baseUrl . '/transactions/' . $payment['transaction_id']);
            
            if ($response->successful()) {
                $fedapayData = $response->json();
                $transaction = $fedapayData['v1/transaction'] ?? $fedapayData['data'] ?? null;
                
                if ($transaction && isset($transaction['status'])) {
                    $newStatus = $this->mapFedapayStatus($transaction['status']);
                    
                    if ($newStatus !== $payment['status']) {
                        $updateData = [
                            'status' => $newStatus,
                            'metadata' => json_encode(array_merge(
                                json_decode($payment['metadata'] ?? '{}', true),
                                [
                                    'last_sync_at' => Carbon::now()->toISOString(),
                                    'fedapay_status' => $transaction['status']
                                ]
                            )),
                            'updated_at' => Carbon::now()
                        ];
                        
                        if (in_array($newStatus, ['approved', 'completed', 'paid', 'success'])) {
                            $updateData['paid_at'] = Carbon::now();
                            $this->createVotesAsync($payment['id']);
                        }
                        
                        $this->updateSimplePayment($payment['id'], $updateData);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erreur synchronisation FedaPay', [
                'error' => $e->getMessage(),
                'transaction_id' => $payment['transaction_id'] ?? 'N/A'
            ]);
        }
    }
    
    /**
     * MAPPER LE STATUT FEDAPAY
     */
    private function mapFedapayStatus(string $fedapayStatus): string
    {
        return match($fedapayStatus) {
            'pending' => 'pending',
            'approved' => 'approved',
            'canceled' => 'cancelled',
            'declined' => 'failed',
            'transferred' => 'approved',
            default => $fedapayStatus
        };
    }
    
    /**
     * CRÉER LES VOTES DE MANIÈRE ASYNCHRONE
     */
    private function createVotesAsync($paymentId): void
    {
        // Lancer un job asynchrone ou faire un appel HTTP
        // Pour éviter les problèmes de transaction
        try {
            // Version simple - directement dans le même processus
            // mais avec une connexion TEMPORAIRE
            $pdo = $this->createTempConnection();
            
            // Récupérer le paiement
            $stmt = $pdo->prepare("
                SELECT p.*, c.id as candidature_id 
                FROM payments p
                LEFT JOIN candidatures c ON 
                    c.candidat_id = p.candidat_id 
                    AND c.edition_id = p.edition_id 
                    AND c.category_id = p.category_id
                WHERE p.id = :id
            ");
            $stmt->execute([':id' => $paymentId]);
            $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$payment || !in_array($payment['status'], ['approved', 'completed', 'paid', 'success'])) {
                $pdo = null;
                return;
            }
            
            $metadata = json_decode($payment['metadata'] ?? '{}', true);
            $votesCount = $metadata['votes_count'] ?? 1;
            
            // Créer la candidature si elle n'existe pas
            if (empty($payment['candidature_id']) && $payment['category_id']) {
                $stmt = $pdo->prepare("
                    INSERT INTO candidatures 
                    (candidat_id, edition_id, category_id, nombre_votes, created_at, updated_at)
                    VALUES (:candidat_id, :edition_id, :category_id, 0, :now, :now)
                    RETURNING id
                ");
                $stmt->execute([
                    ':candidat_id' => $payment['candidat_id'],
                    ':edition_id' => $payment['edition_id'],
                    ':category_id' => $payment['category_id'],
                    ':now' => Carbon::now()
                ]);
                $candidatureId = $stmt->fetch(\PDO::FETCH_COLUMN);
            } else {
                $candidatureId = $payment['candidature_id'];
            }
            
            // Créer les votes
            if ($candidatureId) {
                for ($i = 0; $i < $votesCount; $i++) {
                    $stmt = $pdo->prepare("
                        INSERT INTO votes 
                        (candidature_id, payment_id, is_paid, created_at, updated_at)
                        VALUES (:candidature_id, :payment_id, true, :now, :now)
                    ");
                    $stmt->execute([
                        ':candidature_id' => $candidatureId,
                        ':payment_id' => $paymentId,
                        ':now' => Carbon::now()
                    ]);
                }
                
                // Mettre à jour le compteur
                $stmt = $pdo->prepare("
                    UPDATE candidatures 
                    SET nombre_votes = nombre_votes + :votes_count,
                        updated_at = :now
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':votes_count' => $votesCount,
                    ':now' => Carbon::now(),
                    ':id' => $candidatureId
                ]);
            }
            
            // Mettre à jour les métadonnées
            $metadata['votes_created_at'] = Carbon::now()->toISOString();
            $metadata['votes_created'] = $votesCount;
            
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET metadata = :metadata, updated_at = :now
                WHERE id = :id
            ");
            $stmt->execute([
                ':metadata' => json_encode($metadata),
                ':now' => Carbon::now(),
                ':id' => $paymentId
            ]);
            
            Log::info('Votes créés avec succès', [
                'payment_id' => $paymentId,
                'votes_count' => $votesCount,
                'candidature_id' => $candidatureId
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur création votes', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
        } finally {
            if (isset($pdo)) {
                $pdo = null;
            }
        }
    }
    
    /**
     * GÉNÉRER UNE PAGE SIMPLE
     */
    private function generateSimplePage(string $type, string $message, ?array $payment = null): \Illuminate\Http\Response
    {
        $config = [
            'success' => [
                'title' => 'Paiement Réussi',
                'color' => '#10B981',
                'icon' => '✓',
                'redirect' => config('app.frontend_url', '') . '/payment/success'
            ],
            'failed' => [
                'title' => 'Paiement Échoué',
                'color' => '#EF4444',
                'icon' => '✗',
                'redirect' => config('app.frontend_url', '') . '/payment/failed'
            ],
            'cancelled' => [
                'title' => 'Paiement Annulé',
                'color' => '#F59E0B',
                'icon' => '↶',
                'redirect' => config('app.frontend_url', '') . '/payment/cancelled'
            ],
            'error' => [
                'title' => 'Erreur',
                'color' => '#6B7280',
                'icon' => '⚠',
                'redirect' => config('app.frontend_url', '')
            ]
        ];
        
        $settings = $config[$type] ?? $config['error'];
        
        // Données du paiement
        $paymentDetails = '';
        if ($payment) {
            $metadata = json_decode($payment['metadata'] ?? '{}', true);
            $paymentDetails = "
            <div style='margin: 20px 0; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 10px;'>
                <h3 style='margin: 0 0 10px 0; color: white;'>Détails du paiement</h3>
                <div style='color: rgba(255,255,255,0.9); font-size: 14px;'>
                    <div><strong>Référence:</strong> {$payment['reference']}</div>
                    <div><strong>Montant:</strong> " . number_format($payment['amount'], 0, ',', ' ') . " XOF</div>
                    <div><strong>Candidat:</strong> " . ($metadata['candidat_name'] ?? '') . "</div>
                    <div><strong>Votes:</strong> " . ($metadata['votes_count'] ?? 1) . "</div>
                </div>
            </div>";
        }
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$settings['title']} - Show Us Talent</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: {$settings['color']};
            color: white;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
        }
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        p {
            margin: 0 0 30px 0;
            font-size: 18px;
            opacity: 0.9;
        }
        .countdown {
            font-size: 48px;
            font-weight: bold;
            margin: 20px 0;
            font-variant-numeric: tabular-nums;
        }
        .note {
            font-size: 14px;
            opacity: 0.7;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">{$settings['icon']}</div>
        <h1>{$settings['title']}</h1>
        <p>{$message}</p>
        {$paymentDetails}
        <div class="countdown" id="countdown">3</div>
        <p>Redirection automatique...</p>
        <div class="note">Vous pouvez fermer cette fenêtre</div>
    </div>
    
    <script>
        let seconds = 3;
        const countdownEl = document.getElementById('countdown');
        
        function updateCountdown() {
            countdownEl.textContent = seconds;
            if (seconds <= 0) {
                try {
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'PAYMENT_RESULT',
                            result: '{$type}',
                            payment: " . json_encode($payment ?: []) . "
                        }, '*');
                    }
                } catch(e) {}
                
                setTimeout(() => {
                    window.location.href = '{$settings['redirect']}';
                }, 500);
            } else {
                seconds--;
                setTimeout(updateCountdown, 1000);
            }
        }
        
        document.addEventListener('DOMContentLoaded', updateCountdown);
    </script>
</body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * RÉINITIALISER LA CONNEXION POSTGRESQL (en cas d'urgence)
     */
    public function resetConnection(): JsonResponse
    {
        try {
            // Purger toutes les connexions
            if (class_exists('DB')) {
                DB::purge('pgsql');
                DB::reconnect('pgsql');
            }
            
            // Tester
            $pdo = $this->createTempConnection();
            $test = $pdo->query("SELECT 1 as test")->fetch(\PDO::FETCH_ASSOC);
            $pdo = null;
            
            return response()->json([
                'success' => true,
                'message' => 'Connexion réinitialisée',
                'test' => $test
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }
}