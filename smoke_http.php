<?php

/*
 * HTTP smoke test for the demo backend.
 *
 * Boots the full HTTP kernel against in-memory SQLite and a fake S3 service,
 * so it needs no PostgreSQL, Redis, AWS credentials, or PHP extensions beyond
 * pdo_sqlite. Run with:
 *
 *   php smoke_http.php
 */

use App\Jobs\ProcessPlanPdf;
use App\Services\S3UploadService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

// Override the .env database settings before Laravel boots (immutable Dotenv
// never overwrites already-set environment variables).
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('QUEUE_CONNECTION=sync');
putenv('CACHE_STORE=array');

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

// Fake S3 so no AWS calls are ever made.
$app->singleton(S3UploadService::class, function () {
    return new class extends S3UploadService {
        public function presignedUploadUrl(string $key, string $contentType = 'application/pdf'): string
        {
            return "https://fake-bucket.s3.amazonaws.com/{$key}?X-Amz-Signature=fake";
        }

        public function publicUrl(string $key): string
        {
            return "https://fake-bucket.s3.amazonaws.com/{$key}";
        }
    };
});

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Same process, same in-memory connection: migrate then serve requests.
Artisan::call('migrate', ['--force' => true]);

Queue::fake();

function send(string $method, string $uri, array $data = []): array
{
    $request = Request::create($uri, $method, $data);
    $request->headers->set('Accept', 'application/json');

    $response = app(Kernel::class)->handle($request);

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
}

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, array $detail = []): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] {$name}\n";
    } else {
        $fail++;
        echo "[FAIL] {$name}\n";
        if ($detail !== []) {
            echo '       '.json_encode($detail)."\n";
        }
    }
}

// 1. upload-url ----------------------------------------------------------------
[$code, $json] = send('POST', '/api/plans/upload-url', ['filename' => 'floor-plan-v3.pdf']);
check('upload-url returns 200', $code === 200, $json ?? []);
check(
    'upload-url returns uploadUrl + s3Key under demo/uploads/',
    isset($json['uploadUrl'], $json['s3Key'])
        && str_starts_with((string) $json['s3Key'], 'demo/uploads/')
        && str_ends_with((string) $json['s3Key'], '.pdf'),
    $json ?? []
);

[$code, $json] = send('POST', '/api/plans/upload-url', ['filename' => 'notes.txt']);
check('upload-url rejects non-pdf filename (422)', $code === 422, $json ?? []);

[$code, $json] = send('POST', '/api/plans/upload-url', []);
check('upload-url rejects missing filename (422)', $code === 422);

// 2. upload-complete -----------------------------------------------------------
[$code, $json] = send('POST', '/api/plans/upload-complete', [
    'name' => 'North Tower',
    's3Key' => 'demo/uploads/abc-123.pdf',
]);
check('upload-complete returns 201', $code === 201, $json ?? []);
$planId = $json['id'] ?? null;
check('upload-complete creates processing plan', $planId !== null && ($json['status'] ?? null) === 'processing');
$queued = false;
try {
    Queue::assertPushed(ProcessPlanPdf::class, fn ($job) => $job->plan->id === $planId);
    $queued = true;
} catch (\Throwable $e) {
    // not queued - falls through to the check below
}
check('upload-complete queues ProcessPlanPdf for that plan', $queued);

[$code, $json] = send('POST', '/api/plans/upload-complete', [
    'name' => 'Bad',
    's3Key' => 'other/path.pdf',
]);
check('upload-complete rejects non-demo s3Key (422)', $code === 422, $json ?? []);

// 3. show -----------------------------------------------------------------------
[$code, $json] = send('GET', "/api/plans/{$planId}");
check('show returns 200', $code === 200, $json ?? []);
check('show returns shape with pages array', ($json['pageCount'] ?? null) === null && ($json['pages'] ?? null) === []);

[$code, $json] = send('GET', '/api/plans/999999');
check('show returns 404 for missing plan', $code === 404, $json ?? []);

// 4. annotations + issues (append-only versioning + normalized coords) ----------
[$code, $json] = send('GET', '/api/plan-pages/999999/annotations');
check('annotations index 404 for missing page', $code === 404);

[$code, $json] = send('POST', '/api/plan-pages/999999/issues', [
    'title' => 'Crack in wall',
    'x' => 0.625,
    'y' => 0.25,
]);
check('issues store 404 for missing page', $code === 404);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);