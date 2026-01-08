<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Vote;
use App\Models\Candidature;
use App\Models\User;
use App\Models\Edition;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class PaymentController extends Controller
{
    private $votePrice = 100;

    /**
     * Initialiser un paiement
     */
    public function initiatePayment(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'candidat_id' => 'required|exists:users,id',
                'edition_id' => 'required|exists:editions,id',
                'category_id' => 'nullable|exists:categories,id',
                'votes_count' => 'required|integer|min:1|max:100',
                'email' => 'required|email|max:100',
                'phone' => 'required|string|min:8|max:15',
                'firstname' => 'required|string|max:50',
                'lastname' => 'required|string|max:50'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            
            // Valider et formater le téléphone
            $phone = $this->validateAndFormatPhone($data['phone']);
            if (!$phone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Numéro de téléphone invalide',
                    'errors' => ['phone' => ['Le numéro doit être au format: 0XXXXXXXXX ou 229XXXXXXXX']]
                ], 422);
            }

            $edition = Edition::findOrFail($data['edition_id']);
            
            if (!$edition->isVoteOpen()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les votes ne sont pas ouverts pour cette édition.'
                ], 400);
            }

            $candidat = User::findOrFail($data['candidat_id']);
            
            $category = null;
            if ($data['category_id']) {
                $category = Category::findOrFail($data['category_id']);
                
                $candidature = Candidature::where('candidat_id', $data['candidat_id'])
                    ->where('edition_id', $data['edition_id'])
                    ->where('category_id', $data['category_id'])
                    ->first();

                if (!$candidature) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ce candidat ne participe pas à cette catégorie.'
                    ], 400);
                }
            }

            $amount = $this->votePrice * $data['votes_count'];

            $payment = Payment::create([
                'reference' => 'VOTE-' . strtoupper(Str::random(10)),
                'user_id' => null,
                'edition_id' => $data['edition_id'],
                'candidat_id' => $data['candidat_id'],
                'category_id' => $data['category_id'] ?? null,
                'transaction_id' => null,
                'amount' => $amount,
                'montant' => $amount,
                'currency' => 'XOF',
                'status' => 'pending',
                'payment_token' => Str::uuid(),
                'payment_method' => null,
                'customer_email' => $data['email'],
                'email_payeur' => $data['email'],
                'customer_phone' => $phone,
                'customer_firstname' => $data['firstname'],
                'customer_lastname' => $data['lastname'],
                'metadata' => [
                    'votes_count' => $data['votes_count'],
                    'vote_price' => $this->votePrice,
                    'candidat_name' => $candidat->nom_complet ?? $candidat->nom . ' ' . $candidat->prenoms,
                    'edition_name' => $edition->nom,
                    'category_name' => $category->nom ?? 'Non spécifiée',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => Carbon::now()->toISOString()
                ],
                'expires_at' => Carbon::now()->addMinutes(30) // Réduit à 30 minutes
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paiement initialisé avec succès',
                'data' => [
                    'payment_token' => $payment->payment_token,
                    'amount' => $amount,
                    'currency' => 'XOF',
                    'votes_count' => $data['votes_count'],
                    'candidat_name' => $candidat->nom_complet ?? $candidat->nom . ' ' . $candidat->prenoms,
                    'edition_name' => $edition->nom,
                    'category_name' => $category->nom ?? 'Non spécifiée',
                    'expires_at' => $payment->expires_at->toISOString(),
                    'check_status_url' => url("/api/payments/{$payment->payment_token}/status"),
                    'success_url' => url("/payments/{$payment->payment_token}/success/redirect"),
                    'failed_url' => url("/payments/{$payment->payment_token}/failed/redirect")
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erreur initiation paiement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'initialisation du paiement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Traiter le paiement
     */
    
    public function processPayment(Request $request): JsonResponse{
        try {
            $validator = Validator::make($request->all(), [
                'payment_token' => 'required|exists:payments,payment_token',
                'payment_method' => 'required|in:mobile_money,card'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de paiement invalide'
                ], 422);
            }

            $payment = Payment::where('payment_token', $request->payment_token)->first();
            
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
            if ($payment->expires_at && $payment->expires_at->isPast()) {
                $payment->update(['status' => 'expired']);
                return response()->json([
                    'success' => false,
                    'message' => 'Le paiement a expiré'
                ], 400);
            }

            $edition = Edition::find($payment->edition_id);
            if (!$edition || !$edition->isVoteOpen()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les votes ne sont plus ouverts pour cette édition'
                ], 400);
            }

            // Si le paiement était annulé ou échoué, le réinitialiser
            if (in_array($payment->status, ['cancelled', 'failed', 'expired'])) {
                // Conserver certaines métadonnées importantes
                $metadata = $payment->metadata ?? [];
                $importantMetadata = [
                    'votes_count' => $metadata['votes_count'] ?? 1,
                    'vote_price' => $metadata['vote_price'] ?? $this->votePrice,
                    'candidat_name' => $metadata['candidat_name'] ?? '',
                    'edition_name' => $metadata['edition_name'] ?? '',
                    'category_name' => $metadata['category_name'] ?? '',
                    'ip_address' => $metadata['ip_address'] ?? null,
                    'user_agent' => $metadata['user_agent'] ?? null,
                    'created_at' => $metadata['created_at'] ?? Carbon::now()->toISOString(),
                    'previous_status' => $payment->status,
                    'retry_count' => ($metadata['retry_count'] ?? 0) + 1,
                    'retry_at' => Carbon::now()->toISOString()
                ];
                
                $payment->update([
                    'status' => 'pending',
                    'transaction_id' => null,
                    'payment_method' => null,
                    'metadata' => $importantMetadata,
                    'expires_at' => Carbon::now()->addMinutes(30)
                ]);
                
                Log::info('Paiement réinitialisé pour nouvelle tentative', [
                    'payment_id' => $payment->id,
                    'previous_status' => $payment->status,
                    'retry_count' => $importantMetadata['retry_count']
                ]);
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
     * Traiter un paiement FedaPay
     */
    private function processFedaPayPayment(Payment $payment, string $paymentMethod): JsonResponse {
        try {
            $apiKey = config('services.fedapay.secret_key');
            $environment = config('services.fedapay.environment', 'live');
            
            if (!$apiKey) {
                throw new \Exception('Clé API FedaPay non configurée');
            }

            Log::info('Création transaction FedaPay', [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'environment' => $environment
            ]);

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

            $transactionData = [
                'description' => sprintf(
                    'Vote pour %s (%d vote%s) - %s',
                    $payment->metadata['candidat_name'] ?? 'Candidat',
                    $payment->metadata['votes_count'] ?? 1,
                    $payment->metadata['votes_count'] > 1 ? 's' : '',
                    $payment->metadata['edition_name'] ?? 'Édition'
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

            Log::info('Réponse FedaPay', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if (!$response->successful()) {
                $errorDetails = $response->json();
                Log::error('Erreur FedaPay API', [
                    'status' => $response->status(),
                    'error' => $errorDetails,
                    'payment_id' => $payment->id
                ]);
                
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
            $payment->update([
                'transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
                'status' => 'processing',
                'metadata' => array_merge($payment->metadata ?? [], [
                    'fedapay_transaction_id' => $transactionId,
                    'fedapay_payment_token' => $transaction['payment_token'] ?? null,
                    'processed_at' => Carbon::now()->toISOString(),
                    'payment_url' => $paymentUrl
                ])
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transaction créée avec succès',
                'data' => [
                    'redirect_url' => $paymentUrl,
                    'transaction_id' => $transactionId,
                    'payment_token' => $payment->payment_token,
                    'payment_method' => $paymentMethod,
                    'expires_at' => $payment->expires_at?->toISOString(),
                    'check_status_url' => url("/api/payments/{$payment->payment_token}/status"),
                    'success_redirect_url' => url("/payments/{$payment->payment_token}/success/redirect"),
                    'failed_redirect_url' => url("/payments/{$payment->payment_token}/failed/redirect")
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur FedaPay', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_id' => $payment->id ?? 'N/A'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du paiement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifier le statut du paiement
     */
    public function checkPaymentStatus($token): JsonResponse{
        try {
            $payment = Payment::where('payment_token', $token)->firstOrFail();
            
            // Synchroniser avec FedaPay si nécessaire
            if ($payment->transaction_id && in_array($payment->status, ['pending', 'processing'])) {
                $this->syncPaymentStatusWithFedapay($payment);
                $payment->refresh();
            }

            // Vérifier l'expiration
            $isExpired = $payment->expires_at && $payment->expires_at->isPast();
            if ($isExpired && $payment->status === 'pending') {
                $payment->update(['status' => 'expired']);
                $payment->refresh();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $payment->status,
                    'is_successful' => in_array($payment->status, ['approved', 'completed', 'paid', 'success']),
                    'is_expired' => $isExpired,
                    'amount' => $payment->amount,
                    'votes_count' => $payment->metadata['votes_count'] ?? 1,
                    'candidat_name' => $payment->metadata['candidat_name'] ?? '',
                    'created_at' => $payment->created_at->toISOString(),
                    'paid_at' => $payment->paid_at?->toISOString(),
                    'payment_method' => $payment->payment_method,
                    'transaction_id' => $payment->transaction_id,
                    'reference' => $payment->reference,
                    'payment_token' => $payment->payment_token,
                    'check_interval' => 3000 // Interval de vérification en millisecondes
                ]
            ]);

        } catch (\Exception $e) {
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
            $payment = Payment::where('payment_token', $token)->firstOrFail();

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
                        'paid_at' => $payment->paid_at?->format('d/m/Y H:i'),
                        'payment_method' => $payment->payment_method,
                        'status' => $payment->status
                    ],
                    'vote_details' => [
                        'votes_count' => $payment->metadata['votes_count'] ?? 1,
                        'candidat_name' => $payment->metadata['candidat_name'] ?? '',
                        'edition_name' => $payment->metadata['edition_name'] ?? '',
                        'category_name' => $payment->metadata['category_name'] ?? ''
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
            $payment = Payment::where('payment_token', $token)->firstOrFail();
            
            // Créer une page HTML qui ferme automatiquement la fenêtre et communique avec le parent
            $html = $this->generateAutoClosePage($payment, 'success');
            
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
            $payment = Payment::where('payment_token', $token)->firstOrFail();

            return response()->json([
                'success' => false,
                'message' => 'Paiement échoué ou annulé',
                'data' => [
                    'status' => $payment->status,
                    'can_retry' => in_array($payment->status, ['failed', 'cancelled', 'expired']),
                    'expires_at' => $payment->expires_at?->format('d/m/Y H:i'),
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
            $payment = Payment::where('payment_token', $token)->firstOrFail();
            
            $html = $this->generateAutoClosePage($payment, 'failed');
            
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
     * Générer une page HTML qui ferme automatiquement la fenêtre
     */
   

    /**
     * Méthodes auxiliaires (inchangées mais incluses pour complétude)
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

    private function createVotesFromPayment(Payment $payment): void {
        $votesCount = $payment->metadata['votes_count'] ?? 1;
        
        $candidature = Candidature::firstOrCreate(
            [
                'candidat_id' => $payment->candidat_id,
                'edition_id' => $payment->edition_id,
                'category_id' => $payment->category_id
            ],
            [
                'nombre_votes' => 0,
                'status' => 'active'
            ]
        );

        for ($i = 0; $i < $votesCount; $i++) {
            Vote::create([
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
                'ip_address' => $payment->metadata['ip_address'] ?? null,
                'user_agent' => $payment->metadata['user_agent'] ?? null,
                'created_at' => Carbon::now()
            ]);
        }

        $candidature->increment('nombre_votes', $votesCount);

        Log::info('Votes créés depuis paiement', [
            'payment_id' => $payment->id,
            'votes_count' => $votesCount,
            'candidature_id' => $candidature->id,
            'candidat_id' => $payment->candidat_id
        ]);
    }

    /**
     * Vérifier un paiement
     */
    public function verifyPayment($token): JsonResponse{
        try {
            $payment = Payment::where('payment_token', $token)->firstOrFail();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'exists' => true,
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'expires_at' => $payment->expires_at?->toISOString(),
                    'candidat_name' => $payment->metadata['candidat_name'] ?? '',
                    'votes_count' => $payment->metadata['votes_count'] ?? 1
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
        DB::beginTransaction();
        
        try {
            $payment = Payment::where('payment_token', $token)
                ->where('status', 'pending')
                ->firstOrFail();

            $payment->update(['status' => 'cancelled']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paiement annulé avec succès'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
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

            $payments = Payment::where('customer_email', $user->email)
                ->orWhere('email_payeur', $user->email)
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
    private function generateAutoClosePage(?Payment $payment, string $type, string $message = ''): \Illuminate\Http\Response{
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

        // -------------------------
        // Données paiement
        // -------------------------
        $paymentData = null;
        $paymentDetailsHtml = '';

        if ($payment) {
            $paymentData = [
                'token' => $payment->payment_token,
                'status' => $payment->status,
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'candidat_name' => $payment->metadata['candidat_name'] ?? '',
                'votes_count' => $payment->metadata['votes_count'] ?? 1
            ];

            $votes = $payment->metadata['votes_count'] ?? 1;

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
                        <span class="detail-value">' . htmlspecialchars($payment->metadata['candidat_name'] ?? '', ENT_QUOTES, 'UTF-8') . '</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Votes</span>
                        <span class="detail-value">' . $votes . ' vote' . ($votes > 1 ? 's' : '') . '</span>
                    </div>
                </div>
            </div>';
        }

        // -------------------------
        // Sécurisation variables JS
        // -------------------------
        $jsonData      = htmlspecialchars(json_encode($paymentData), ENT_QUOTES, 'UTF-8');
        $typeEscaped   = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
        $titleEscaped  = htmlspecialchars($settings['title'], ENT_QUOTES, 'UTF-8');
        $messageEscaped= htmlspecialchars($settings['message'], ENT_QUOTES, 'UTF-8');
        $iconEscaped   = htmlspecialchars($settings['icon'], ENT_QUOTES, 'UTF-8');
        $iconColor     = htmlspecialchars($settings['iconColor'], ENT_QUOTES, 'UTF-8');
        $gradient      = htmlspecialchars($settings['gradient'], ENT_QUOTES, 'UTF-8');
        $redirectUrl   = htmlspecialchars($settings['redirectUrl'], ENT_QUOTES, 'UTF-8');
        $closeDelay    = (int) $settings['closeDelay'];

        // -------------------------
        // HTML final
        // -------------------------
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
            $payment = Payment::where('transaction_id', $transactionId)->first();
            
            if (!$payment) {
                Log::warning('Paiement non trouvé pour webhook POST', ['transaction_id' => $transactionId]);
                return response()->json(['success' => false, 'message' => 'Paiement non trouvé'], 404);
            }

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
                DB::beginTransaction();
                
                $payment->update([
                    'status' => $newStatus,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'webhook_received_at' => Carbon::now()->toISOString(),
                        'webhook_event' => $event,
                        'webhook_method' => 'POST',
                        'webhook_data' => $data
                    ])
                ]);
                
                // Si le paiement est réussi, créer les votes
                if (in_array($newStatus, ['approved', 'completed', 'paid', 'success']) && 
                    !in_array($payment->status, ['approved', 'completed', 'paid', 'success'])) {
                    $payment->paid_at = Carbon::now();
                    $payment->save();
                    $this->createVotesFromPayment($payment);
                }
                
                DB::commit();
                
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
            $payment = Payment::where('transaction_id', $transactionId)->first();
            
            if (!$payment) {
                Log::warning('Paiement non trouvé pour redirection GET', [
                    'transaction_id' => $transactionId
                ]);
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé');
            }

            // Déterminer le statut
            $isCancelled = ($status === 'pending' && $close === 'true') || 
                        $status === 'canceled' || 
                        $status === 'cancelled' ||
                        str_contains(strtolower($request->fullUrl()), 'cancel');

            if ($isCancelled) {
                // Mettre à jour le statut comme annulé
                DB::beginTransaction();
                
                $payment->update([
                    'status' => 'cancelled',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'redirect_received_at' => Carbon::now()->toISOString(),
                        'redirect_status' => $status,
                        'redirect_close' => $close,
                        'redirect_url' => $request->fullUrl(),
                        'cancelled_at' => Carbon::now()->toISOString(),
                        'cancellation_source' => 'fedapay_redirect'
                    ])
                ]);
                
                DB::commit();
                
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
            $payment->refresh();

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
    private function syncPaymentStatusWithFedapay(Payment $payment): void
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
                        DB::beginTransaction();
                        
                        $payment->update([
                            'status' => $newStatus,
                            'metadata' => array_merge($payment->metadata ?? [], [
                                'last_sync_at' => Carbon::now()->toISOString(),
                                'fedapay_sync_status' => $transaction['status']
                            ])
                        ]);
                        
                        // Si le paiement est réussi, créer les votes
                        if (in_array($newStatus, ['approved', 'completed', 'paid', 'success']) && 
                            !in_array($payment->status, ['approved', 'completed', 'paid', 'success'])) {
                            $payment->paid_at = Carbon::now();
                            $payment->save();
                            $this->createVotesFromPayment($payment);
                        }
                        
                        DB::commit();
                        
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
                $payment = Payment::where('payment_token', $paymentToken)->first();
            }
            
            if (!$payment && $transactionId) {
                $payment = Payment::where('transaction_id', $transactionId)->first();
            }

            if (!$payment) {
                Log::warning('Paiement non trouvé pour callback', [
                    'payment_token' => $paymentToken,
                    'transaction_id' => $transactionId
                ]);
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé');
            }

            // Synchroniser le statut
            if (in_array($payment->status, ['pending', 'processing'])) {
                $this->syncPaymentStatusWithFedapay($payment);
                $payment->refresh();
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
            $payment = Payment::where('payment_token', $paymentToken)->first();
            
            if (!$payment) {
                return $this->generateAutoClosePage(null, 'error', 'Paiement non trouvé');
            }
            
            // Mettre à jour le statut
            DB::beginTransaction();
            
            $payment->update([
                'status' => 'cancelled',
                'metadata' => array_merge($payment->metadata ?? [], [
                    'callback_cancelled_at' => Carbon::now()->toISOString(),
                    'callback_url' => $request->fullUrl()
                ])
            ]);
            
            DB::commit();
            
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