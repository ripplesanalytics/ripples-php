<?php

namespace Ripples;

class Ripples
{
    protected string $secretKey;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct(?string $secretKey = null, array $options = [])
    {
        $this->secretKey = $secretKey ?? getenv('RIPPLES_SECRET_KEY') ?: ($_ENV['RIPPLES_SECRET_KEY'] ?? $_SERVER['RIPPLES_SECRET_KEY'] ?? '');
        $this->baseUrl = rtrim($options['base_url'] ?? getenv('RIPPLES_URL') ?: 'https://api.ripples.sh', '/');
        $this->timeout = $options['timeout'] ?? 5;

        if ($this->secretKey === '') {
            throw new RipplesException('Missing secret key. Set RIPPLES_SECRET_KEY in your .env or pass it to the constructor.');
        }
    }

    /**
     * Track revenue.
     *
     * At least one of user_id, email, or visitor_id is required.
     * Any extra keys become custom properties automatically.
     */
    public function revenue(float $amount, string $userId, array $attributes = []): array
    {
        return $this->post('/v1/ingest/revenue', ['amount' => $amount, 'user_id' => $userId, ...$attributes]);
    }

    /**
     * Track a signup.
     *
     * Any extra keys beyond the known fields become custom properties automatically.
     */
    public function signup(string $userId, array $attributes = []): array
    {
        return $this->post('/v1/ingest/signup', ['user_id' => $userId, ...$attributes]);
    }

    /**
     * Identify a user (set or update traits).
     *
     * Any extra keys beyond the known fields become custom properties automatically.
     */
    public function identify(string $userId, array $attributes = []): array
    {
        return $this->post('/v1/ingest/identify', ['user_id' => $userId, ...$attributes]);
    }

    /**
     * Send a POST request to the Ripples API.
     *
     * Override this method to use Guzzle, Symfony HTTP, or any other client.
     */
    protected function post(string $path, array $data): array
    {
        $url = $this->baseUrl . $path;
        $json = json_encode($data);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->secretKey,
            ],
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RipplesException("HTTP request failed: {$error}");
        }

        if ($status >= 400) {
            $decoded = json_decode($body, true) ?? [];
            $message = $decoded['error'] ?? "HTTP {$status}";
            throw new RipplesException($message, $status);
        }

        return json_decode($body, true) ?? [];
    }
}
