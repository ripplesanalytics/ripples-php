<?php

namespace Ripples;

class Ripples
{
    protected string $secretKey;
    protected string $baseUrl;
    protected int $timeout;
    protected int $connectTimeout;
    /** @var callable|null */
    protected $onError;

    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private int $maxQueueSize;

    public function __construct(?string $secretKey = null, array $options = [])
    {
        $this->secretKey      = $secretKey ?? getenv('RIPPLES_SECRET_KEY') ?: ($_ENV['RIPPLES_SECRET_KEY'] ?? $_SERVER['RIPPLES_SECRET_KEY'] ?? '');
        $this->baseUrl        = rtrim($options['base_url'] ?? getenv('RIPPLES_URL') ?: 'https://api.ripples.sh', '/');
        $this->timeout        = $options['timeout'] ?? 3;
        $this->connectTimeout = $options['connect_timeout'] ?? 2;
        $this->onError        = $options['on_error'] ?? null;
        $this->maxQueueSize   = $options['max_queue_size'] ?? 100;

        if ($this->secretKey === '') {
            throw new RipplesException('Missing secret key. Set RIPPLES_SECRET_KEY in your .env or pass it to the constructor.');
        }

        // In PHP-FPM the runtime calls fastcgi_finish_request() before running
        // shutdown functions, so the HTTP response has already been sent to the
        // client by the time the batch is delivered to Ripples — zero latency
        // impact on your users, guaranteed delivery on every request.
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * Track revenue.
     *
     * At least one of user_id, email, or visitor_id is required.
     * Any extra keys become custom properties automatically.
     */
    public function revenue(float $amount, string $userId, array $attributes = []): void
    {
        $this->enqueue('revenue', ['amount' => $amount, 'user_id' => $userId, ...$attributes]);
    }

    /**
     * Track a signup.
     *
     * Any extra keys beyond the known fields become custom properties automatically.
     */
    public function signup(string $userId, array $attributes = []): void
    {
        $this->enqueue('signup', ['user_id' => $userId, ...$attributes]);
    }

    /**
     * Track significant product usage only — actions that prove a user got real value
     * (created a budget, sent a message, invited a teammate).
     *
     * This is NOT a generic event log like PostHog or Mixpanel. Do not send pageviews,
     * banner impressions, button clicks, or "viewed X" events. Every track() call feeds
     * the Activation dashboard — noise pollutes your funnel.
     *
     * Ripples auto-detects activation (first occurrence per user per action),
     * computes adoption rates, and correlates with retention/payment.
     *
     * Pass 'area' in attributes to group actions into product areas.
     * Pass 'activated' => true to mark this specific occurrence as the activation moment.
     */
    public function track(string $actionName, string $userId, array $attributes = []): void
    {
        $this->enqueue('track', ['name' => $actionName, 'user_id' => $userId, ...$attributes]);
    }

    /**
     * Track a subscription state change for MRR calculation.
     *
     * Call when a subscription is created, upgraded/downgraded, or canceled.
     * For Stripe/Paddle users with a native integration, MRR is tracked automatically
     * — only use this method for other payment providers.
     *
     * @param string $subscriptionId  Your subscription ID (must be stable across updates)
     * @param string $userId          The user who owns the subscription
     * @param string $status          active, canceled, past_due, trialing, paused
     * @param float  $amount          Amount per billing cycle (e.g. 29.00), in your currency
     * @param string $interval        Billing interval: month, year, week, day
     * @param array  $attributes      Optional: currency, name/plan, interval_count
     */
    public function subscription(
        string $subscriptionId,
        string $userId,
        string $status,
        float $amount,
        string $interval = 'month',
        array $attributes = [],
    ): void {
        $this->enqueue('revenue', array_filter([
            'amount' => 0,
            'user_id' => $userId,
            'subscription_id' => $subscriptionId,
            'subscription_status' => $status,
            'subscription_amount' => (string) round($amount * 100),
            'billing_interval' => $interval,
            'billing_interval_count' => (string) ($attributes['interval_count'] ?? 1),
            'currency' => $attributes['currency'] ?? null,
            'name' => $attributes['name'] ?? $attributes['plan'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Identify a user (set or update traits).
     *
     * Any extra keys beyond the known fields become custom properties automatically.
     */
    public function identify(string $userId, array $attributes = []): void
    {
        $this->enqueue('identify', ['user_id' => $userId, ...$attributes]);
    }

    /**
     * Send all queued events to the Ripples API as a single batch request.
     *
     * Called automatically on PHP shutdown (after response is sent in FPM).
     * Call explicitly in CLI scripts or before process exit when you need to
     * guarantee delivery.
     */
    public function flush(): void
    {
        if ($this->queue === []) {
            return;
        }

        $batch       = $this->queue;
        $this->queue = [];

        $this->send('/v1/ingest/batch', ['events' => $batch]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function enqueue(string $type, array $data): void
    {
        $this->queue[] = ['type' => $type, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), ...$data];

        if (\count($this->queue) >= $this->maxQueueSize) {
            $this->flush();
        }
    }

    /**
     * Dispatch a request, swallowing any network or API error so the host
     * application is never disrupted by a Ripples outage or slow response.
     * If an on_error callback is configured it receives the Throwable for logging.
     */
    private function send(string $path, array $data): void
    {
        try {
            $this->post($path, $data);
        } catch (\Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
        }
    }

    /**
     * Send a POST request to the Ripples API.
     *
     * Override this method to use Guzzle, Symfony HTTP, or any other client.
     */
    protected function post(string $path, array $data): void
    {
        $url  = "{$this->baseUrl}{$path}";
        $json = json_encode($data);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                "Authorization: Bearer {$this->secretKey}",
            ],
        ]);

        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);

        if ($error) {
            throw new RipplesException("HTTP request failed: {$error}");
        }

        if ($status >= 400) {
            $decoded = json_decode($body, true) ?? [];
            $message = $decoded['error'] ?? "HTTP {$status}";
            throw new RipplesException($message, $status);
        }
    }
}
