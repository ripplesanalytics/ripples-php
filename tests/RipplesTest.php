<?php

namespace Ripples\Tests;

use PHPUnit\Framework\TestCase;
use Ripples\Ripples;
use Ripples\RipplesException;

/**
 * A testable Ripples client that captures batch requests instead of sending them.
 */
class FakeRipples extends Ripples
{
    /** @var list<array{path: string, data: array}> */
    public array $batches = [];
    public bool $shouldThrow = false;
    public ?\Throwable $throwable = null;

    protected function post(string $path, array $data): void
    {
        if ($this->shouldThrow) {
            throw $this->throwable ?? new RipplesException('Simulated failure');
        }
        $this->batches[] = compact('path', 'data');
    }
}

class RipplesTest extends TestCase
{
    private FakeRipples $ripples;

    protected function setUp(): void
    {
        $this->ripples = new FakeRipples('priv_test_key');
    }

    /** Events from the most recent flush. */
    private function lastEvents(): array
    {
        $last = end($this->ripples->batches);
        return $last ? $last['data']['events'] : [];
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
        $r->flush();
        $this->assertSame('/v1/ingest/batch', $r->batches[0]['path']);
    }

    // ------------------------------------------------------------------
    // Revenue
    // ------------------------------------------------------------------

    public function testRevenueMinimal(): void
    {
        $this->ripples->revenue(49.99, 'u1');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('revenue', $event['type']);
        $this->assertSame(49.99, $event['amount']);
        $this->assertSame('u1', $event['user_id']);
    }

