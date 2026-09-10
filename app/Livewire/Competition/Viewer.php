<?php

namespace App\Livewire\Competition;

use App\Models\CompetitionAnnouncement;
use App\Models\CompetitionClass;
use App\Models\CompetitionSchedule;
use App\Models\Event;
use App\Models\Venue;
use App\Support\ActiveEventContext;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.viewer')]
class Viewer extends Component
{
    public ?Event $event;

    public ?string $venueId = null;

    public bool $tvMode = false;

    public ?CompetitionAnnouncement $announcement = null;

    public function mount(): void
    {
        $this->event = app(ActiveEventContext::class)->requireCurrent();

        abort_unless($this->event->isActive(), 404);
        abort_unless($this->event->isCompetition(), 404);

        $this->venueId = request()->query('venue') ? (string) request()->query('venue') : null;
        $this->tvMode = request()->query('display') === 'tv';

        $this->loadAnnouncement();
    }

    public function filterByVenue($id = null): void
    {
        $this->venueId = $id ? (string) $id : null;
    }

    public function loadAnnouncement(): void
    {
        $this->announcement = CompetitionAnnouncement::active()
            ->where('event_id', $this->event->id)
            ->latest()
            ->first();
    }

    public function dismissAnnouncement(): void
    {
        $this->announcement = null;
    }

    public function render()
    {
        $classIds = CompetitionClass::where('event_id', $this->event->id)->pluck('id');

        $venueModel = $this->venueId ? Venue::find($this->venueId) : null;

        $query = CompetitionSchedule::with([
            'competitionClass.competitionCategory',
            'venue',
            'scheduleEntries.competitionRegistration.participation.person',
        ])->whereIn('competition_class_id', $classIds);

        if ($venueModel) {
            $query->where('venue_id', $venueModel->id);
        }

        $schedules = $query->orderBy('sort_order')->orderBy('start_at')->get();

        $playing = $schedules->where('status', 'Playing')->values();
        $ready = $schedules->where('status', 'Ready')->values();
        $scheduled = $schedules->where('status', 'Scheduled')->sortBy('start_at')->take(5)->values();

        $currentTime = Carbon::now()->format('H:i:s');

        return view('livewire.competition.viewer', [
            'playing' => $playing,
            'ready' => $ready,
            'scheduled' => $scheduled,
            'venues' => Venue::where('event_id', $this->event->id)->orderBy('sort_order')->orderBy('name')->get(),
            'currentTime' => $currentTime,
            'selectedVenue' => $venueModel,
        ]);
    }
}
