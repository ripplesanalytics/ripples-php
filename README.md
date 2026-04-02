# Ripples PHP SDK

Server-side PHP SDK for [Ripples.sh](https://ripples.sh) analytics.

## Install

```bash
composer require ripples/ripples-php
```

Add to your `.env`:

```
RIPPLES_SECRET_KEY=priv_your_secret_key
```

## Usage

```php
use Ripples\Ripples;

$ripples = new Ripples();

$ripples->revenue(49.99, 'user_123');
$ripples->signup('user_123', ['email' => 'jane@example.com']);
$ripples->track('created a budget', 'user_123', ['area' => 'budgets']);
$ripples->identify('user_123', ['email' => 'jane@example.com']);
```

That's it.

## Track product usage

Call `track()` when a user does something meaningful in your product. Ripples auto-detects activation (first occurrence per user), computes adoption rates, and correlates with retention and payment.

```php
$ripples->track('created a budget', 'user_123', ['area' => 'budgets']);
$ripples->track('shared a list', 'user_123', ['area' => 'sharing', 'via' => 'link']);
$ripples->track('exported report', 'user_123', ['area' => 'reports', 'format' => 'csv']);
```

Use `area` to group actions into product areas. Use `activated => true` to mark the specific moment a user activates — it flags this occurrence, not the event type:

```php
// User added their 10th transaction — we consider this their activation moment
$ripples->track('added transaction', 'user_123', [
    'area'      => 'transactions',
    'activated' => true,  // only on THIS occurrence
]);
```

## Track revenue

```php
$ripples->revenue(49.99, 'user_123');
```

Any key you pass that isn't a known field becomes a custom property automatically:

```php
$ripples->revenue(49.99, 'user_123', [
    'email'          => 'jane@example.com',
    'currency'       => 'EUR',
    'transaction_id' => 'txn_abc123',
    'name'           => 'Pro Plan',
    'plan'           => 'annual',       // custom property
    'coupon'         => 'WELCOME20',    // custom property
]);
```

Refunds are just negative revenue:

```php
$ripples->revenue(-29.99, 'user_123', ['transaction_id' => 'txn_abc123']);
```

## Track signups

```php
$ripples->signup('user_123', [
    'email'    => 'jane@example.com',
    'name'     => 'Jane Smith',
    'referral' => 'twitter',    // custom property
    'plan'     => 'free',       // custom property
]);
```

## Identify users

Update user traits at any time:

```php
$ripples->identify('user_123', [
    'email'   => 'jane@example.com',
    'name'    => 'Jane Smith',
    'company' => 'Acme Inc',   // custom property
    'role'    => 'admin',      // custom property
]);
```

## Error handling

```php
use Ripples\RipplesException;

try {
    $ripples->revenue(49.99, 'user_123');
} catch (RipplesException $e) {
    // handle error
}
```

## Configuration

The SDK reads `RIPPLES_SECRET_KEY` from your environment automatically. You can override everything:

```php
$ripples = new Ripples('priv_explicit_key', [
    'base_url' => 'https://your-domain.com/api', // self-hosted
    'timeout'  => 10, // seconds (default: 5)
]);
```

Self-hosted URL can also be set via env:

```
RIPPLES_URL=https://your-domain.com/api
```

## Custom HTTP client

Extend the class and override `post()` to use Guzzle, Symfony HTTP, or anything else:

```php
class MyRipples extends \Ripples\Ripples
{
    protected function post(string $path, array $data): array
    {
        // your custom implementation
    }
}
```

## Requirements

- PHP 8.1+
- ext-curl
- ext-json

## License

MIT
