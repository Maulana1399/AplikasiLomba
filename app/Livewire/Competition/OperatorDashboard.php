<?php

namespace App\Livewire\Competition;

use App\Models\CompetitionAnnouncement;
use App\Models\CompetitionClass;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionSchedule;
use App\Services\Competition\CompetitionWorkflowService;
use App\Support\ActiveEventContext;
use Carbon\Carbon;
use Livewire\Component;

class OperatorDashboard extends Component
{
    public string $announcementMessage = '';

    public bool $showAnnouncementForm = false;

    public bool $processing = false;

    private function workflow(): CompetitionWorkflowService
    {
        return app(CompetitionWorkflowService::class);
    }

    public function advanceStatus(int $scheduleId): void
    {

        $schedule = CompetitionSchedule::withCount('scheduleEntries as participants_count')->findOrFail($scheduleId);

        if ($schedule->start_at && Carbon::parse($schedule->start_at)->isFuture()) {
            // AplikasiLomba: no-auth LAN app, skip time check for admin
            return;
        }

        if ($schedule->status === 'Playing') {
            $nextStatus = $this->workflow()->completeMatch($schedule);

            if ($nextStatus === 'Waiting Result') {
                session()->flash('info', 'Pertandingan menunggu hasil official.');

                return;
            }

            if ($nextStatus !== 'Finished') {
                session()->flash('error', 'Gagal menyelesaikan pertandingan.');
            }

            return;
        }

        $result = match ($schedule->status) {
            'Scheduled' => $this->workflow()->prepareMatch($schedule),
            'Ready' => $this->workflow()->startMatch($schedule),
            default => false,
        };

        if (! $result && $schedule->status === 'Scheduled' && ! $schedule->canAutoReady()) {
            session()->flash('error', 'Tidak dapat mengubah ke Ready: peserta belum lengkap.');
        } elseif (! $result && $schedule->status === 'Ready' && ! $schedule->isReadyForStart()) {
            session()->flash('error', 'Tidak dapat memulai pertandingan: peserta belum lengkap.');
        }
    }

    public function resetStatus(int $scheduleId): void
    {

        $schedule = CompetitionSchedule::with('bracketMatch')->findOrFail($scheduleId);

        $result = $this->workflow()->resetMatch($schedule);

        if (! ($result['reset'] ?? false)) {
            session()->flash('error', $result['reason'] === 'downstream_active'
                ? 'Reset dibatalkan: babak berikutnya masih berlangsung (Playing / Waiting Result). Selesaikan atau input hasil babak tersebut terlebih dahulu.'
                : 'Reset dibatalkan: status pertandingan tidak memungkinkan reset.');

            return;
        }

        if ($result['invalidated_downstream'] ?? false) {
            session()->flash('info', 'Pertandingan direset. Hasil babak berikutnya yang bergantung pada pertandingan ini ikut dibatalkan, termasuk podium.');

            return;
        }

        session()->flash('success', 'Pertandingan berhasil direset.');
    }

    public function toggleAnnouncementForm(): void
    {
        $this->showAnnouncementForm = ! $this->showAnnouncementForm;
        $this->reset(['announcementMessage']);
        $this->resetErrorBag();
    }

    public function publishAnnouncement(): void
    {

        if ($this->processing) {
            return;
        }
        $this->processing = true;

        try {
            $this->validate([
                'announcementMessage' => 'required|string|max:500',
            ]);

            $event = app(ActiveEventContext::class)->requireCurrent();

            CompetitionAnnouncement::where('event_id', $event->id)->update(['is_active' => false]);

            CompetitionAnnouncement::create([
                'event_id' => $event->id,
                'message' => $this->announcementMessage,
                'is_active' => true,
                'expires_at' => now()->addMinutes(5),
            ]);

            $this->showAnnouncementForm = false;
            $this->reset(['announcementMessage']);
            session()->flash('success', 'Pengumuman berhasil dipublikasikan.');
        } finally {
            $this->processing = false;
        }
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();

        $schedules = CompetitionSchedule::with(['competitionClass.competitionCategory', 'venue', 'winner.participation.person', 'finishedBy'])
            ->whereIn('competition_class_id', CompetitionClass::where('event_id', $event?->id)->pluck('id'))
            ->withCount('scheduleEntries as participants_count')
            ->orderBy('sort_order')
            ->orderBy('start_at')
            ->get()
            ->map(function ($schedule) {
                $schedule->is_future = $schedule->start_at && Carbon::parse($schedule->start_at)->isFuture();
                $schedule->has_outcome = CompetitionOutcome::whereHas('competitionRegistration', function ($q) use ($schedule) {
                    $q->where('competition_class_id', $schedule->competition_class_id);
                })->exists();

                return $schedule;
            });

        $nowPlaying = $schedules->where('status', 'Playing');
        $ready = $schedules->where('status', 'Ready');
        $scheduled = $schedules->where('status', 'Scheduled')->sortBy('start_at');
        $finished = $schedules->where('status', 'Finished');

        $activeAnnouncement = CompetitionAnnouncement::active()
            ->where('event_id', $event?->id)
            ->latest()
            ->first();

        $viewerUrl = $event ? route('competition.viewer', ['event' => $event->id], true) : null;
        $tvUrl = $event ? route('competition.viewer', ['event' => $event->id, 'venue' => null, 'display' => 'tv'], true) : null;

        return view('livewire.competition.operator-dashboard', [
            'nowPlaying' => $nowPlaying,
            'ready' => $ready,
            'scheduled' => $scheduled,
            'finished' => $finished,
            'activeAnnouncement' => $activeAnnouncement,
            'viewerUrl' => $viewerUrl,
            'tvUrl' => $tvUrl,
            'canManage' => true,
            'schedules' => $schedules,
        ]);
    }
}
