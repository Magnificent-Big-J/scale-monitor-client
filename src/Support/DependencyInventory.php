<?php

namespace Rainwaves\ScaleMonitorClient\Support;

use Throwable;

/**
 * Reads the deployed application's own composer.lock and package-lock.json
 * and returns the locked package versions, so Scale Monitor can check them
 * against published security advisories. Development packages are kept and
 * flagged. Nothing here contacts the network or reveals file contents beyond
 * package names, versions and a SHA-256 of each lock file.
 */
class DependencyInventory
{
    /** @return array{schema_version: string, release: string|null, generated_at: string, ecosystems: array<string, array{lock_sha256: string, packages: list<array{0: string, 1: string, 2: bool}>}|null>} */
    public function build(?string $basePath = null): array
    {
        $basePath ??= base_path();

        return [
            'schema_version' => '1.0',
            'release' => $this->release($basePath),
            'generated_at' => now()->toIso8601String(),
            'ecosystems' => [
                'composer' => $this->read($basePath.'/composer.lock', fn (array $lock) => $this->composer($lock)),
                'npm' => $this->read($basePath.'/package-lock.json', fn (array $lock) => $this->npm($lock)),
            ],
        ];
    }

    /** @param  callable(array<string, mixed>): list<array{0: string, 1: string, 2: bool}>  $parse */
    private function read(string $path, callable $parse): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $contents = (string) file_get_contents($path);
            $lock = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return is_array($lock) ? ['lock_sha256' => hash('sha256', $contents), 'packages' => $parse($lock)] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{0: string, 1: string, 2: bool}> */
    private function composer(array $lock): array
    {
        $packages = [];
        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            foreach ((array) ($lock[$section] ?? []) as $package) {
                if (is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                    $packages[] = [$package['name'], $package['version'], $dev];
                }
            }
        }

        return $this->unique($packages);
    }

    /** @return list<array{0: string, 1: string, 2: bool}> */
    private function npm(array $lock): array
    {
        $packages = [];
        if (is_array($lock['packages'] ?? null)) {
            foreach ($lock['packages'] as $path => $package) {
                if ($path === '' || ! is_array($package) || ($package['link'] ?? false) || ! is_string($package['version'] ?? null)) {
                    continue;
                }
                $position = strrpos((string) $path, 'node_modules/');
                $name = is_string($package['name'] ?? null) ? $package['name'] : ($position === false ? '' : substr((string) $path, $position + 13));
                if ($name !== '') {
                    $packages[] = [$name, $package['version'], (bool) ($package['dev'] ?? false)];
                }
            }
        } else {
            $this->npmV1((array) ($lock['dependencies'] ?? []), $packages);
        }

        return $this->unique($packages);
    }

    /** @param  list<array{0: string, 1: string, 2: bool}>  $packages */
    private function npmV1(array $dependencies, array &$packages): void
    {
        foreach ($dependencies as $name => $package) {
            if (is_array($package) && is_string($package['version'] ?? null) && ! str_contains($package['version'], ':')) {
                $packages[] = [(string) $name, $package['version'], (bool) ($package['dev'] ?? false)];
                $this->npmV1((array) ($package['dependencies'] ?? []), $packages);
            }
        }
    }

    /**
     * One entry per name and version; a version used at runtime anywhere counts as runtime.
     *
     * @param  list<array{0: string, 1: string, 2: bool}>  $packages
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function unique(array $packages): array
    {
        $unique = [];
        foreach ($packages as [$name, $version, $dev]) {
            $key = $name."\0".$version;
            $unique[$key] = [$name, $version, isset($unique[$key]) ? ($unique[$key][2] && $dev) : $dev];
        }

        return array_values($unique);
    }

    private function release(string $basePath): ?string
    {
        $release = basename(realpath($basePath) ?: $basePath);

        return preg_match('/^[a-f0-9]{40}$/i', $release) ? strtolower($release) : (env('APP_RELEASE') ?: null);
    }
}
