<?php

use Illuminate\Support\Facades\Route;

// BoothPOS's frontend is a Vue SPA (Vue Router, history mode) built by Vite
// and served as static assets by this same Laravel app (PRD §9 — one local
// install, no separate frontend server). Every non-API GET falls through to
// the same Blade shell so client-side routing can take over; `/api/*` is
// handled entirely by routes/api.php and never reaches this catch-all.
Route::get('/{any}', fn () => view('app'))
    ->where('any', '^(?!api).*$')
    ->name('spa');
