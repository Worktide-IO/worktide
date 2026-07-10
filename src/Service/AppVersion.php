<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Build/version identity of the running instance, surfaced at GET /v1/version
 * so operators (and the SPAs) can tell exactly which build is live — the thing
 * you want when a fix "should be deployed" but the behaviour says otherwise.
 *
 * Values come from build-time env / files (see Dockerfile + compose.prod.yaml):
 *  - APP_VERSION  release tag, e.g. "v0.1.1" (operator-set build arg)
 *  - APP_COMMIT   git SHA (Coolify's SOURCE_COMMIT build arg)
 *  - BUILD_TIME   file written at image build (`date -u`)
 * All degrade gracefully to "dev"/"unknown"/null when unset (local dev).
 */
final readonly class AppVersion
{
    public function __construct(
        private ?string $version,
        private ?string $commit,
        private string $environment,
        private string $projectDir,
    ) {}

    /**
     * @return array{version: string, commit: string, shortCommit: string, buildTime: ?string, env: string}
     */
    public function toArray(): array
    {
        $commit = ($this->commit ?? '') !== '' ? (string) $this->commit : 'unknown';
        $short = $commit !== 'unknown' ? substr($commit, 0, 7) : 'unknown';
        $version = ($this->version ?? '') !== '' ? (string) $this->version : ($short !== 'unknown' ? $short : 'dev');

        $buildTime = null;
        $file = $this->projectDir . '/BUILD_TIME';
        if (is_file($file)) {
            $buildTime = trim((string) @file_get_contents($file)) ?: null;
        }

        return [
            'version' => $version,
            'commit' => $commit,
            'shortCommit' => $short,
            'buildTime' => $buildTime,
            'env' => $this->environment,
        ];
    }
}
