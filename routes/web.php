<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any}', function () {
    return response()->json(['app' => 'fieldwire-demo backend']);
})->where('any', '.*');