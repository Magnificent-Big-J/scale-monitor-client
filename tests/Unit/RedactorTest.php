<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rainwaves\ScaleMonitorClient\Support\Redactor;

class RedactorTest extends TestCase
{
    public function test_a_top_level_sensitive_key_is_redacted(): void
    {
        $result = Redactor::redact(['password' => 'hunter2', 'name' => 'Jane'], ['password']);

        $this->assertSame('[redacted]', $result['password']);
        $this->assertSame('[redacted]', $result['name']);
    }

    public function test_a_nested_sensitive_key_is_redacted(): void
    {
        $result = Redactor::redact(['user' => ['token' => 'abc123', 'email' => 'a@example.com']], ['token']);

        $this->assertSame('[redacted]', $result['user']['token']);
        $this->assertSame('[redacted]', $result['user']['email']);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $result = Redactor::redact(['Password' => 'hunter2'], ['password']);

        $this->assertSame('[redacted]', $result['Password']);
    }

    public function test_a_deeply_nested_key_is_redacted(): void
    {
        $result = Redactor::redact(['a' => ['b' => ['c' => ['secret' => 'x']]]], ['secret']);

        $this->assertSame('[redacted]', $result['a']['b']['c']['secret']);
    }

    public function test_a_safe_payload_is_returned_unchanged(): void
    {
        $data = ['order_id' => 123, 'status' => 'paid'];

        $this->assertSame($data, Redactor::redact($data, ['password', 'token']));
    }

    public function test_credential_aliases_are_masked_before_queueing(): void
    {
        foreach (['passwd', 'pwd', 'credit_card', 'client_secret', 'private_key', 'secret_access_key', 'aws_secret_access_key', 'api-key', 'access-token'] as $key) {
            $result = Redactor::redact(['context' => [strtoupper($key) => 'synthetic-secret']], []);
            $this->assertStringNotContainsString('synthetic-secret', json_encode($result));
        }
    }
}
