<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'game', ['mode' => 'player']);
Route::view('/host', 'game', ['mode' => 'host']);
Route::view('/screen', 'game', ['mode' => 'screen']);
