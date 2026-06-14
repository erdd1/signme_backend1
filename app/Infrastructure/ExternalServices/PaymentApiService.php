<?php

namespace App\Infrastructure\ExternalServices;

use Illuminate\Support\Facades\Http;

/**
 * Class PaymentApiService
 * 
 * Service pour communiquer avec les APIs externes de paiement
 */
class PaymentApiService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('payment.gateway_base_url');
        $this->apiKey = config('payment.gateway_api_key');
    }

    /**
     * Effectue un appel API au service de paiement
     */
    public function call($endpoint, $method = 'POST', $data = [])
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->{strtolower($method)}("{$this->baseUrl}{$endpoint}", $data);

            return $response->json();
        } catch (\Exception $e) {
            \Log::error('Payment API Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
