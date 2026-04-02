# Ripples PHP SDK


Server-side PHP SDK for [Ripples.sh](https://ripples.sh) analytics.


## Install


```bash
composer require ripplesanalytics/ripples-php
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
$ripples->identify('user_123', ['email' => 'jane@example.com']);
```


That's it.


## Track revenue


```php
$ripples->revenue(49.99, 'user_123');
```


Any key you pass that isn't a known field becomes a custom property automatically:# Ripples PHP SDK

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
$ripples->identify('user_123', ['email' => 'jane@example.com']);
```

That's it.

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
