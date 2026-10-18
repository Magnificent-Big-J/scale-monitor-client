<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rainwaves\ScaleMonitorClient\Support\Redactor;

class SecretTextTest extends TestCase
{
    public static function texts(): array
    {
        return array_map(fn ($text) => [$text], json_decode(file_get_contents(__DIR__.'/../Fixtures/redaction-secrets.json'), true));
    }

    #[DataProvider('texts')]
    public function test_common_secret_text_is_absent_and_scrubbing_is_idempotent(string $text): void
    {
        $safe = Redactor::scrub($text);
        $this->assertStringNotContainsString('synthetic-secret', $safe);
        $this->assertSame($safe, Redactor::scrub($safe));
    }
}
