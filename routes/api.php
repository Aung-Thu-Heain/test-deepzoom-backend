<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

// Plans -----------------------------------------------------------------------
Route::post('/plans/upload-url', [PlanController::class, 'uploadUrl']);
Route::post('/plans/upload-complete', [PlanController::class, 'uploadComplete']);
Route::get('/plans/{plan}', [PlanController::class, 'show']);

// Annotations -----------------------------------------------------------------
Route::get('/plan-pages/{planPage}/annotations', [AnnotationController::class, 'index']);
Route::post('/plan-pages/{planPage}/annotations', [AnnotationController::class, 'store']);
Route::patch('/annotations/{annotation}', [AnnotationController::class, 'update']);
Route::get('/annotations/{annotation}/versions', [AnnotationController::class, 'versions']);
Route::delete('/annotations/{annotation}', [AnnotationController::class, 'destroy']);

// Issues ----------------------------------------------------------------------
Route::get('/plan-pages/{planPage}/issues', [IssueController::class, 'index']);
Route::post('/plan-pages/{planPage}/issues', [IssueController::class, 'store']);
Route::get('/issues/{issue}', [IssueController::class, 'show']);
Route::patch('/issues/{issue}', [IssueController::class, 'update']);
Route::delete('/issues/{issue}', [IssueController::class, 'destroy']);