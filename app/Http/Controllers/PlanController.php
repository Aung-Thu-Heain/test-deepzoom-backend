<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPlanPdf;
use App\Models\Plan;
use App\Services\S3UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    public function __construct(private S3UploadService $s3)
    {
    }

    /**
     * POST /api/plans/upload-url
     * Issue a presigned S3 PUT URL so React can upload the PDF directly.
     */
    public function uploadUrl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/\.pdf$/i'],
        ]);

        $s3Key = 'demo/uploads/'.Str::uuid().'.pdf';

        return response()->json([
            'uploadUrl' => $this->s3->presignedUploadUrl($s3Key),
            's3Key' => $s3Key,
        ]);
    }

    /**
     * POST /api/plans/upload-complete
     * React finished the direct-to-S3 upload; create the plan and queue processing.
     */
    public function uploadComplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            's3Key' => ['required', 'string', 'regex:/^demo\/uploads\/[a-zA-Z0-9._-]+\.pdf$/'],
        ]);

        $plan = Plan::create([
            'name' => $data['name'],
            'original_pdf_key' => $data['s3Key'],
            'status' => 'processing',
        ]);

        ProcessPlanPdf::dispatch($plan);

        return response()->json($plan, 201);
    }

    /**
     * GET /api/plans/{plan}
     */
    public function show(Plan $plan): JsonResponse
    {
        return response()->json([
            'id' => $plan->id,
            'name' => $plan->name,
            'status' => $plan->status,
            'pageCount' => $plan->page_count,
            'pages' => $plan->pages->map(fn ($page) => [
                'id' => $page->id,
                'pageNumber' => $page->page_number,
                'width' => $page->width,
                'height' => $page->height,
                'dziUrl' => $this->s3->publicUrl($page->dzi_key),
                'thumbnailUrl' => $page->thumbnail_key ? $this->s3->publicUrl($page->thumbnail_key) : null,
            ])->values(),
        ]);
    }
}