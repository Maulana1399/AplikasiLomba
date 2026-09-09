<?php

use App\Models\Event;
use App\Support\ActiveEventContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root — auto-select competition event and redirect to dashboard
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    $event = Event::active()->where('event_type', 'competition')->first();

    if ($event) {
        app(ActiveEventContext::class)->set($event);

        return redirect()->route('competition.dashboard', $event);
    }

    return response()->view('errors.no-event', [], 404);
});

/*
|--------------------------------------------------------------------------
| Competition Routes — no auth required (internal LAN app)
|--------------------------------------------------------------------------
*/
Route::prefix('events/{event}')->middleware(['resolve.active-event'])->group(function () {

    Route::get('competition', App\Livewire\Competition\Dashboard::class)
        ->name('competition.dashboard');

    // Registrasi
    Route::get('competition/registration', App\Livewire\Competition\Registration::class)
        ->name('competition.registration');

    Route::get('competition/participants', App\Livewire\Competition\ParticipantList::class)
        ->name('competition.participants');

    // Setting
    Route::get('competition/categories', App\Livewire\Competition\Category\Index::class)
        ->name('competition.category.index');

    Route::get('competition/classes', App\Livewire\Competition\Class\Index::class)
        ->name('competition.class.index');

    Route::get('competition/venues', App\Livewire\Competition\Venue\Index::class)
        ->name('competition.venue.index');

    // Pembagian Tim
    Route::get('competition/teams', App\Livewire\Competition\Team\Index::class)
        ->name('competition.teams');

    // Lomba
    Route::get('competition/schedules', App\Livewire\Competition\Schedule\Index::class)
        ->name('competition.schedule.index');

    Route::get('competition/heat', App\Livewire\Competition\Heat\Index::class)
        ->name('competition.heat.index');

    Route::get('competition/operator-dashboard', App\Livewire\Competition\OperatorDashboard::class)
        ->name('competition.operator-dashboard');

    Route::get('competition/match-center', App\Livewire\Competition\MatchCenter::class)
        ->name('competition.match-center');

    Route::get('competition/official-panel', App\Livewire\Competition\OfficialPanel::class)
        ->name('competition.official-panel');

    Route::get('competition/bracket-manager', App\Livewire\Competition\BracketManager::class)
        ->name('competition.bracket-manager');

    Route::get('competition/schedules/{schedule}/outcomes', App\Livewire\Competition\Schedule\OutcomeManager::class)
        ->name('competition.schedule.outcomes');

    Route::get('competition/schedules/{schedule}/entries', App\Livewire\Competition\Schedule\EntryManager::class)
        ->name('competition.schedule.entries');

    // Viewer (public, no auth needed)
    Route::get('competition/viewer/{venue?}', App\Livewire\Competition\Viewer::class)
        ->name('competition.viewer');
});

/*
|--------------------------------------------------------------------------
| Event switcher API — internal endpoint for sidebar event switching
|--------------------------------------------------------------------------
*/
Route::post('/events/switch/{event}', function (Event $event) {
    if (! $event->isActive()) {
        abort(404);
    }

    app(ActiveEventContext::class)->set($event);

    return redirect()->route('competition.dashboard', $event);
})->name('events.switch');
