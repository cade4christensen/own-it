<?php

use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::get('/state', [GameController::class, 'state']);
Route::post('/join', [GameController::class, 'join']);
Route::post('/answer', [GameController::class, 'answer']);
Route::post('/vote', [GameController::class, 'vote']);

Route::post('/host/advance', [GameController::class, 'advance']);
Route::post('/host/prompts', [GameController::class, 'prompts']);
Route::post('/host/remove', [GameController::class, 'remove']);
