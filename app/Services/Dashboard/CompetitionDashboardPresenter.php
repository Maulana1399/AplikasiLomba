<?php

namespace App\Services\Dashboard;

use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\Event;
use App\Models\Venue;
use Carbon\Carbon;

class CompetitionDashboardPresenter implements DashboardPresenterContract
{
    public function present(Event $event): array
    {
        $classIds = CompetitionClass::where('event_id', $event->id)
            ->where('is_active', true)
            ->pluck('id');

        $venueIds = Venue::where('event_id', $event->id)->pluck('id');

        $schedules = CompetitionSchedule::whereIn('competition_class_id', $classIds);

        $overview = [
            'participants' => CompetitionRegistration::whereIn('competition_class_id', $classIds)->count(),
            'classes' => $classIds->count(),
            'venues' => $venueIds->count(),
            'today_matches' => (clone $schedules)->whereDate('start_at', Carbon::today())->count(),
            'running' => (clone $schedules)->where('status', 'Playing')->count(),
            'finished' => (clone $schedules)->where('status', 'Finished')->count(),
            'pending' => CompetitionRegistration::whereIn('competition_class_id', $classIds)
                ->whereDoesntHave('scheduleEntries')
                ->count(),
        ];

        $todaySchedules = CompetitionSchedule::with([
            'competitionClass.competitionCategory',
            'venue',
            'scheduleEntries.competitionRegistration.participation.person',
        ])
            ->whereIn('competition_class_id', $classIds)
            ->where('start_at', '>=', Carbon::now())
            ->whereDate('start_at', Carbon::today())
            ->orderBy('start_at')
            ->take(10)
            ->get();

        $liveMatches = CompetitionSchedule::with([
            'competitionClass.competitionCategory',
            'venue',
            'scheduleEntries.competitionRegistration.participation.person',
        ])
            ->withCount('scheduleEntries as pc')
            ->whereIn('competition_class_id', $classIds)
            ->whereIn('status', ['Playing', 'Waiting Result', 'Ready'])
            ->orderByRaw("CASE WHEN status = 'Playing' THEN 0 WHEN status = 'Waiting Result' THEN 1 ELSE 2 END")
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn ($s) => $s->venue?->name ?? 'Tanpa Venue');

        $recentRegistrations = CompetitionRegistration::with([
            'participation.person',
            'competitionClass',
            'competitionCategory',
        ])
            ->whereIn('competition_class_id', $classIds)
            ->latest()
            ->take(10)
            ->get();

        $recentResults = CompetitionSchedule::with([
            'competitionClass',
            'winner.participation.person',
        ])
            ->whereIn('competition_class_id', $classIds)
            ->where('status', 'Finished')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->take(10)
            ->get();

        return [
            'overview' => $overview,
            'todaySchedules' => $todaySchedules,
            'liveMatches' => $liveMatches,
            'recentRegistrations' => $recentRegistrations,
            'recentResults' => $recentResults,
        ];
    }

    public function view(): string
    {
        return 'livewire.competition.dashboard.competition';
    }
}
