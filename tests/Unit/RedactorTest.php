<?php

declare(strict_types=1);

namespace Cbagdawala\InnLogger\Tests\Unit;

use Cbagdawala\InnLogger\Redactor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedactorTest extends TestCase
{
    public function test_redacts_default_keys_recursively_and_case_insensitively(): void
    {
        $redactor = new Redactor();

        $result = $redactor->redact([
            'PASSWORD' => 'hunter2',
            'user' => [
                'email' => 'a@example.com',
                'Password_Confirmation' => 'hunter2',
                'profile' => ['card' => ['Card-Number' => '4111111111111111', 'CVV' => '123']],
            ],
            'headers' => ['Authorization' => 'Bearer abc', 'Cookie' => 'laravel_session=x', 'X-Other' => 'ok'],
            'Access_Token' => 'a',
            'refresh_token' => 'b',
            'api_secret' => 'c',
            'Secret' => 'd',
            'token' => ['nested' => 'whole value redacted'],
            'list' => [['password' => 'p1'], ['password' => 'p2']],
        ]);

        $this->assertSame(Redactor::REDACTED, $result['PASSWORD']);
        $this->assertSame('a@example.com', $result['user']['email']);
        $this->assertSame(Redactor::REDACTED, $result['user']['Password_Confirmation']);
        $this->assertSame(Redactor::REDACTED, $result['user']['profile']['card']['Card-Number']);
        $this->assertSame(Redactor::REDACTED, $result['user']['profile']['card']['CVV']);
        $this->assertSame(Redactor::REDACTED, $result['headers']['Authorization']);
        $this->assertSame(Redactor::REDACTED, $result['headers']['Cookie']);
        $this->assertSame('ok', $result['headers']['X-Other']);
        $this->assertSame(Redactor::REDACTED, $result['Access_Token']);
        $this->assertSame(Redactor::REDACTED, $result['refresh_token']);
        $this->assertSame(Redactor::REDACTED, $result['api_secret']);
        $this->assertSame(Redactor::REDACTED, $result['Secret']);
        $this->assertSame(Redactor::REDACTED, $result['token']);
        $this->assertSame(Redactor::REDACTED, $result['list'][0]['password']);
        $this->assertSame(Redactor::REDACTED, $result['list'][1]['password']);
    }

    public function test_custom_keys_are_added_to_the_defaults(): void
    {
        $redactor = new Redactor(['national_id', 'X-Tenant-Key']);

        $result = $redactor->redact([
            'National_ID' => '123',
            'x_tenant_key' => 'k',
            'password' => 'still redacted',
            'name' => 'kept',
        ]);

        $this->assertSame(Redactor::REDACTED, $result['National_ID']);
        $this->assertSame(Redactor::REDACTED, $result['x_tenant_key']);
        $this->assertSame(Redactor::REDACTED, $result['password']);
        $this->assertSame('kept', $result['name']);
    }

    public function test_masks_credentials_inside_string_values(): void
    {
        $redactor = new Redactor([], ['/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN]'], ['ils_supersecretvalue1234']);

        $result = $redactor->redact([
            'note' => 'called with Bearer eyJhbGciOi.abc-def_ghi and ssn 123-45-6789',
            'leak' => 'secret is ils_supersecretvalue1234',
            'other' => 'ils_AbCdEf0123456789',
        ]);

        $this->assertSame('called with Bearer [REDACTED] and ssn [SSN]', $result['note']);
        $this->assertStringNotContainsString('supersecret', $result['leak']);
        $this->assertSame(Redactor::REDACTED, $result['other']);
    }

    public function test_redacts_sensitive_query_parameters(): void
    {
        $redactor = new Redactor(['signature']);

        $this->assertSame(
            '/reset?token=[REDACTED]&page=2&user[password]=[REDACTED]&signature=[REDACTED]#top',
            $redactor->redactUrl('/reset?token=abc&page=2&user[password]=pw&signature=sig#top'),
        );
        $this->assertSame('/plain/path', $redactor->redactUrl('/plain/path'));
    }

    public function test_objects_are_made_json_safe(): void
    {
        $redactor = new Redactor();
        $object = new \stdClass();
        $object->password = 'x';
        $object->name = 'n';
        $resource = fopen('php://memory', 'r');

        $result = $redactor->redact([
            'std' => $object,
            'date' => new \DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            'exception' => new RuntimeException('boom'),
            'unknown' => new class () {
            },
            'resource' => $resource,
            'nan' => NAN,
        ]);
        fclose($resource);

        $this->assertSame(['password' => Redactor::REDACTED, 'name' => 'n'], $result['std']);
        $this->assertSame('2026-09-24T10:00:00+00:00', $result['date']);
        $this->assertSame(RuntimeException::class, $result['exception']['class']);
        $this->assertStringStartsWith('[object ', $result['unknown']);
        $this->assertSame('[resource]', $result['resource']);
        $this->assertSame('NAN', $result['nan']);
    }

    public function test_depth_is_bounded(): void
    {
        $deep = ['v' => 'bottom'];
        for ($i = 0; $i < 20; $i++) {
            $deep = ['n' => $deep];
        }

        $encoded = json_encode((new Redactor())->redact($deep));

        $this->assertIsString($encoded);
        $this->assertStringContainsString('[max depth]', $encoded);
    }
}
