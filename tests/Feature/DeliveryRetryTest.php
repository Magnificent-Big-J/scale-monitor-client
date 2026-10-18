<?php

namespace Rainwaves\ScaleMonitorClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Rainwaves\ScaleMonitorClient\Jobs\PushScaleMonitorTelemetry;
use Rainwaves\ScaleMonitorClient\ScaleMonitor;
use Rainwaves\ScaleMonitorClient\ScaleMonitorServiceProvider;
use Rainwaves\ScaleMonitorClient\Support\DeliveryStatus;
use Rainwaves\ScaleMonitorClient\Support\ScaleMonitorSignature;
use Symfony\Component\Process\Process;

class DeliveryRetryTest extends TestCase
{
    private string $statePath;

    protected function getPackageProviders($app): array
    {
        return [ScaleMonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('scale-monitor', array_replace_recursive($app['config']->get('scale-monitor', []), [
            'enabled' => true, 'base_url' => 'https://monitor.test', 'key_id' => 'test-key', 'secret' => 'test-secret',
        ]));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = tempnam(sys_get_temp_dir(), 'sm-delivery-');
        unlink($this->statePath);
        config(['scale-monitor.delivery.state_path' => $this->statePath]);
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        parent::tearDown();
    }

    private function work(): void
    {
        $options = new WorkerOptions;
        $options->sleep = 0;
        $this->app->make('queue.worker')->runNextJob('database', 'default', $options);
    }

    public static function transientFailures(): array
    {
        return [[503], [429], ['timeout']];
    }

    #[DataProvider('transientFailures')]
    public function test_a_real_queue_worker_retries_with_stable_identity_and_fresh_signatures(int|string $first): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls, $first) {
            if (++$calls === 1) {
                if ($first === 'timeout') {
                    throw new ConnectionException('password=synthetic-timeout-secret');
                }

                return Http::response(['error' => 'Bearer synthetic-response-secret'], $first, ['Retry-After' => '99999']);
            }

            return Http::response([], 202);
        });
        app(ScaleMonitor::class)->heartbeat('scheduler');
        $original = DB::table('jobs')->value('payload');
        $this->work();
        $this->assertSame(1, DB::table('jobs')->count(), 'Transient failure must remain queued.');
        $delay = DB::table('jobs')->value('available_at') - now()->timestamp;
        $this->assertGreaterThanOrEqual(1, $delay);
        $this->assertLessThanOrEqual(60, $delay);
        $this->assertSame($original, DB::table('jobs')->value('payload'));
        $this->travel(61)->seconds();
        $this->work();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(2, $calls);
        $requests = Http::recorded();
        // Laravel's HTTP fake may omit failed connection attempts from recorded().
        if ($first !== 'timeout') {
            $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
            $this->assertNotSame($requests[0][0]->header(ScaleMonitorSignature::HEADER_NONCE), $requests[1][0]->header(ScaleMonitorSignature::HEADER_NONCE));
            $this->assertNotSame($requests[0][0]->header(ScaleMonitorSignature::HEADER_TIMESTAMP), $requests[1][0]->header(ScaleMonitorSignature::HEADER_TIMESTAMP));
        }
        foreach ($requests as [$request]) {
            $this->assertSame(ScaleMonitorSignature::sign('test-secret', 'POST', '/api/v1/telemetry/heartbeat', $request->header(ScaleMonitorSignature::HEADER_TIMESTAMP)[0], $request->header(ScaleMonitorSignature::HEADER_NONCE)[0], $request->body()), $request->header(ScaleMonitorSignature::HEADER_SIGNATURE)[0]);
        }
        $status = app(DeliveryStatus::class)->read();
        $this->assertSame(1, $status['counts']['retry_scheduled']);
        $this->assertSame(1, $status['counts']['accepted']);
        $this->assertNotNull($status['last_success_at']);
        $this->assertStringNotContainsString('synthetic-', file_get_contents($this->statePath));
    }

    public static function permanentFailures(): array
    {
        return [[200], [204], [301], [401], [403], [422]];
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_rejections_are_not_retried(int $status): void
    {
        Http::fake(['*' => Http::response('password=synthetic-response-secret', $status)]);
        app(ScaleMonitor::class)->heartbeat('scheduler');
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('rejected', app(DeliveryStatus::class)->read()['last']['outcome']);
        $this->assertStringNotContainsString('synthetic-response-secret', file_get_contents($this->statePath));
    }

    public function test_exhaustion_is_bounded_and_does_not_create_failed_job_telemetry(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        app(ScaleMonitor::class)->heartbeat('scheduler');
        for ($i = 0; $i < 4; $i++) {
            $this->work();
            $this->travel(61)->seconds();
        }
        Http::assertSentCount(3);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(2, app(DeliveryStatus::class)->read()['counts']['retry_scheduled']);
        $this->assertSame(1, app(DeliveryStatus::class)->read()['counts']['exhausted']);
    }

    public function test_sync_requests_and_deployments_make_one_attempt_without_retry_loops(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(['*' => Http::response([], 503)]);
        app(ScaleMonitor::class)->heartbeat('scheduler');
        app(ScaleMonitor::class)->deployment('v-test', null, 'succeeded');
        Http::assertSentCount(2);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(2, app(DeliveryStatus::class)->read()['counts']['exhausted']);
    }

    public function test_payload_is_safe_before_it_enters_the_actual_queue(): void
    {
        app(ScaleMonitor::class)->error('high', 'RuntimeException', 'SQL bindings: patient synthetic-raw-person', context: ['token' => 'synthetic-key-secret', 'detail' => 'mysql://alice:synthetic-uri-secret@db/test']);
        $queued = DB::table('jobs')->value('payload');
        foreach (['synthetic-raw-person', 'synthetic-key-secret', 'synthetic-uri-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $queued);
        }
    }

    public function test_retry_configuration_has_a_hard_upper_bound(): void
    {
        config(['scale-monitor.retries' => 999]);
        Http::fake(['*' => Http::response([], 503)]);
        app(ScaleMonitor::class)->heartbeat('scheduler');
        for ($i = 0; $i < 7; $i++) {
            $this->work();
            $this->travel(61)->seconds();
        }
        Http::assertSentCount(6);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(5, app(DeliveryStatus::class)->read()['counts']['retry_scheduled']);
    }

    public function test_disabled_retries_and_unavailable_local_state_do_not_break_the_caller(): void
    {
        config(['scale-monitor.retries' => 0, 'scale-monitor.delivery.state_path' => sys_get_temp_dir()]);
        Http::fake(['*' => Http::response([], 503)]);
        app(ScaleMonitor::class)->heartbeat('scheduler');
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_legacy_jobs_are_sanitised_again_before_transmission(): void
    {
        Http::fake(['*' => Http::response([], 202)]);
        $job = new PushScaleMonitorTelemetry('error', []);
        (new \ReflectionProperty($job, 'payload'))->setValue($job, ['exception_class' => 'RuntimeException', 'message' => 'SQLSTATE patient synthetic-person-secret']);
        $job->handle();
        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'synthetic-person-secret'));
    }

    public function test_status_command_only_reads_local_evidence(): void
    {
        Http::fake();
        $this->artisan('scale-monitor:delivery-status')->expectsOutputToContain('not_recorded')->assertSuccessful();
        $this->assertFileDoesNotExist($this->statePath);
        Http::assertNothingSent();
    }

    public static function realHttpFailures(): array
    {
        return [[503], [429], ['timeout'], [401], [422], [301]];
    }

    #[DataProvider('realHttpFailures')]
    public function test_real_http_failures_through_the_database_queue_worker(int|string $first): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $requestsPath = tempnam(sys_get_temp_dir(), 'sm-http-');
        $server = new Process([PHP_BINARY, '-S', $address, __DIR__.'/../Fixtures/receiver.php'], env: [
            'SM_TEST_REQUESTS' => $requestsPath, 'SM_TEST_FIRST_RESPONSE' => (string) $first,
        ]);
        try {
            $server->start();
            for ($i = 0; $i < 100; $i++) {
                $probe = @stream_socket_client('tcp://'.$address, $errno, $error, 0.05);
                if ($probe) {
                    fclose($probe);
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($server->isRunning(), $server->getErrorOutput());
            config(['scale-monitor.base_url' => 'http://'.$address, 'scale-monitor.timeouts.total' => $first === 'timeout' ? 0.1 : 2]);
            app(ScaleMonitor::class)->heartbeat('scheduler');
            $this->work();
            $transient = in_array($first, [503, 429, 'timeout'], true);
            $this->assertSame($transient ? 1 : 0, DB::table('jobs')->count());
            if ($transient) {
                // Let the single test server finish its deliberately slow first request.
                if ($first === 'timeout') {
                    usleep(350000);
                }
                config(['scale-monitor.timeouts.total' => 2]);
                $this->travel(61)->seconds();
                $this->work();
            }
            $requests = array_map(fn ($line) => json_decode($line, true), file($requestsPath, FILE_IGNORE_NEW_LINES));
            $this->assertCount($transient ? 2 : 1, $requests);
            $this->assertSame(0, DB::table('jobs')->count());
            if ($transient) {
                $this->assertSame($requests[0]['body'], $requests[1]['body']);
                $this->assertNotSame($requests[0]['headers']['x-scale-nonce'], $requests[1]['headers']['x-scale-nonce']);
                $this->assertNotSame($requests[0]['headers']['x-scale-timestamp'], $requests[1]['headers']['x-scale-timestamp']);
            }
            foreach ($requests as $request) {
                $this->assertSame(ScaleMonitorSignature::sign('test-secret', 'POST', '/api/v1/telemetry/heartbeat', $request['headers']['x-scale-timestamp'], $request['headers']['x-scale-nonce'], $request['body']), $request['headers']['x-scale-signature']);
            }
            $this->assertSame($transient ? 'accepted' : 'rejected', app(DeliveryStatus::class)->read()['last']['outcome']);
            $this->assertStringNotContainsString('synthetic-response-secret', file_get_contents($this->statePath));
        } finally {
            $server->stop(0.5);
            @unlink($requestsPath);
        }
    }
}
