<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Builds the Flysystem filesystem used by {@see \App\Service\FileStorage}, chosen
 * at runtime from env so callers never change.
 *
 *   FILE_STORAGE_ADAPTER=local  → LocalFilesystemAdapter (var/uploads) — default, dev
 *   FILE_STORAGE_ADAPTER=s3     → AwsS3V3Adapter (S3 / MinIO / UpCloud)
 *
 * At 10GB+ mailbox scale the local disk is not an option — email bodies and
 * attachments offloaded by EmailImapAdapter must live in object storage.
 */
final class StorageAdapterFactory
{
    public function __construct(
        private readonly string $driver,
        private readonly string $localPath,
        private readonly string $s3Key,
        private readonly string $s3Secret,
        private readonly string $s3Region,
        private readonly string $s3Bucket,
        private readonly string $s3Endpoint,
        private readonly bool $s3PathStyle,
        private readonly string $s3Prefix,
    ) {}

    public function create(): FilesystemOperator
    {
        if (strtolower(trim($this->driver)) === 's3') {
            return new Filesystem($this->createS3Adapter(), ['directory_visibility' => 'private']);
        }

        return new Filesystem(new LocalFilesystemAdapter($this->localPath));
    }

    private function createS3Adapter(): AwsS3V3Adapter
    {
        $config = [
            'region' => $this->s3Region !== '' ? $this->s3Region : 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => $this->s3Key, 'secret' => $this->s3Secret],
        ];

        // Non-AWS S3 (MinIO, UpCloud, Cloudflare R2) need a custom endpoint and
        // usually path-style addressing.
        if ($this->s3Endpoint !== '') {
            $config['endpoint'] = $this->s3Endpoint;
            $config['use_path_style_endpoint'] = $this->s3PathStyle;
        }

        return new AwsS3V3Adapter(new S3Client($config), $this->s3Bucket, $this->s3Prefix);
    }
}
