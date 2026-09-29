<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Models\PlanPage;
use App\Services\PdfProcessingService;
use App\Services\S3UploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPlanPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $timeout = 900;

    public function __construct(public Plan $plan)
    {
    }

    public function handle(PdfProcessingService $pdf, S3UploadService $s3): void
    {
        $tmpDir = storage_path('app/tmp/'.$this->plan->id);

        File::ensureDirectoryExists($tmpDir);

        try {
            // 1. status -> processing
            $this->plan->forceFill(['status' => 'processing'])->save();

            // 3. download the PDF from S3
            $pdfPath = $tmpDir.'/drawing.pdf';
            $s3->download($this->plan->original_pdf_key, $pdfPath);

            // 4. page count
            $pageCount = $pdf->pageCount($pdfPath);

            if ($pageCount < 1) {
                throw new \RuntimeException('PDF has no pages.');
            }

            $processedPages = 0;

            // 5.-12. process one page at a time
            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                // Poppler -> high-res PNG
                $pageImage = $pdf->renderPage($pdfPath, $tmpDir.'/page-'.$pageNumber, $pageNumber);

                [$width, $height] = $pdf->imageDimensions($pageImage);

                // libvips -> Deep Zoom tiles + thumbnail
                $pageBase = $tmpDir.'/pages/'.$pageNumber.'/page';
                File::ensureDirectoryExists(dirname($pageBase));
                $pdf->buildDeepZoom($pageImage, $pageBase);
                $pdf->makeThumbnail($pageImage, $tmpDir.'/pages/'.$pageNumber.'/thumbnail.jpg');

                // upload .dzi, tiles and thumbnail to S3
                $pageKeyBase = 'demo/plans/'.$this->plan->id.'/pages/'.$pageNumber;
                $dziKey = $pageKeyBase.'/page.dzi';
                $thumbKey = $pageKeyBase.'/thumbnail.jpg';

                foreach (File::allFiles($tmpDir.'/pages/'.$pageNumber) as $file) {
                    $key = match ($file->getFilename()) {
                        'page.dzi' => $dziKey,
                        'thumbnail.jpg' => $thumbKey,
                        default => $pageKeyBase.'/'.$file->getRelativePathname(),
                    };

                    $s3->uploadFile($file->getPathname(), $key);
                }

                // Keep this write idempotent: a retried job may have already
                // recorded this page before failing later in the pipeline.
                PlanPage::upsert([
                    [
                        'plan_id' => $this->plan->id,
                        'page_number' => $pageNumber,
                        'width' => $width,
                        'height' => $height,
                        'dzi_key' => $dziKey,
                        'thumbnail_key' => $thumbKey,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ], ['plan_id', 'page_number'], [
                    'width',
                    'height',
                    'dzi_key',
                    'thumbnail_key',
                    'updated_at',
                ]);

                $processedPages++;

                // free disk space after each page
                File::deleteDirectory($tmpDir.'/pages/'.$pageNumber);
                File::delete($pageImage);
            }

            // 13.-14. update page_count and mark ready
            $this->plan->forceFill([
                'status' => 'ready',
                'page_count' => $processedPages,
            ])->save();

            Log::info('Plan processed successfully', [
                'plan_id' => $this->plan->id,
                'pages' => $processedPages,
            ]);
        } catch (Throwable $e) {
            // 15. failed -> status = failed, log the exception
            $this->plan->forceFill(['status' => 'failed'])->save();

            Log::error('PDF processing failed', [
                'plan_id' => $this->plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }
}