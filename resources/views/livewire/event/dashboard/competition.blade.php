@php \Carbon\Carbon::setLocale('id'); @endphp

{{-- Ringkasan Competition --}}
@if (!empty($overview))
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
            <div class="text-xs font-medium uppercase tracking-wide text-blue-600 dark:text-blue-400">Peserta</div>
            <div class="mt-1 text-2xl font-bold text-blue-800 dark:text-blue-200">{{ $overview['participants'] }}</div>
        </div>
        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-950">
            <div class="text-xs font-medium uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Kelas</div>
            <div class="mt-1 text-2xl font-bold text-indigo-800 dark:text-indigo-200">{{ $overview['classes'] }}</div>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
            <div class="text-xs font-medium uppercase tracking-wide text-amber-600 dark:text-amber-400">Hari Ini</div>
            <div class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">{{ $overview['today_matches'] }}</div>
        </div>
        <div class="rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
            <div class="text-xs font-medium uppercase tracking-wide text-green-600 dark:text-green-400">Berlangsung</div>
            <div class="mt-1 text-2xl font-bold text-green-800 dark:text-green-200">{{ $overview['running'] }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-600 dark:text-zinc-400">Selesai</div>
            <div class="mt-1 text-2xl font-bold text-zinc-800 dark:text-zinc-200">{{ $overview['finished'] }}</div>
        </div>
        @if ($overview['pending'] > 0)
            <div class="rounded-xl border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-800 dark:bg-yellow-950">
                <div class="text-xs font-medium uppercase tracking-wide text-yellow-600 dark:text-yellow-400">Belum Dijadwalkan</div>
                <div class="mt-1 text-2xl font-bold text-yellow-800 dark:text-yellow-200">{{ $overview['pending'] }}</div>
            </div>
        @endif
    </div>
@endif

{{-- Live Pertandingan --}}
@if ($liveMatches->isNotEmpty())
    <div>
        <h2 class="mb-3 text-lg font-bold text-zinc-900 dark:text-white">Live Pertandingan</h2>
        @foreach ($liveMatches as $venueName => $matches)
            <div class="mb-4">
                <h3 class="mb-2 text-sm font-semibold text-zinc-600 dark:text-zinc-400">{{ $venueName }}</h3>
                <div class="space-y-2">
                    @foreach ($matches as $schedule)
                        @php
                            $participants = $schedule->scheduleEntries->map(fn($e) => $e->competitionRegistration?->participation?->person?->nama)->filter();
                        @endphp
                        <a href="{{ route('competition.match-center', absolute: false) }}"
                           class="flex items-center justify-between rounded-lg border bg-white p-3 transition hover:shadow dark:bg-zinc-950
                                  {{ $schedule->status === 'Playing' ? 'border-green-300 dark:border-green-700' : '' }}
                                  {{ $schedule->status === 'Waiting Result' ? 'border-yellow-300 dark:border-yellow-700' : '' }}
                                  {{ $schedule->status === 'Ready' ? 'border-blue-300 dark:border-blue-700' : '' }}">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $schedule->competitionClass?->name ?? '-' }}</div>
                                @if ($participants->isNotEmpty())
                                    <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $participants->implode(' vs ') }}</div>
                                @endif
                            </div>
                            <span @class([
                                'ml-2 inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase',
                                'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200'  => $schedule->status === 'Playing',
                                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-200' => $schedule->status === 'Waiting Result',
                                'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200'     => $schedule->status === 'Ready',
                            ])>{{ $schedule->status }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- Jadwal Hari Ini --}}
@if ($todaySchedules->isNotEmpty())
    <div>
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Jadwal Hari Ini</h2>
            <a href="{{ route('competition.match-center', absolute: false) }}" class="text-sm text-blue-600 hover:text-blue-800">Lihat Semua</a>
        </div>
        <div class="space-y-2">
            @foreach ($todaySchedules as $schedule)
                @php $participants = $schedule->scheduleEntries->map(fn($e) => $e->competitionRegistration?->participation?->person?->nama)->filter(); @endphp
                <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="shrink-0 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $schedule->start_at?->format('H:i') ?? '-' }}</span>
                            <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $schedule->competitionClass?->name ?? '-' }}</span>
                        </div>
                        @if ($schedule->venue)<div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $schedule->venue->name }}</div>@endif
                        @if ($participants->isNotEmpty())
                            <div class="mt-0.5 flex flex-wrap gap-1">
                                @foreach ($participants as $name)
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ $name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <span @class([
                        'ml-2 inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-medium',
                        'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200'   => $schedule->status === 'Scheduled',
                        'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'   => $schedule->status === 'Ready',
                        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $schedule->status === 'Playing',
                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' => $schedule->status === 'Waiting Result',
                        'bg-zinc-800 text-white dark:bg-black dark:text-zinc-300'         => $schedule->status === 'Finished',
                    ])>{{ $schedule->status }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- Aktivitas Terbaru --}}
<div class="grid gap-6 sm:grid-cols-2">
    @if ($recentRegistrations->isNotEmpty())
        <div>
            <h2 class="mb-3 text-lg font-bold text-zinc-900 dark:text-white">Registrasi Terbaru</h2>
            <div class="space-y-2">
                @foreach ($recentRegistrations as $reg)
                    <div class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $reg->participation?->person?->nama ?? '-' }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $reg->competitionClass?->name ?? '-' }} &middot; {{ $reg->created_at->diffForHumans() }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    @if ($recentResults->isNotEmpty())
        <div>
            <h2 class="mb-3 text-lg font-bold text-zinc-900 dark:text-white">Hasil Terbaru</h2>
            <div class="space-y-2">
                @foreach ($recentResults as $result)
                    <div class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-950">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $result->competitionClass?->name ?? '-' }}</div>
                        @if ($result->winner)
                            <div class="text-xs text-yellow-600 dark:text-yellow-400">🏆 {{ $result->winner->participation?->person?->nama ?? '-' }}</div>
                        @endif
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $result->finished_at?->diffForHumans() ?? '' }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