    public function testRevenueFlatProperties(): void
    {
        $this->ripples->revenue(100.0, 'u1', [
            'currency' => 'EUR',
            'plan'     => 'annual',
            'coupon'   => 'WELCOME',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame(100.0, $event['amount']);
        $this->assertSame('u1', $event['user_id']);
        $this->assertSame('EUR', $event['currency']);
        $this->assertSame('annual', $event['plan']);
        $this->assertSame('WELCOME', $event['coupon']);
    }

    public function testRefund(): void
    {
        $this->ripples->revenue(-29.99, 'u1');
        $this->ripples->flush();
        $this->assertSame(-29.99, $this->lastEvents()[0]['amount']);
    }

    // ------------------------------------------------------------------
    // Signup
    // ------------------------------------------------------------------

    public function testSignupMinimal(): void
    {
        $this->ripples->signup('user_42');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('signup', $event['type']);
        $this->assertSame('user_42', $event['user_id']);
    }

    public function testSignupWithAttributes(): void
    {
        $this->ripples->signup('user_42', [
            'email'    => 'jane@example.com',
            'name'     => 'Jane',
            'referral' => 'twitter',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('user_42', $event['user_id']);
        $this->assertSame('jane@example.com', $event['email']);
        $this->assertSame('Jane', $event['name']);
        $this->assertSame('twitter', $event['referral']);
    }

    // ------------------------------------------------------------------
    // Identify
    // ------------------------------------------------------------------

    public function testIdentifyMinimal(): void
    {
        $this->ripples->identify('user_42');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('identify', $event['type']);
        $this->assertSame('user_42', $event['user_id']);
    }

    public function testIdentifyWithAttributes(): void
    {
        $this->ripples->identify('user_42', [
            'email'   => 'jane@example.com',
            'company' => 'Acme',
            'role'    => 'admin',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('user_42', $event['user_id']);
        $this->assertSame('jane@example.com', $event['email']);
        $this->assertSame('Acme', $event['company']);
        $this->assertSame('admin', $event['role']);
    }

    // ------------------------------------------------------------------
    // Track (product usage)
    // ------------------------------------------------------------------

    public function testTrackMinimal(): void
    {
        $this->ripples->track('created a budget', 'user_42');
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('track', $event['type']);
        $this->assertSame('created a budget', $event['name']);
        $this->assertSame('user_42', $event['user_id']);
    }

    public function testTrackWithArea(): void
    {
        $this->ripples->track('created a budget', 'user_42', [
            'area' => 'budgets',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('track', $event['type']);
        $this->assertSame('created a budget', $event['name']);
        $this->assertSame('budgets', $event['area']);
    }

    public function testTrackWithActivatedFlag(): void
    {
        $this->ripples->track('shared a list', 'user_42', [
            'area'      => 'sharing',
            'activated' => true,
            'via'       => 'link',
        ]);
        $this->ripples->flush();

        $event = $this->lastEvents()[0];
        $this->assertSame('track', $event['type']);
        $this->assertSame('shared a list', $event['name']);
        $this->assertSame('sharing', $event['area']);
        $this->assertTrue($event['activated']);
        $this->assertSame('link', $event['via']);
    }

    // ------------------------------------------------------------------
    // Batching
    // ------------------------------------------------------------------

    public function testMultipleEventsAreCollectedInOneBatch(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->identify('u1', ['email' => 'a@b.com']);
        $this->ripples->revenue(9.99, 'u1');
        $this->ripples->flush();

        $this->assertCount(1, $this->ripples->batches); // one HTTP call
        $events = $this->lastEvents();
        $this->assertCount(3, $events);
        $this->assertSame('signup', $events[0]['type']);
        $this->assertSame('identify', $events[1]['type']);
        $this->assertSame('revenue', $events[2]['type']);
    }

    public function testFlushSendsToBatchEndpoint(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->flush();
        $this->assertSame('/v1/ingest/batch', $this->ripples->batches[0]['path']);
    }

    public function testFlushClearsTheQueue(): void
    {
        $this->ripples->signup('u1');
        $this->ripples->flush();
        $this->ripples->flush(); // second flush — queue is empty, no HTTP call

        $this->assertCount(1, $this->ripples->batches);
    }

    public function testFlushOnEmptyQueueDoesNothing(): void
    {
        $this->ripples->flush();
        $this->assertCount(0, $this->ripples->batches);
    }

    public function testMaxQueueSizeTriggersAutoFlush(): void
    {
        $r = new FakeRipples('priv_test_key', ['max_queue_size' => 3]);

        $r->signup('u1');
        $r->signup('u2');
        $this->assertCount(0, $r->batches); // not yet

        $r->signup('u3'); // hits limit — auto-flush
        $this->assertCount(1, $r->batches);
        $this->assertCount(3, $r->batches[0]['data']['events']);
    }

    // ------------------------------------------------------------------
    // Resilience
    // ------------------------------------------------------------------

    public function testNetworkErrorDoesNotThrow(): void
    {
        $this->ripples->shouldThrow = true;

        $this->ripples->signup('u1');
        $this->ripples->identify('u1');
        $this->ripples->revenue(9.99, 'u1');
        $this->ripples->flush();

        $this->assertTrue(true); // reached without exception
    }

    public function testOnErrorCallbackIsInvokedWithException(): void
    {
        $caught = null;

        $r = new FakeRipples('priv_test_key', [
            'on_error' => function (\Throwable $e) use (&$caught) {
                $caught = $e;
            },
        ]);
        $r->shouldThrow = true;
        $r->throwable   = new RipplesException('Simulated timeout');

        $r->signup('u1');
        $r->flush();

        $this->assertInstanceOf(RipplesException::class, $caught);
        $this->assertSame('Simulated timeout', $caught->getMessage());
    }

    public function testOnErrorIsNotCalledOnSuccess(): void
    {
        $called = false;

        $r = new FakeRipples('priv_test_key', [
            'on_error' => function () use (&$called) { $called = true; },
        ]);
        $r->signup('u1');
        $r->flush();

        $this->assertFalse($called);
    }

    // ------------------------------------------------------------------
    // Options
    // ------------------------------------------------------------------

    public function testCustomTimeoutOptions(): void
    {
        $r = new FakeRipples('priv_test_key', [
            'timeout'         => 5,
            'connect_timeout' => 3,
        ]);
        $r->signup('u1');
        $r->flush();
        $this->assertSame('signup', $r->batches[0]['data']['events'][0]['type']);
    }
}
