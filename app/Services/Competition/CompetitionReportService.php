<?php

namespace App\Services\Competition;

use App\Models\CompetitionAnnouncement;
use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionRegistration;
use App\Models\CompetitionSchedule;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Support\Collection;

class CompetitionReportService
{
    public function summary(Event $event): array
    {
        $categories = CompetitionCategory::where('event_id', $event->id)->withCount('competitionClasses')->get();
        $classIds = CompetitionClass::where('event_id', $event->id)->pluck('id');
        $schedules = CompetitionSchedule::with('venue')
            ->whereIn('competition_class_id', $classIds)
            ->get();

        $registrationCount = CompetitionRegistration::whereIn('competition_class_id', $classIds)->count();
        $activeCount = CompetitionAnnouncement::active()->where('event_id', $event->id)->count();

        return [
            'total_categories' => $categories->count(),
            'total_classes' => $classIds->count(),
            'total_registrations' => $registrationCount,
            'total_venues' => Venue::where('event_id', $event->id)->count(),
            'total_schedules' => $schedules->count(),
            'scheduled' => $schedules->where('status', 'Scheduled')->count(),
            'ready' => $schedules->where('status', 'Ready')->count(),
            'playing' => $schedules->where('status', 'Playing')->count(),
            'finished' => $schedules->where('status', 'Finished')->count(),
            'active_announcements' => $activeCount,
            'categories' => $categories,
        ];
    }

    public function registrationReport(Event $event, array $filters = []): Collection
    {
        $classIds = CompetitionClass::where('event_id', $event->id)->pluck('id');

        $query = CompetitionRegistration::with([
            'participation.person.desa',
            'participation.person.kelompok',
            'competitionCategory',
            'competitionClass',
        ])->whereIn('competition_class_id', $classIds);

        if (! empty($filters['category_id'])) {
            $query->where('competition_category_id', $filters['category_id']);
        }
        if (! empty($filters['class_id'])) {
            $query->where('competition_class_id', $filters['class_id']);
        }
        if (! empty($filters['gender'])) {
            $query->whereHas('participation.person', function ($q) use ($filters) {
                $q->where('jenis_kelamin', $filters['gender']);
            });
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('participation.person', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('id')->get();
    }

    public function scheduleReport(Event $event, array $filters = []): Collection
    {
        $classIds = CompetitionClass::where('event_id', $event->id)->pluck('id');

        $query = CompetitionSchedule::with([
            'competitionClass.competitionCategory',
            'venue',
            'scheduleEntries',
        ])->withCount('scheduleEntries as participants_count')
            ->whereIn('competition_class_id', $classIds);

        if (! empty($filters['venue_id'])) {
            $query->where('venue_id', $filters['venue_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('sort_order')->orderBy('start_at')->get();
    }

    public function outcomeReport(Event $event, array $filters = []): Collection
    {
        $classIds = CompetitionClass::where('event_id', $event->id)->pluck('id');

        $query = CompetitionOutcome::with([
            'competitionRegistration.participation.person',
            'competitionRegistration.competitionCategory',
            'competitionRegistration.competitionClass',
        ])->whereHas('competitionRegistration', function ($q) use ($classIds, $filters) {
            $q->whereIn('competition_class_id', $classIds);
            if (! empty($filters['category_id'])) {
                $q->where('competition_category_id', $filters['category_id']);
            }
            if (! empty($filters['class_id'])) {
                $q->where('competition_class_id', $filters['class_id']);
            }
        });

        return $query->orderBy('position')->orderBy('id')->get();
    }

    public function venueStatistics(Event $event): Collection
    {
        $venues = Venue::where('event_id', $event->id)->get();
        $classIds = CompetitionClass::where('event_id', $event->id)->pluck('id');

        return $venues->map(function ($venue) use ($classIds) {
            $schedules = CompetitionSchedule::where('venue_id', $venue->id)
                ->whereIn('competition_class_id', $classIds)
                ->get();

            return (object) [
                'venue' => $venue,
                'total_schedules' => $schedules->count(),
                'finished' => $schedules->where('status', 'Finished')->count(),
                'ready' => $schedules->where('status', 'Ready')->count(),
                'playing' => $schedules->where('status', 'Playing')->count(),
            ];
        });
    }

    public function categoryStatistics(Event $event): Collection
    {
        $classIds = CompetitionClass::where('event_id', $event->id)->pluck('id');

        return CompetitionCategory::where('event_id', $event->id)
            ->withCount('competitionClasses')
            ->get()
            ->map(function ($category) use ($classIds) {
                $catClassIds = CompetitionClass::where('competition_category_id', $category->id)
                    ->whereIn('id', $classIds)
                    ->pluck('id');

                $finished = CompetitionSchedule::whereIn('competition_class_id', $catClassIds)
                    ->where('status', 'Finished')
                    ->count();

                $registrations = CompetitionRegistration::whereIn('competition_class_id', $catClassIds)->count();

                return (object) [
                    'category' => $category,
                    'total_classes' => $category->competition_classes_count,
                    'total_registrations' => $registrations,
                    'finished_schedules' => $finished,
                ];
            });
    }

    public function classStatistics(Event $event): Collection
    {
        $classIds = CompetitionClass::where('event_id', $event->id)->pluck('id');

        return CompetitionClass::whereIn('id', $classIds)
            ->with('competitionCategory')
            ->get()
            ->map(function ($class) {
                $registrations = CompetitionRegistration::where('competition_class_id', $class->id)->count();
                $schedules = CompetitionSchedule::where('competition_class_id', $class->id)->get();
                $finished = $schedules->where('status', 'Finished')->count();
                $outcomes = CompetitionOutcome::whereHas('competitionRegistration', function ($q) use ($class) {
                    $q->where('competition_class_id', $class->id);
                })->count();

                return (object) [
                    'class' => $class,
                    'category_name' => $class->competitionCategory?->name,
                    'total_registrations' => $registrations,
                    'total_schedules' => $schedules->count(),
                    'finished_schedules' => $finished,
                    'total_outcomes' => $outcomes,
                ];
            });
    }
}
