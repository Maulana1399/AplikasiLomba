<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Eksekusi Lomba</span>
    </div>

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Eksekusi Lomba</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Massal / Individual Scoring / Individual vs Individual / Team vs Team.</p>
        </div>
        @if ($selected)
            <flux:button wire:click="backToList" variant="ghost" icon="arrow-left">Kembali ke Daftar</flux:button>
        @endif
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ session('error') }}</div>
    @endif
    @if (session('info'))
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ session('info') }}</div>
    @endif

    @if ($selected)
        {{-- =============================================================
             DETAIL EKSEKUSI
             ============================================================= --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ $selected->competitionCategory?->name ?? '-' }}
                    </p>
                    <h2 class="mt-1 text-xl font-bold text-zinc-900 dark:text-white">{{ $selected->name }}</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $formatLabel }} &middot; {{ $resultTypeLabel }} &middot; Gender {{ $selected->gender ?? '-' }}
                        @if ($detail['kind'] === 'vs')
                            &middot; Juara {{ $detail['winner_count'] }}
                        @endif
                    </p>
                </div>
            </div>

            @if ($detail['kind'] === 'mass')
                {{-- ============ MASSAL / INDIVIDUAL SCORING ============ --}}
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Peserta / Tim</p>
                        <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $detail['participants'] }}</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Status Jadwal</p>
                        <p class="mt-1 text-2xl font-bold @if ($detail['locked']) text-green-600 @else text-zinc-900 dark:text-white @endif">
                            {{ $detail['has_schedule'] ? $detail['schedule']->status : 'Belum disiapkan' }}
                        </p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Kompetitor Terpasang</p>
                        <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $detail['entries']->count() }}</p>
                    </div>
                </div>

                @if ($detail['locked'])
                    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        Hasil kelas ini sudah difinalisasi (status Finished). Untuk melihat/mengubah detil, buka halaman hasil.
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @if (! $detail['locked'])
                        @if ($detail['has_schedule'])
                            <flux:button :href="route('competition.schedule.outcomes', ['schedule' => $detail['schedule']->id], absolute: false)" variant="primary" icon="pencil-square">Input / Edit Hasil</flux:button>
                        @else
                            <flux:button wire:click="prepareSchedule" variant="primary" icon="play" :loading="$processing">Siapkan Jadwal & Peserta</flux:button>
                        @endif
                    @else
                        <flux:button :href="route('competition.schedule.outcomes', ['schedule' => $detail['schedule']->id], absolute: false)" variant="primary" icon="eye">Lihat Hasil</flux:button>
                    @endif

                    @if ($detail['has_schedule'])
                        <flux:button :href="route('competition.schedule.entries', ['schedule' => $detail['schedule']->id], absolute: false)" variant="ghost" icon="users">Atur Peserta</flux:button>
                    @endif

                    @if ($detail['has_schedule'] && ! $detail['locked'])
                        <flux:button wire:click="resetSchedule" variant="ghost" icon="arrow-path">Reset Jadwal</flux:button>
                    @endif
                </div>

                @if ($detail['entries']->isNotEmpty())
                    <div class="mt-6 overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                            <thead class="bg-zinc-50 dark:bg-zinc-900">
                                <tr class="text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">Peserta / Tim</th>
                                    <th class="px-4 py-3">Detail</th>
                                    <th class="px-4 py-3">Posisi</th>
                                    <th class="px-4 py-3">Skor/Waktu</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($detail['entries'] as $index => $entry)
                                    <tr>
                                        <td class="px-4 py-3 text-zinc-500">{{ $index + 1 }}</td>
                                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $entry['name'] }}</td>
                                        <td class="px-4 py-3 text-zinc-500">{{ $entry['detail'] }}</td>
                                        <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ $entry['position'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ $entry['score'] !== null ? $entry['score'] : '-' }}</td>
                                        <td class="px-4 py-3 text-zinc-500">{{ $entry['status'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @else
                {{-- ============ VS FORMAT ============ --}}
                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Peserta / Tim</p>
                        <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $detail['competitors'] }}</p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Bracket</p>
                        <p class="mt-1 text-2xl font-bold @if ($detail['has_bracket']) text-green-600 @else text-amber-600 @endif">
                            {{ $detail['has_bracket'] ? 'Ada' : 'Belum' }}
                        </p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Juara</p>
                        <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $detail['winner_count'] }}</p>
                    </div>
                </div>

                @if (! $detail['has_bracket'])
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        Kelas vs memakai Bracket. Belum ada bracket aktif untuk kelas ini — buat bracket untuk mulai eksekusi.
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    <flux:button :href="route('competition.bracket-manager', absolute: false)" variant="primary" icon="squares-2x2">Buka Bracket Manager</flux:button>
                    <flux:button :href="route('competition.match-center', absolute: false)" variant="ghost" icon="play">Match Center</flux:button>
                    <flux:button :href="route('competition.official-panel', absolute: false)" variant="ghost" icon="clipboard-document-check">Panel Official</flux:button>
                </div>
            @endif

            @if (! empty($detail['podium']))
                <div class="mt-6">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">Podium / Hasil Akhir</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($detail['podium'] as $podium)
                            <div class="rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 dark:border-yellow-800 dark:bg-yellow-950">
                                <p class="text-xs font-medium text-yellow-700 dark:text-yellow-300">Juara {{ $podium['position'] }}</p>
                                <p class="mt-1 font-semibold text-zinc-900 dark:text-white">
                                    {{ $podium['person_name'] ?? $podium['team_name'] ?? '-' }}
                                </p>
                                <p class="text-xs text-yellow-700 dark:text-yellow-300">
                                    {{ isset($podium['score']) && $podium['score'] !== null ? 'Skor: '.$podium['score'] : '' }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @else
        {{-- =============================================================
             LIST KELAS
             ============================================================= --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <flux:select wire:model.live="filterCategoryId" label="Kategori">
                        <option value="">Semua Kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <div>
                    <flux:select wire:model.live="filterFormat" label="Format">
                        <option value="">Semua Format</option>
                        @foreach ($formats as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                        <th class="px-4 py-3">Kelas Lomba</th>
                        <th class="px-4 py-3">Kategori</th>
                        <th class="px-4 py-3">Format</th>
                        <th class="px-4 py-3">Hasil</th>
                        <th class="px-4 py-3">Peserta/Tim</th>
                        <th class="px-4 py-3">Status Eksekusi</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($classes as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $row['name'] }}
                                <p class="text-xs font-normal text-zinc-400">Gender {{ $row['gender'] }}</p>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $row['category'] }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $row['format'] }}</td>
                            <td class="px-4 py-3 text-zinc-500">{{ $row['result_type'] }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $row['participants'] }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' => $row['execution_status'] === 'Finished' || str_starts_with($row['execution_status'], 'Bracket'),
                                    'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' => $row['execution_status'] === 'Ready' || $row['execution_status'] === 'Bracket belum dibuat',
                                    'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' => in_array($row['execution_status'], ['Scheduled', 'Waiting Result'], true),
                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300' => $row['execution_status'] === 'Playing',
                                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $row['execution_status'] === 'Belum disiapkan',
                                ])>{{ $row['execution_status'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button wire:click="selectClass({{ $row['id'] }})" size="sm" variant="primary">
                                    Eksekusi
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-500">
                                Belum ada kelas lomba pada event ini dengan format eksekusi yang tersedia.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>