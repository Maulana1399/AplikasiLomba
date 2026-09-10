<?php

use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root — auto-select competition event and redirect to dashboard
|--------------------------------------------------------------------------
*/
Route::get('/', function (App\Support\CompetitionBootstrap $bootstrap) {
    $event = $bootstrap->ensureActiveCompetitionEvent();

    app(ActiveEventContext::class)->set($event);

    return redirect()->route('competition.dashboard');
});

/*
|--------------------------------------------------------------------------
| Competition Routes — no auth required (internal LAN app)
|
| The event is resolved internally from the active competition context; the
| competition engine still receives event_id, but it is never exposed in the
| URL anymore (no /events/{event} prefix).
|--------------------------------------------------------------------------
*/
Route::prefix('competition')->middleware(['ensure.active-competition'])->group(function () {

    // Landing / internal home
    Route::get('dashboard', App\Livewire\Competition\Dashboard::class)
        ->name('competition.dashboard');

    // 1. Registrasi
    Route::get('registration', App\Livewire\Competition\Registration::class)
        ->name('competition.registration');

    Route::get('participants', App\Livewire\Competition\ParticipantList::class)
        ->name('competition.participants');

    // 2. Setting
    Route::get('categories', App\Livewire\Competition\Category\Index::class)
        ->name('competition.category.index');

    Route::get('classes', App\Livewire\Competition\Class\Index::class)
        ->name('competition.class.index');

    // 2b. Setting → Pilihan Dropdown (master data for registrasi dropdowns)
    Route::get('participant-classes', App\Livewire\Competition\ParticipantClass\Index::class)
        ->name('competition.participant-class.index');

    Route::get('desa', App\Livewire\Competition\Desa\Index::class)
        ->name('competition.desa.index');

    Route::get('kelompok', App\Livewire\Competition\Kelompok\Index::class)
        ->name('competition.kelompok.index');

    // 3. Pembagian Tim
    Route::get('teams', App\Livewire\Competition\Team\Index::class)
        ->name('competition.teams');

    // 4. Lomba
    Route::get('heat', App\Livewire\Competition\Heat\Index::class)
        ->name('competition.heat.index');

    Route::get('operator-dashboard', App\Livewire\Competition\OperatorDashboard::class)
        ->name('competition.operator-dashboard');

    Route::get('match-center', App\Livewire\Competition\MatchCenter::class)
        ->name('competition.match-center');

    Route::get('official-panel', App\Livewire\Competition\OfficialPanel::class)
        ->name('competition.official-panel');

    Route::get('bracket-manager', App\Livewire\Competition\BracketManager::class)
        ->name('competition.bracket-manager');

    // Schedule workflows — internal engine dependency (Heat/Bracket/Match/Result),
    // reachable from the match workflow, not exposed as a top-level menu.
    Route::get('schedules/{schedule}/outcomes', App\Livewire\Competition\Schedule\OutcomeManager::class)
        ->name('competition.schedule.outcomes');

    Route::get('schedules/{schedule}/entries', App\Livewire\Competition\Schedule\EntryManager::class)
        ->name('competition.schedule.entries');

    // Public viewer / TV display
    Route::get('viewer', App\Livewire\Competition\Viewer::class)
        ->name('competition.viewer');
});