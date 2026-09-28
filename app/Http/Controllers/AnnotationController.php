<?php

namespace App\Http\Controllers;

use App\Models\Annotation;
use App\Models\PlanPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnotationController extends Controller
{
    /**
     * GET /api/plan-pages/{planPage}/annotations
     */
    public function index(PlanPage $planPage): JsonResponse
    {
        $annotations = $planPage->annotations()
            ->orderBy('id')
            ->get()
            ->map(fn (Annotation $annotation) => $this->serialize($annotation));

        return response()->json($annotations);
    }

    /**
     * POST /api/plan-pages/{planPage}/annotations
     * Creates annotation (current_version = 1) + first version row.
     */
    public function store(Request $request, PlanPage $planPage): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:rectangle,arrow,freehand,text'],
            'geometry' => ['required', 'array'],
            'style' => ['sometimes', 'array'],
        ]);

        $annotation = $planPage->annotations()->create([
            'type' => $data['type'],
            'current_version' => 1,
        ]);

        $annotation->versions()->create([
            'version' => 1,
            'geometry' => $data['geometry'],
            'style' => $data['style'] ?? [],
        ]);

        return response()->json($this->serialize($annotation), 201);
    }

    /**
     * PATCH /api/annotations/{annotation}
     * Never overwrites old versions - each edit appends version N+1.
     */
    public function update(Request $request, Annotation $annotation): JsonResponse
    {
        $data = $request->validate([
            'geometry' => ['required', 'array'],
            'style' => ['sometimes', 'array'],
        ]);

        $currentVersion = $annotation->versions()
            ->where('version', $annotation->current_version)
            ->first();

        $nextVersion = $annotation->current_version + 1;

        $annotation->versions()->create([
            'version' => $nextVersion,
            'geometry' => $data['geometry'],
            'style' => $data['style'] ?? ($currentVersion?->style ?? []),
        ]);

        $annotation->update(['current_version' => $nextVersion]);

        return response()->json($this->serialize($annotation->fresh()));
    }

    /**
     * GET /api/annotations/{annotation}/versions
     */
    public function versions(Annotation $annotation): JsonResponse
    {
        $versions = $annotation->versions()->get()->map(fn ($version) => [
            'id' => $version->id,
            'annotationId' => $version->annotation_id,
            'version' => $version->version,
            'geometry' => $version->geometry,
            'style' => $version->style,
            'createdAt' => $version->created_at,
        ]);

        return response()->json($versions);
    }

    /**
     * DELETE /api/annotations/{annotation}
     */
    public function destroy(Annotation $annotation): JsonResponse
    {
        $annotation->delete();

        return response()->json(null, 204);
    }

    private function serialize(Annotation $annotation): array
    {
        $current = $annotation->currentVersion();

        return [
            'id' => $annotation->id,
            'planPageId' => $annotation->plan_page_id,
            'type' => $annotation->type,
            'currentVersion' => $annotation->current_version,
            'geometry' => $current?->geometry ?? [],
            'style' => $current?->style ?? [],
            'createdAt' => $annotation->created_at,
            'updatedAt' => $annotation->updated_at,
        ];
    }
}