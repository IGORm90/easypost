<?php

use Illuminate\Support\Facades\Route;

// Serves the Vue SPA shell for every route except the API, Sanctum and
// Horizon prefixes, which are handled by their own route files/packages.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|horizon).*$');
