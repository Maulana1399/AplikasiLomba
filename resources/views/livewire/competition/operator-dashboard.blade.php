<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Operator Dashboard</span>
    </div>

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Operator Dashboard</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Atur status jadwal dan kelola pengumuman.</p>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="toggleAnnouncementForm" variant="primary">
                {{ $showAnnouncementForm ? 'Batal' : 'Pengumuman' }}
            </flux:button>
        </div>
    </div>

    {{-- Viewer URLs --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex-1">
                <p class="text-xs font-medium uppercase tracking-wide text-blue-600 dark:text-blue-400">Viewer Publik</p>
                <p class="mt-1 text-sm text-blue-800 dark:text-blue-200 break-all">{{ $viewerUrl }}</p>
            </div>
            <div class="flex gap-2">
                <flux:button size="sm" variant="primary" onclick="navigator.clipboard.writeText('{{ $viewerUrl }}')">Salin</flux:button>
                <flux:button size="sm" variant="primary" :href="$viewerUrl" external>Buka</flux:button>
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-4 border-t border-blue-200 pt-3 dark:border-blue-800">
            <div class="flex-1">
                <p class="text-xs font-medium uppercase tracking-wide text-blue-600 dark:text-blue-400">TV Display</p>
                <p class="mt-1 text-sm text-blue-800 dark:text-blue-200 break-all">{{ $tvUrl }}</p>
                <p class="mt-1 text-xs text-blue-600 dark:text-blue-400">Gunakan mode landscape + fullscreen untuk display TV 16:9. Auto-refresh setiap 5 detik.</p>
            </div>
            <div class="flex gap-2">
                <flux:button size="sm" variant="primary" onclick="navigator.clipboard.writeText('{{ $tvUrl }}')">Salin</flux:button>
                <flux:button size="sm" variant="primary" :href="$tvUrl" external>Buka</flux:button>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">{{ session('success') }}</div>
    @endif
    @if (session('info'))
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ session('info') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ session('error') }}</div>
    @endif

    {{-- Announcement Form --}}
    @if ($showAnnouncementForm)
        <div class="rounded-xl border border-yellow-200 bg-yellow-50 p-6 dark:border-yellow-800 dark:bg-yellow-950">
            <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Publikasi Pengumuman</h2>
            <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">Pengumuman akan tampil di Viewer selama 5 menit.</p>
            <div class="flex gap-3">
                <div class="flex-1">
                    <flux:input wire:model="announcementMessage" placeholder="Tulis pengumuman..." />
                    @error('announcementMessage') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <flux:button wire:click="publishAnnouncement" variant="primary" :loading="$processing">Kirim</flux:button>
            </div>
            @if ($activeAnnouncement)
                <div class="mt-3 rounded-lg border border-yellow-300 bg-yellow-100 p-3 text-sm text-yellow-800 dark:border-yellow-700 dark:bg-yellow-900 dark:text-yellow-200">
                    <strong>Aktif:</strong> {{ $activeAnnouncement->message }}
                    <span class="text-xs text-yellow-600 dark:text-yellow-400">(hingga {{ $activeAnnouncement->expires_at?->format('H:i') }})</span>
                </div>
            @endif
        </div>
    @endif

    {{-- PLAYING --}}
    @if ($nowPlaying->isNotEmpty())
        <div class="rounded-xl border border-green-300 bg-green-50 p-4 dark:border-green-700 dark:bg-green-950">
            <h2 class="mb-3 text-lg font-bold text-green-800 dark:text-green-200">Playing</h2>
            <div class="grid gap-3">
                @foreach ($nowPlaying as $schedule)
                    @php
                        $pc = $schedule->participants_count ?? 0;
                        $rp = $schedule->required_participants ?? 1;
                    @endphp
                    <div class="flex items-center justify-between rounded-lg border border-green-200 bg-white p-4 dark:border-green-800 dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $schedule->competitionClass?->competitionCategory?->name }} / {{ $schedule->competitionClass?->name }}</div>
                            <div class="text-sm text-zinc-500">{{ $schedule->venue?->name ?? '-' }} &middot; {{ $schedule->start_at ? \Carbon\Carbon::parse($schedule->start_at)->format('H:i') : '-' }} - {{ $schedule->end_at ? \Carbon\Carbon::parse($schedule->end_at)->format('H:i') : '-' }}</div>
                            <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Peserta: {{ $pc }} / {{ $rp }}</div>
                        </div>
                        <div class="flex gap-2">
                            <flux:button wire:click="advanceStatus({{ $schedule->id }})" size="sm" variant="primary">Selesai</flux:button>
                            <flux:button wire:click="resetStatus({{ $schedule->id }})" size="sm" variant="ghost">Reset</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- READY --}}
    @if ($ready->isNotEmpty())
        <div class="rounded-xl border border-blue-300 bg-blue-50 p-4 dark:border-blue-700 dark:bg-blue-950">
            <h2 class="mb-3 text-lg font-bold text-blue-800 dark:text-blue-200">Ready</h2>
            <div class="grid gap-3">
                @foreach ($ready as $schedule)
                    @php
                        $pc = $schedule->participants_count ?? 0;
                        $rp = $schedule->required_participants ?? 1;
                    @endphp
                    <div class="flex items-center justify-between rounded-lg border border-blue-200 bg-white p-4 dark:border-blue-800 dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $schedule->competitionClass?->competitionCategory?->name }} / {{ $schedule->competitionClass?->name }}</div>
                            <div class="text-sm text-zinc-500">{{ $schedule->venue?->name ?? '-' }} &middot; {{ $schedule->notes ?: '' }}</div>
                            <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Peserta: {{ $pc }} / {{ $rp }}</div>
                            @if ($schedule->is_future)
                                <div class="mt-1 text-xs text-amber-600">⚠️ Jadwal ini belum dimulai.</div>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            @if ($pc >= $rp)
                                <flux:button wire:click="advanceStatus({{ $schedule->id }})" size="sm" variant="primary">Mulai</flux:button>
                            @else
                                <flux:button :href="route('competition.schedule.entries', ['schedule' => $schedule->id], absolute: false)" size="sm" variant="primary">Atur Peserta</flux:button>
                            @endif
                            <flux:button wire:click="resetStatus({{ $schedule->id }})" size="sm" variant="ghost">Reset</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- SCHEDULED --}}
    @if ($scheduled->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-3 text-lg font-bold text-zinc-800 dark:text-zinc-200">Scheduled</h2>
            <div class="grid gap-3">
                @foreach ($scheduled as $schedule)
                    @php
                        $pc = $schedule->participants_count ?? 0;
                        $rp = $schedule->required_participants ?? 1;
                    @endphp
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $schedule->competitionClass?->competitionCategory?->name }} / {{ $schedule->competitionClass?->name }}</div>
                            <div class="text-sm text-zinc-500">{{ $schedule->venue?->name ?? '-' }} &middot; {{ $schedule->start_at ? \Carbon\Carbon::parse($schedule->start_at)->format('d/m/Y H:i') : '-' }}</div>
                            <div class="mt-1 flex items-center gap-2">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' => $pc >= $rp,
                                    'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300' => $pc < $rp,
                                ])>{{ $pc }} / {{ $rp }}</span>
                                @if ($schedule->is_future)
                                    <span class="text-xs text-amber-600">⚠️ Belum dimulai</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex gap-2">
                            @if ($pc >= $rp)
                                <flux:button wire:click="advanceStatus({{ $schedule->id }})" size="sm" variant="primary">Siapkan</flux:button>
                            @else
                                <flux:button :href="route('competition.schedule.entries', ['schedule' => $schedule->id], absolute: false)" size="sm" variant="primary">Atur Peserta</flux:button>
                            @endif
                            <flux:button wire:click="resetStatus({{ $schedule->id }})" size="sm" variant="ghost">Reset</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- FINISHED --}}
    @if ($finished->isNotEmpty())
        <details class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <summary class="cursor-pointer p-4 text-lg font-bold text-zinc-800 dark:text-zinc-200">Finished ({{ $finished->count() }})</summary>
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                <div class="grid gap-3">
                    @foreach ($finished as $schedule)
                        @php
                            $winnerName = $schedule->winner?->participation?->person?->nama ?? '-';
                            $finishedTime = $schedule->finished_at ? \Carbon\Carbon::parse($schedule->finished_at)->format('H:i') : '-';
                        @endphp
                        <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                            <div>
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $schedule->competitionClass?->competitionCategory?->name }} / {{ $schedule->competitionClass?->name }}</div>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>{{ $schedule->venue?->name ?? '-' }}</span>
                                    @if ($schedule->winner_registration_id)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-2 py-0.5 font-medium text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200">🏆 {{ $winnerName }}</span>
                                    @endif
                                    @if ($schedule->finish_reason)
                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 dark:bg-zinc-800">{{ $schedule->finish_reason }}</span>
                                    @endif
                                    <span>Selesai {{ $finishedTime }}</span>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <flux:button :href="route('competition.schedule.outcomes', ['schedule' => $schedule->id], absolute: false)" size="sm" icon-trailing="clipboard-document-list">
                                    {{ $schedule->has_outcome ? 'Lihat Hasil' : 'Input Hasil' }}
                                </flux:button>
                                <flux:button wire:click="resetStatus({{ $schedule->id }})" size="sm" variant="ghost">Reset</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    @endif

    @if ($schedules->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-200 p-10 text-center dark:border-zinc-700">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Belum ada jadwal. Buat jadwal terlebih dahulu.</p>
        </div>
    @endif
</div>
