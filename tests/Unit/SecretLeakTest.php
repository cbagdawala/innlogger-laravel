<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Client;
use Cbagdawala\InnLogger\Config;
use Cbagdawala\InnLogger\Redactor;
use Cbagdawala\InnLogger\Secret;
use Cbagdawala\InnLogger\Signer;
use Cbagdawala\InnLogger\Tests\Support\FakeTransport;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretLeakTest extends TestCase
{
    private const SECRET = 'ils_LeakCheckSecret0123456789abcdefABCDEF01234567';

    private static function config(): Config
    {
        return new Config([
            'url' => 'https://logger.example.com',
            'api_key' => 'ilv_LeakCheckKey0123456789abcdefAB',
            'api_secret' => self::SECRET,
        ]);
    }

    /**
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function holders(): iterable
    {
        yield 'Config' => [static fn (): object => self::config()];
        yield 'Client' => [static fn (): object => new Client(self::config(), new FakeTransport())];
        yield 'Signer' => [static fn (): object => new Signer('ilv_key', self::SECRET)];
        yield 'Redactor' => [static fn (): object => new Redactor([], [], [self::SECRET])];
        yield 'Secret' => [static fn (): object => new Secret(self::SECRET)];
    }

    /**
     * @param  \Closure(): object  $factory
     */
    #[DataProvider('holders')]
    public function test_dumps_never_contain_the_secret(\Closure $factory): void
    {
        $object = $factory();

        ob_start();
        var_dump($object);
        $varDump = (string) ob_get_clean();

        $outputs = [
            'print_r' => print_r($object, true),
            'var_export' => var_export($object, true),
            'var_dump' => $varDump,
            'json_encode' => (string) json_encode($object),
            'array cast' => print_r((array) $object, true),
        ];

        foreach ($outputs as $how => $output) {
            $this->assertStringNotContainsString(self::SECRET, $output, $how.' leaked the secret');
            $this->assertStringNotContainsString('LeakCheckSecret', $output, $how.' leaked part of the secret');
        }
    }

    public function test_config_debug_info_masks_the_secret_but_keeps_other_values(): void
    {
        $dump = print_r(self::config(), true);

        $this->assertStringContainsString('[apiSecret] => '.Secret::MASK, $dump);
        $this->assertStringContainsString('https://logger.example.com', $dump);
        $this->assertStringContainsString('ilv_LeakCheckKey', $dump);
        $this->assertStringContainsString('(not set)', print_r(new Config([]), true));
    }

    public function test_serialized_config_never_contains_the_secret(): void
    {
        $config = self::config();
        $serialized = serialize($config);

        $this->assertStringNotContainsString(self::SECRET, $serialized);

        $restored = unserialize($serialized);
        $this->assertInstanceOf(Config::class, $restored);
        $this->assertSame('https://logger.example.com', $restored->url);
        $this->assertFalse($restored->hasApiSecret());
        $this->assertSame('not_configured', $restored->problem());

        // The original is unaffected.
        $this->assertSame(self::SECRET, $config->apiSecret());
    }

    public function test_serialized_signer_and_redactor_never_contain_the_secret(): void
    {
        $this->assertStringNotContainsString(self::SECRET, serialize(new Signer('k', self::SECRET)));
        $this->assertStringNotContainsString(self::SECRET, serialize(new Redactor([], [], [self::SECRET])));
    }

    public function test_client_cannot_be_serialized(): void
    {
        $this->expectException(LogicException::class);

        serialize(new Client(self::config(), new FakeTransport()));
    }

    public function test_secret_survives_clone_and_is_released_independently(): void
    {
        $secret = new Secret(self::SECRET);
        $copy = clone $secret;
        unset($secret);
        gc_collect_cycles();

        $this->assertSame(self::SECRET, $copy->reveal());
    }

    public function test_signing_still_uses_the_real_secret(): void
    {
        $headers = (new Signer('k', self::SECRET))->headers('{}', 'r', 1790000000, 'n');

        $this->assertSame(hash_hmac('sha256', "1790000000\nn\n{}", self::SECRET), $headers['X-InnLogger-Signature']);
    }
}
