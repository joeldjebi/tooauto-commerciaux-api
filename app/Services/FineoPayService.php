<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FineoPayService
{
    protected string $baseUrl;
    protected string $checkoutPath;
    protected ?string $businessCode;
    protected ?string $apiKey;
    protected ?string $callbackToken;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.fineopay.base_url'), '/');
        $this->checkoutPath = '/' . ltrim(config('services.fineopay.checkout_path'), '/');
        $this->businessCode = config('services.fineopay.business_code');
        $this->apiKey = config('services.fineopay.api_key');
        $this->callbackToken = config('services.fineopay.callback_token');
    }

    public function createCheckoutLink(array $payload): array
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'businessCode' => $this->businessCode,
            'apiKey' => $this->apiKey,
        ])->post($this->checkoutUrl(), $payload);

        return [
            'http_status' => $response->status(),
            'body' => $response->json(),
        ];
    }

    public function checkoutUrl(): string
    {
        return $this->baseUrl . $this->checkoutPath;
    }

    public function callbackUrl(): string
    {
        $url = url('/api/v1/fineopay/callback');

        if (!$this->callbackToken) {
            return $url;
        }

        return $url . '?token=' . urlencode($this->callbackToken);
    }

    public function isValidCallbackToken(?string $token): bool
    {
        return !empty($this->callbackToken) && hash_equals($this->callbackToken, (string) $token);
    }
}
