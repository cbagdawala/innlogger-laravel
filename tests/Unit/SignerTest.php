<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function test_signature_matches_an_independent_hmac(): void
    {
        $body = '{"event_id":"x","message":"héllo"}';
        $headers = (new Signer('ilv_key', 'ils_secret'))->headers($body, 'req-1', 1790000000, 'abc123');

        $expected = hash_hmac('sha256', "1790000000\nabc123\n".$body, 'ils_secret');

        $this->assertSame($expected, $headers['X-InnLogger-Signature']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $headers['X-InnLogger-Signature']);
        $this->assertSame('ilv_key', $headers['X-InnLogger-Key']);
        $this->assertSame('1790000000', $headers['X-InnLogger-Timestamp']);
        $this->assertSame('abc123', $headers['X-InnLogger-Nonce']);
        $this->assertSame('req-1', $headers['X-InnLogger-Request-Id']);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertNotContains('ils_secret', $headers);
    }

    public function test_nonces_are_random(): void
    {
        $nonces = array_map(static fn (): string => Signer::nonce(), range(1, 50));

        $this->assertCount(50, array_unique($nonces));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonces[0]);
    }
}
