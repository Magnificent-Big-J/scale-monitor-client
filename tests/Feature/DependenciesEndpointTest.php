<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\DependencyInventory;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;

class DependenciesEndpointTest extends TestCase
{
    private const PATH = '/api/monitoring/v1/dependencies';

    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scale-monitor.key_id', 'test-key-id');
        $app['config']->set('scale-monitor.secret', 'test-secret');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sm-deps-'.Str::random(8);
        mkdir($this->dir);
        file_put_contents($this->dir.'/composer.lock', json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => 'v13.35.0']],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '12.5.23']],
        ]));
        file_put_contents($this->dir.'/package-lock.json', json_encode(['lockfileVersion' => 3, 'packages' => [
            '' => ['name' => 'app'],
            'node_modules/axios' => ['version' => '1.12.2'],
            'node_modules/@vue/shared' => ['version' => '3.5.13', 'dev' => true],
            'node_modules/a/node_modules/axios' => ['version' => '1.12.2', 'dev' => true],
            'node_modules/linked' => ['link' => true],
        ]]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function signedGet(?string $secret = 'test-secret'): TestResponse
    {
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $header = fn (string $name) => 'HTTP_'.str_replace('-', '_', strtoupper($name));

        return $this->call('GET', self::PATH, [], [], [], [
            $header(ScaleMonitorSignature::HEADER_CLIENT) => 'test-key-id',
            $header(ScaleMonitorSignature::HEADER_TIMESTAMP) => $timestamp,
            $header(ScaleMonitorSignature::HEADER_NONCE) => $nonce,
            $header(ScaleMonitorSignature::HEADER_SIGNATURE) => ScaleMonitorSignature::sign($secret, 'GET', self::PATH, $timestamp, $nonce, ''),
        ]);
    }

    public function test_the_inventory_lists_locked_versions_with_dev_flags_and_digests(): void
    {
        $inventory = (new DependencyInventory)->build($this->dir);

        $this->assertSame('1.0', $inventory['schema_version']);
        $this->assertSame([['laravel/framework', 'v13.35.0', false], ['phpunit/phpunit', '12.5.23', true]], $inventory['ecosystems']['composer']['packages']);
        $this->assertSame(hash_file('sha256', $this->dir.'/composer.lock'), $inventory['ecosystems']['composer']['lock_sha256']);
        $this->assertEqualsCanonicalizing([['axios', '1.12.2', false], ['@vue/shared', '3.5.13', true]], $inventory['ecosystems']['npm']['packages']);
    }

    public function test_a_missing_or_broken_lock_file_is_reported_as_absent_not_empty(): void
    {
        unlink($this->dir.'/package-lock.json');
        file_put_contents($this->dir.'/composer.lock', '{not json');

        $inventory = (new DependencyInventory)->build($this->dir);

        $this->assertNull($inventory['ecosystems']['composer']);
        $this->assertNull($inventory['ecosystems']['npm']);
    }

    public function test_the_route_is_registered_automatically_and_requires_a_valid_signature(): void
    {
        $this->app->bind(DependencyInventory::class, fn () => new class($this->dir) extends DependencyInventory
        {
            public function __construct(private readonly string $dir) {}

            public function build(?string $basePath = null): array
            {
                return parent::build($this->dir);
            }
        });

        $this->signedGet()->assertOk()->assertJsonPath('ecosystems.composer.packages.0', ['laravel/framework', 'v13.35.0', false]);
        $this->signedGet('wrong-secret')->assertStatus(401);
        $this->getJson(self::PATH)->assertStatus(401);
    }
}
