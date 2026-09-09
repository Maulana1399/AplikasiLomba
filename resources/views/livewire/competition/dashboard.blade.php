<div class="space-y-6">
    {{-- Breadcrumb --}}
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard', app(\App\Support\ActiveEventContext::class)->current()) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Competition &mdash; {{ $eventName }}</span>
    </div>

    {{-- Konten Competition via partial bersama --}}
    @include('livewire.event.dashboard.competition', [
        'overview'            => $overview,
        'liveMatches'         => $liveMatches,
        'todaySchedules'      => $todaySchedules,
        'recentRegistrations' => $recentRegistrations,
        'recentResults'       => $recentResults,
    ])
</div>
