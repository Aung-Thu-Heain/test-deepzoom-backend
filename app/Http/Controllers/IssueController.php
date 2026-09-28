<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\PlanPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IssueController extends Controller
{
    /**
     * GET /api/plan-pages/{planPage}/issues
     */
    public function index(PlanPage $planPage): JsonResponse
    {
        $issues = $planPage->issues()
            ->orderBy('id')
            ->get()
            ->map(fn (Issue $issue) => $this->serialize($issue));

        return response()->json($issues);
    }

    /**
     * POST /api/plan-pages/{planPage}/issues
     * Coordinates are stored normalized (0.0 - 1.0).
     */
    public function store(Request $request, PlanPage $planPage): JsonResponse
    {
        $data = $this->validateInput($request);

        $issue = $planPage->issues()->create($data);

        return response()->json($this->serialize($issue), 201);
    }

    /**
     * GET /api/issues/{issue}
     */
    public function show(Issue $issue): JsonResponse
    {
        return response()->json($this->serialize($issue));
    }

    /**
     * PATCH /api/issues/{issue}
     */
    public function update(Request $request, Issue $issue): JsonResponse
    {
        $data = $this->validateInput($request);
        $issue->update($data);

        return response()->json($this->serialize($issue->fresh()));
    }

    /**
     * DELETE /api/issues/{issue}
     */
    public function destroy(Issue $issue): JsonResponse
    {
        $issue->delete();

        return response()->json(null, 204);
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:open,in_progress,resolved'],
            'priority' => ['required', 'in:low,medium,high'],
            'x' => ['required', 'numeric', 'min:0', 'max:1'],
            'y' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);
    }

    private function serialize(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'planPageId' => $issue->plan_page_id,
            'title' => $issue->title,
            'description' => $issue->description,
            'status' => $issue->status,
            'priority' => $issue->priority,
            'x' => (float) $issue->x,
            'y' => (float) $issue->y,
            'createdAt' => $issue->created_at,
            'updatedAt' => $issue->updated_at,
        ];
    }
}