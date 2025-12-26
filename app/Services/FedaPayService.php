<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FedaPayService
{
    private $apiKey;
    private $environment;
    private $baseUrl;
    private $checkoutUrl;

    public function __construct()
    {
        $this->apiKey = config('services.fedapay.secret_key');
        $this->environment = config('services.fedapay.environment', 'sandbox');
        
        if ($this->environment === 'sandbox') {
            $this->baseUrl = 'https://sandbox-api.fedapay.com/v1';
            $this->checkoutUrl = 'https://sandbox-checkout.fedapay.com';
        } else {
            $this->baseUrl = 'https://api.fedapay.com/v1';
            $this->checkoutUrl = 'https://checkout.fedapay.com';
        }
    }

    /**
     * Créer une transaction
     */
    public function createTransaction(array $data): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->timeout(30)->post($this->baseUrl . '/transactions', $data);

            Log::info('FedaPay API Response', [
                'status' => $response->status(),
                'data' => $response->json()
            ]);

            if (!$response->successful()) {
                $error = $response->json();
                throw new \Exception($error['error'] ?? 'Erreur FedaPay inconnue');
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Erreur FedaPay createTransaction', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Récupérer une transaction
     */
    public function getTransaction(string $transactionId): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json'
            ])->get($this->baseUrl . '/transactions/' . $transactionId);

            if (!$response->successful()) {
                throw new \Exception('Transaction non trouvée');
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Erreur FedaPay getTransaction', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Obtenir l'URL de checkout
     */
    public function getCheckoutUrl(string $transactionId): string
    {
        return $this->checkoutUrl . '/' . $transactionId;
    }

    /**
     * Valider la signature du webhook
     */
    public function verifyWebhookSignature(string $signature, string $payload): bool
    {
        $secret = config('services.fedapay.webhook_secret');
        
        if (!$secret) {
            // En développement, accepter sans vérification
            return app()->environment('local', 'testing');
        }

        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Formater le numéro pour FedaPay
     */
    public function formatPhoneNumber(string $phone): string
    {
        // Nettoyer
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Ajouter l'indicatif si manquant
        if (str_starts_with($phone, '0')) {
            $phone = '229' . substr($phone, 1);
        }
        
        // S'assurer qu'on a l'indicatif
        if (!str_starts_with($phone, '229') && strlen($phone) >= 8) {
            $phone = '229' . $phone;
        }
        
        // Vérifier la longueur
        if (strlen($phone) < 11 || strlen($phone) > 12) {
            throw new \Exception('Numéro de téléphone invalide');
        }
        
        return '+' . $phone;
    }
}