<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Mockery;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\HealthChecks\StorageHealthCheck;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\HealthCheckRegistry;

class StorageHealthCheckTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    public function test_real_local_roundtrip_uses_reserved_unique_object_and_cleans_up(): void
    {
        $root = sys_get_temp_dir().'/scale-monitor-storage-test-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config(['filesystems.disks.probe' => ['driver' => 'local', 'root' => $root]]);
        try {
            $check = new StorageHealthCheck('probe', 'local');
            $result = $check->run();
            $this->assertSame('healthy', $result->status);
            $this->assertSame('storage_local_probe', $result->key);
            $this->assertGreaterThanOrEqual(0, $result->latencyMs);
            $this->assertSame([], Storage::disk('probe')->allFiles());
        } finally {
            @rmdir($root.'/.scale-monitor/probes');
            @rmdir($root.'/.scale-monitor');
            rmdir($root);
        }
    }

    public function test_write_read_and_cleanup_failures_remain_critical_and_recover(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3', 'bucket' => 'test-only', 'http' => ['timeout' => 90]]]);
        foreach (['write', 'read', 'exception', 'cleanup', 'cleanup_exception', 'healthy'] as $mode) {
            $disk = Mockery::mock();
            $payload = null;
            $path = null;
            $disk->shouldReceive('put')->once()->andReturnUsing(function ($object, $content) use ($mode, &$payload, &$path) {
                $this->assertStringStartsWith('.scale-monitor/probes/', $object);
                $path = $object;
                $payload = $content;
                if ($mode === 'exception') throw new \RuntimeException('credentials must never appear');
                return $mode !== 'write';
            });
            if (! in_array($mode, ['write', 'exception'])) {
                $disk->shouldReceive('get')->once()->andReturnUsing(function () use ($mode, &$payload) {
                    return $mode === 'read' ? 'wrong content' : $payload;
                });
            }
            $disk->shouldReceive('delete')->once()->andReturnUsing(function ($object) use ($mode, &$path) {
                $this->assertSame($path, $object);
                if ($mode === 'cleanup_exception') throw new \RuntimeException('private endpoint');
                return $mode !== 'cleanup';
            });
            Storage::shouldReceive('build')->once()->withArgs(function ($config) {
                return $config['http']['timeout'] === 3 && $config['http']['connect_timeout'] === 2 && $config['retries'] === 0 && $config['throw'] === true;
            })->andReturn($disk);
            $result = (new StorageHealthCheck('s3', 'supabase'))->run();
            $this->assertSame($mode === 'healthy' ? 'healthy' : 'critical', $result->status);
            $this->assertSame('storage_supabase_s3', $result->key);
            $this->assertStringNotContainsString('credentials', $result->summary);
            $this->assertStringNotContainsString('endpoint', $result->summary);
        }
    }

    public function test_missing_or_unsupported_disks_stay_unmeasured_without_probe(): void
    {
        Storage::shouldReceive('build')->never();
        $this->assertSame('skipped', (new StorageHealthCheck('absent', 'amazon_s3'))->run()->status);
        config(['filesystems.disks.remote' => ['driver' => 'custom']]);
        $this->assertSame('skipped', (new StorageHealthCheck('remote', 'unknown'))->run()->status);
        config(['filesystems.disks.readonly' => ['driver' => 'local', 'read-only' => true]]);
        $this->assertSame('skipped', (new StorageHealthCheck('readonly', 'local'))->run()->status);
        config(['filesystems.disks.empty' => ['driver' => 's3']]);
        $this->assertSame('skipped', (new StorageHealthCheck('empty', 'amazon_s3'))->run()->status);
    }

    public function test_auto_provider_uses_verified_endpoint_domains_and_never_guesses_from_aws_variable_names(): void
    {
        foreach ([
            'https://project.storage.supabase.co/storage/v1/s3' => 'supabase',
            'https://nyc3.digitaloceanspaces.com' => 'digitalocean_spaces',
            'https://s3.eu-west-1.amazonaws.com' => 'amazon_s3',
            'https://example.supabase.co.attacker.test' => 's3_compatible',
        ] as $endpoint => $provider) {
            config(['filesystems.disks.s3' => ['driver' => 's3', 'endpoint' => $endpoint]]);
            $this->assertSame('storage_'.$provider.'_s3', (new StorageHealthCheck('s3', 'auto'))->key());
        }
    }

    public function test_auto_provider_resolves_default_aws_endpoints_without_requests_or_credentials(): void
    {
        foreach (['eu-west-1', 'us-east-1', 'cn-north-1'] as $region) {
            config(['filesystems.disks.s3' => $this->s3Config($region) + [
                'endpoint' => null,
                'ignore_configured_endpoint_urls' => true,
            ]]);
            Storage::forgetDisk('s3');

            $this->assertSame('storage_amazon_s3_s3', (new StorageHealthCheck('s3', 'auto'))->key());
        }
    }

    public function test_auto_provider_honours_sdk_level_custom_endpoint_override(): void
    {
        $previous = getenv('AWS_ENDPOINT_URL_S3');
        try {
            foreach ([
                'https://project.storage.supabase.co/storage/v1/s3' => 'supabase',
                'https://nyc3.digitaloceanspaces.com' => 'digitalocean_spaces',
                'https://storage.example.test' => 's3_compatible',
                'https://s3.amazonaws.com.attacker.test' => 's3_compatible',
            ] as $endpoint => $provider) {
                putenv('AWS_ENDPOINT_URL_S3='.$endpoint);
                config(['filesystems.disks.s3' => $this->s3Config('eu-west-1') + [
                    'url' => 'https://public.s3.eu-west-1.amazonaws.com',
                    'ignore_configured_endpoint_urls' => false,
                ]]);
                Storage::forgetDisk('s3');

                $this->assertSame('storage_'.$provider.'_s3', (new StorageHealthCheck('s3', 'auto'))->key());
            }
        } finally {
            putenv($previous === false ? 'AWS_ENDPOINT_URL_S3' : 'AWS_ENDPOINT_URL_S3='.$previous);
            Storage::forgetDisk('s3');
        }
    }

    public function test_auto_provider_resolution_failure_remains_unknown_and_does_not_mask_probe_failure(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3', 'bucket' => 'test-only']]);
        Storage::shouldReceive('disk')->with('s3')->andThrow(new \RuntimeException('private endpoint and credentials'));
        Storage::shouldReceive('build')->once()->andThrow(new \RuntimeException('private endpoint and credentials'));

        $check = new StorageHealthCheck('s3', 'auto');
        $this->assertSame('storage_unknown_s3', $check->key());
        $result = $check->run();
        $this->assertSame('storage_unknown_s3', $result->key);
        $this->assertSame('critical', $result->status);
        $this->assertSame('Storage write/read/delete probe failed.', $result->summary);
    }

    public function test_auto_provider_does_not_infer_from_public_url_or_unsupported_driver(): void
    {
        config(['filesystems.disks.s3' => [
            'driver' => 'custom',
            'endpoint' => 'https://s3.eu-west-1.amazonaws.com',
            'url' => 'https://public.s3.eu-west-1.amazonaws.com',
        ]]);
        Storage::shouldReceive('disk')->never();
        $this->assertSame('storage_unknown_s3', (new StorageHealthCheck('s3', 'auto'))->key());
    }

    private function s3Config(string $region): array
    {
        return [
            'driver' => 's3',
            'bucket' => 'test-only',
            'region' => $region,
            'credentials' => static function (): never {
                throw new \RuntimeException('Provider identification must not resolve credentials.');
            },
            'http_handler' => static function (): never {
                throw new \RuntimeException('Provider identification must not make a request.');
            },
        ];
    }

    public function test_named_default_replaces_generic_probe_and_secondary_disks_are_registered(): void
    {
        config(['scale-monitor.storage.disks' => [config('filesystems.default') => 'local', 'absent' => 'digitalocean_spaces']]);
        $results = $this->app->make(HealthCheckRegistry::class)->runAll();
        $keys = array_map(fn ($result) => $result->key, $results);
        $this->assertNotContains('storage', $keys);
        $this->assertContains('storage_local_'.config('filesystems.default'), $keys);
        $this->assertContains('storage_digitalocean_spaces_absent', $keys);
    }
}
