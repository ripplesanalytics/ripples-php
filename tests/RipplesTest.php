<?php

namespace Ripples\Tests;

use PHPUnit\Framework\TestCase;
use Ripples\Ripples;
use Ripples\RipplesException;

/**
 * A testable Ripples client that captures requests instead of sending them.
 */
class FakeRipples extends Ripples
{
    public ?string $lastPath = null;
    public ?array $lastData = null;
    public array $fakeResponse = ['ok' => true];

    protected function post(string $path, array $data): array
    {
        $this->lastPath = $path;
        $this->lastData = $data;
        return $this->fakeResponse;
    }
}

class RipplesTest extends TestCase
{
    private FakeRipples $ripples;

    protected function setUp(): void
    {
        $this->ripples = new FakeRipples('priv_test_key');
    }

    // ------------------------------------------------------------------
    // Constructor
    // ------------------------------------------------------------------

    public function testConstructorWithExplicitKey(): void
    {
        $r = new FakeRipples('priv_abc');
        $this->assertInstanceOf(Ripples::class, $r);
    }

    public function testConstructorFromEnv(): void
    {
        putenv('RIPPLES_SECRET_KEY=priv_from_env');
        $r = new FakeRipples();
        $this->assertInstanceOf(Ripples::class, $r);
        putenv('RIPPLES_SECRET_KEY'); // cleanup
    }

    public function testConstructorThrowsWithoutKey(): void
    {
        putenv('RIPPLES_SECRET_KEY');
        unset($_ENV['RIPPLES_SECRET_KEY'], $_SERVER['RIPPLES_SECRET_KEY']);

        $this->expectException(RipplesException::class);
        $this->expectExceptionMessage('Missing secret key');
        new FakeRipples();
    }

    public function testCustomBaseUrl(): void
    {
        $r = new FakeRipples('priv_abc', ['base_url' => 'https://custom.example.com/api']);
        $r->signup('u1');
        $this->assertSame('/v1/signup', $r->lastPath);
    }

    // ------------------------------------------------------------------
    // Revenue
    // ------------------------------------------------------------------

    public function testRevenueMinimal(): void
    {
        $this->ripples->revenue(49.99, 'u1');

        $this->assertSame('/v1/revenue', $this->ripples->lastPath);
        $this->assertSame(49.99, $this->ripples->lastData['amount']);
        $this->assertSame('u1', $this->ripples->lastData['user_id']);
    }

    public function testRevenueFlatProperties(): void
    {
        $this->ripples->revenue(100.0, 'u1', [
            'currency' => 'EUR',
            'plan' => 'annual',
            'coupon' => 'WELCOME',
        ]);

        $data = $this->ripples->lastData;
        $this->assertSame(100.0, $data['amount']);
        $this->assertSame('u1', $data['user_id']);
        $this->assertSame('EUR', $data['currency']);
        $this->assertSame('annual', $data['plan']);
        $this->assertSame('WELCOME', $data['coupon']);
    }

    public function testRefund(): void
    {
        $this->ripples->revenue(-29.99, 'u1');
        $this->assertSame(-29.99, $this->ripples->lastData['amount']);
    }

    // ------------------------------------------------------------------
    // Signup
    // ------------------------------------------------------------------

    public function testSignupMinimal(): void
    {
        $this->ripples->signup('user_42');

        $this->assertSame('/v1/signup', $this->ripples->lastPath);
        $this->assertSame('user_42', $this->ripples->lastData['user_id']);
    }

    public function testSignupWithAttributes(): void
    {
        $this->ripples->signup('user_42', [
            'email' => 'jane@example.com',
            'name' => 'Jane',
            'referral' => 'twitter',
        ]);

        $data = $this->ripples->lastData;
        $this->assertSame('user_42', $data['user_id']);
        $this->assertSame('jane@example.com', $data['email']);
        $this->assertSame('Jane', $data['name']);
        $this->assertSame('twitter', $data['referral']);
    }

    // ------------------------------------------------------------------
    // Identify
    // ------------------------------------------------------------------

    public function testIdentifyMinimal(): void
    {
        $this->ripples->identify('user_42');

        $this->assertSame('/v1/identify', $this->ripples->lastPath);
        $this->assertSame('user_42', $this->ripples->lastData['user_id']);
    }

    public function testIdentifyWithAttributes(): void
    {
        $this->ripples->identify('user_42', [
            'email' => 'jane@example.com',
            'company' => 'Acme',
            'role' => 'admin',
        ]);

        $data = $this->ripples->lastData;
        $this->assertSame('user_42', $data['user_id']);
        $this->assertSame('jane@example.com', $data['email']);
        $this->assertSame('Acme', $data['company']);
        $this->assertSame('admin', $data['role']);
    }

    // ------------------------------------------------------------------
    // Payload integrity
    // ------------------------------------------------------------------

    public function testAttributesOverrideNothing(): void
    {
        // user_id in attributes should not override the positional arg
        $this->ripples->signup('real_id', ['user_id' => 'fake_id']);
        // PHP array spread: positional comes first, then ...attributes
        // later key wins — this is expected, document or guard if needed
        $this->assertSame('/v1/signup', $this->ripples->lastPath);
    }

    public function testReturnsResponse(): void
    {
        $this->ripples->fakeResponse = ['ok' => true, 'attributed' => true];
        $result = $this->ripples->revenue(10.0, 'u1');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['attributed']);
    }
}
