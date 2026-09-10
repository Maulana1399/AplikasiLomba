<div class="space-y-6">
    {{-- Overview cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Peserta</p>
            <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $overview['participants'] ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Kelas</p>
            <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $overview['classes'] ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tempat</p>
            <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $overview['venues'] ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Agenda Hari Ini</p>
            <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $overview['today_matches'] ?? 0 }}</p>
        </div>
    </div>

    {{-- Match status strip --}}
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-3 py-1 font-medium text-green-800 dark:bg-green-900 dark:text-green-200">
            Berlangsung: {{ $overview['running'] ?? 0 }}
        </span>
        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
            Selesai: {{ $overview['finished'] ?? 0 }}
        </span>
        <span class="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-3 py-1 font-medium text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
            Belum terjadwal: {{ $overview['pending'] ?? 0 }}
        </span>
    </div>

    {{-- Live / active matches --}}
    @if ($liveMatches->isNotEmpty())
        <div class="rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
            <h2 class="mb-3 text-lg font-bold text-green-800 dark:text-green-200">Lomba Berlangsung</h2>
            @foreach ($liveMatches as $venueName => $matches)
                <div class="mb-3 last:mb-0">
                    <p class="mb-1 text-xs font-medium uppercase tracking-wide text-green-700 dark:text-green-300">{{ $venueName }}</p>
                    <div class="grid gap-2">
                        @foreach ($matches as $schedule)
                            <div class="flex items-center justify-between rounded-lg border border-green-300 bg-white p-3 dark:border-green-700 dark:bg-zinc-900">
                                <div>
                                    <div class="font-semibold text-zinc-900 dark:text-white">
                                        {{ $schedule->competitionClass?->competitionCategory?->name }} / {{ $schedule->competitionClass?->name }}
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $schedule->start_at ? \Carbon\Carbon::parse($schedule->start_at)->format('H:i') : '-' }} - {{ $schedule->end_at ? \Carbon\Carbon::parse($schedule->end_at)->format('H:i') : '-' }}
                                    </div>
                                </div>
                                <span class="rounded-full bg-green-600 px-2 py-0.5 text-xs font-medium text-white">{{ $schedule->status }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Today's schedules --}}
    @if ($todaySchedules->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-3 text-lg font-bold text-zinc-800 dark:text-zinc-200">Agenda Hari Ini</h2>
            <div class="grid gap-2">
                @foreach ($todaySchedules as $schedule)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">
                                {{ $schedule->competitionClass?->competitionCategory?->name }} / {{ $schedule->competitionClass?->name }}
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $schedule->venue?->name ?? '-' }} &middot; {{ $schedule->start_at ? \Carbon\Carbon::parse($schedule->start_at)->format('H:i') : '-' }}</div>
                        </div>
                        <span @class([
                            'rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' => $schedule->status === 'Finished',
                            'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' => $schedule->status === 'Ready',
                            'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' => $schedule->status === 'Scheduled',
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300' => $schedule->status === 'Playing',
                        ])>{{ $schedule->status }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Recent registrations --}}
    @if ($recentRegistrations->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-3 text-lg font-bold text-zinc-800 dark:text-zinc-200">Registrasi Terbaru</h2>
            <div class="grid gap-2">
                @foreach ($recentRegistrations as $registration)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $registration->participation?->person?->nama ?? '-' }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $registration->competitionCategory?->name }} / {{ $registration->competitionClass?->name }}
                            </div>
                        </div>
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $registration->created_at?->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Recent results --}}
    @if ($recentResults->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-3 text-lg font-bold text-zinc-800 dark:text-zinc-200">Hasil Terbaru</h2>
            <div class="grid gap-2">
                @foreach ($recentResults as $schedule)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $schedule->competitionClass?->name ?? '-' }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($schedule->winner_registration_id)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-2 py-0.5 font-medium text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200">🏆 {{ $schedule->winner?->participation?->person?->nama ?? '-' }}</span>
                                @endif
                                <span>{{ $schedule->finished_at?->format('d/m/Y H:i') }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($liveMatches->isEmpty() && $todaySchedules->isEmpty() && $recentRegistrations->isEmpty() && $recentResults->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-200 p-10 text-center dark:border-zinc-700">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Belum ada data untuk ditampilkan.</p>
        </div>
    @endif
</div>