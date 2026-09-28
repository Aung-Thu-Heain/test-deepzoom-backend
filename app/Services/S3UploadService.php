<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class S3UploadService
{
    /** Demo-only S3 helper. Never uploads through Laravel for the initial PDF. */

    public function presignedUploadUrl(string $key, string $contentType = 'application/pdf'): string
    {
        try {
            // Native Laravel helper for S3 presigned PUT URLs.
            return Storage::disk('s3')->temporaryUploadUrl($key, now()->addMinutes(15), [
                'ContentType' => $contentType,
            ]);
        } catch (\Throwable $e) {
            // Fallback using the AWS SDK directly.
            Log::info('temporaryUploadUrl() fell back to the AWS SDK: '.$e->getMessage());

            $client = Storage::disk('s3')->getClient();

            $command = $client->getCommand('PutObject', [
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key' => $key,
                'ContentType' => $contentType,
            ]);

            return (string) $client->createPresignedRequest($command, now()->addMinutes(15))->getUri();
        }
    }

    public function download(string $key, string $localPath): void
    {
        $stream = Storage::disk('s3')->readStream($key);

        if ($stream === false) {
            throw new RuntimeException("Could not open stream for S3 key: {$key}");
        }

        $fh = fopen($localPath, 'wb');

        if ($fh === false) {
            fclose($stream);
            throw new RuntimeException("Could not open local file for writing: {$localPath}");
        }

        stream_copy_to_stream($stream, $fh);

        fclose($fh);
        fclose($stream);
    }

    public function uploadFile(string $localPath, string $key): void
    {
        $contents = file_get_contents($localPath);

        if ($contents === false) {
            throw new RuntimeException("Could not read local file: {$localPath}");
        }

        try {
            // Demo-only: make generated tiles publicly readable over HTTP so
            // OpenSeadragon can fetch the .dzi and tile images without auth.
            Storage::disk('s3')->put($key, $contents, ['ACL' => 'public-read']);
        } catch (\Throwable $e) {
            // Some buckets disable public ACLs; fall back to private so the
            // upload itself still succeeds (bucket policy must allow public
            // reads in that case - see README -> S3 setup).
            Log::warning("Public ACL failed for {$key}, retrying private: ".$e->getMessage());
            Storage::disk('s3')->put($key, $contents);
        }
    }

    public function publicUrl(string $key): string
    {
        return Storage::disk('s3')->url($key);
    }
}