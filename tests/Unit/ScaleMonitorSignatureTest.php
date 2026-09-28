<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;

class ScaleMonitorSignatureTest extends TestCase
{
    public function test_a_signature_matches_itself(): void
    {
        $signature = ScaleMonitorSignature::sign('secret', 'POST', '/api/v1/telemetry/heartbeat', '1700000000', 'nonce-1', '{"key":"scheduler"}');

        $this->assertTrue(ScaleMonitorSignature::matches('secret', $signature, 'POST', '/api/v1/telemetry/heartbeat', '1700000000', 'nonce-1', '{"key":"scheduler"}'));
    }

    public function test_a_different_secret_does_not_match(): void
    {
        $signature = ScaleMonitorSignature::sign('secret', 'GET', '/path', '1700000000', 'nonce-1', '');

        $this->assertFalse(ScaleMonitorSignature::matches('wrong-secret', $signature, 'GET', '/path', '1700000000', 'nonce-1', ''));
    }

    public function test_a_tampered_body_does_not_match(): void
    {
        $signature = ScaleMonitorSignature::sign('secret', 'POST', '/path', '1700000000', 'nonce-1', '{"a":1}');

        $this->assertFalse(ScaleMonitorSignature::matches('secret', $signature, 'POST', '/path', '1700000000', 'nonce-1', '{"a":2}'));
    }

    public function test_a_tampered_method_does_not_match(): void
    {
        $signature = ScaleMonitorSignature::sign('secret', 'GET', '/path', '1700000000', 'nonce-1', '');

        $this->assertFalse(ScaleMonitorSignature::matches('secret', $signature, 'POST', '/path', '1700000000', 'nonce-1', ''));
    }

    public function test_the_signature_is_prefixed_with_the_algorithm_version(): void
    {
        $signature = ScaleMonitorSignature::sign('secret', 'GET', '/path', '1700000000', 'nonce-1', '');

        $this->assertStringStartsWith('v1=', $signature);
    }

    public function test_method_case_does_not_matter(): void
    {
        $signature = ScaleMonitorSignature::sign('secret', 'post', '/path', '1700000000', 'nonce-1', '');

        $this->assertTrue(ScaleMonitorSignature::matches('secret', $signature, 'POST', '/path', '1700000000', 'nonce-1', ''));
    }
}
