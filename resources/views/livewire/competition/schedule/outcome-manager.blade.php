<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">{{ $isHeat ? 'Heat' : 'Outcome' }}</span>
    </div>

    <div>
        <div class="flex items-center gap-2">
            <flux:button :href="route('competition.execution.index', absolute: false)" variant="ghost" icon="arrow-left" size="sm">Kembali</flux:button>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $categoryName }} / {{ $className }}</h1>
        </div>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Venue: {{ $venueName }} &middot; Input hasil peserta
            &middot; Result type: <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ \App\Support\CompetitionResultType::label($resultType) }}</span>
            @if ($canAutoRank)
                &middot; Auto-rank: {{ $resultDirection === 'asc' ? 'terkecil dahulu (waktu/finish)' : 'terbesar dahulu (skor)' }}
            @endif
            @if ($isHeat)
                &middot; <span @class(['font-medium text-zinc-800 dark:text-zinc-200'])>{{ \App\Support\CompetitionResultType::label($resultType) }}</span>
                @if ($resultType === \App\Support\CompetitionResultType::TIME)
                    &middot; Format waktu: <span class="font-mono text-zinc-700 dark:text-zinc-300">1:32.500</span>
                @endif
            @endif
        </p>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    @if (! empty($podium))
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($podium as $entry)
                <div class="rounded-xl border p-4 text-center {{ $entry['position'] === 1 ? 'border-amber-300 bg-amber-50 dark:border-amber-500 dark:bg-amber-950' : ($entry['position'] === 2 ? 'border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900' : 'border-orange-200 bg-orange-50/60 dark:border-orange-700 dark:bg-orange-950') }}">
                    <p class="text-xs font-semibold uppercase tracking-wide {{ $entry['position'] === 1 ? 'text-amber-700 dark:text-amber-300' : ($entry['position'] === 2 ? 'text-zinc-600 dark:text-zinc-300' : 'text-orange-700 dark:text-orange-300') }}">
                        Juara {{ $entry['position'] }}
                    </p>
                    <p class="mt-1 truncate font-semibold text-zinc-900 dark:text-white">{{ $entry['person_name'] ?? $entry['team_name'] ?? '-' }}</p>
                    @if (! empty($entry['participant_number']))
                        <p class="text-xs text-zinc-600 dark:text-zinc-300">{{ $entry['participant_number'] }}</p>
                    @endif
                    @if (array_key_exists('score', $entry) && $entry['score'] !== null)
                        <p class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                            {{ $isHeat ? 'Waktu: '. \App\Support\CompetitionTime::format($entry['score']) : 'Skor: '.$entry['score'] }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($isTeam && ! $isTeamHeat)
        {{-- Team results (Team Mass / team competitor) --}}
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">No</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Team</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Posisi</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Skor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($teamOutcomes as $index => $row)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $loop->iteration }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">{{ $row['team_name'] }}</td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="teamOutcomes.{{ $index }}.position" type="number" min="0" size="sm" placeholder="-" class="w-16" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:select wire:model="teamOutcomes.{{ $index }}.status" size="sm" class="w-28">
                                    <flux:select.option value="">--</flux:select.option>
                                    <flux:select.option value="Lolos">Lolos</flux:select.option>
                                    <flux:select.option value="Gugur">Gugur</flux:select.option>
                                    <flux:select.option value="Diskualifikasi">Diskualifikasi</flux:select.option>
                                    <flux:select.option value="Tidak Hadir">Tidak Hadir</flux:select.option>
                                </flux:select>
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="teamOutcomes.{{ $index }}.score" type="number" step="0.01" min="0" size="sm" placeholder="-" class="w-20" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="teamOutcomes.{{ $index }}.remarks" size="sm" placeholder="-" class="w-32" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Belum ada team terdaftar di jadwal ini. Tambahkan team lewat "Atur Peserta".</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (!empty($teamOutcomes))
            <div class="flex justify-end gap-2">
                @if ($canAutoRank)
                    <flux:button wire:click="autoRank" variant="filled">
                        Rank Otomatis
                    </flux:button>
                @endif
                <flux:button wire:click="saveOutcomes" variant="primary">
                    Simpan Hasil Team
                </flux:button>
            </div>
        @endif
    @elseif ($isHeat)
        {{-- Heat results: per (heat, participant/team) --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-950">
            <span class="font-semibold text-zinc-900 dark:text-white">Babak (Round) {{ $round }}</span>
            <span class="ml-2 text-zinc-500 dark:text-zinc-400">
                {{ $isTeamHeat ? 'Team Heat' : 'Individual Heat' }} · per-heat ranking + top-N advancement
            </span>
        </div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">No</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $isTeamHeat ? 'Team' : 'Nama' }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">No. Peserta</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            @if ($resultType === \App\Support\CompetitionResultType::TIME)
                                Waktu (M:SS.mmm)
                            @else
                                {{ \App\Support\CompetitionResultType::label($resultType) }}
                            @endif
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Catatan</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Posisi Final</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($heatResults as $index => $row)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $loop->iteration }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">{{ $row['person_name'] ?? $row['team_name'] ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $row['participant_number'] ?? ($isTeamHeat ? 'Team' : '-') }}</td>
                            <td class="px-4 py-2">
                                @if ($resultType === \App\Support\CompetitionResultType::TIME)
                                    <flux:input wire:model="heatResults.{{ $index }}.timeText" size="sm" placeholder="1:32.500" class="w-28" />
                                @else
                                    <flux:input wire:model="heatResults.{{ $index }}.scoreValue" type="number" step="0.01" min="0" size="sm" placeholder="0" class="w-24" />
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <flux:select wire:model="heatResults.{{ $index }}.status" size="sm" class="w-28">
                                    <flux:select.option value="">--</flux:select.option>
                                    <flux:select.option value="Lolos">Lolos</flux:select.option>
                                    <flux:select.option value="Gugur">Gugur</flux:select.option>
                                    <flux:select.option value="Diskualifikasi">Diskualifikasi</flux:select.option>
                                    <flux:select.option value="Tidak Hadir">Tidak Hadir</flux:select.option>
                                </flux:select>
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="heatResults.{{ $index }}.notes" size="sm" placeholder="-" class="w-28" />
                            </td>
                            <td class="px-4 py-3 text-sm font-semibold text-zinc-900 dark:text-white">{{ $row['final_position'] ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Belum ada peserta terdaftar di heat ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (!empty($heatResults))
            <div class="flex flex-wrap justify-end gap-2">
                <flux:button wire:click="saveOutcomes" variant="filled">
                    Simpan Hasil Heat
                </flux:button>
                <flux:button wire:click="rankHeat" variant="filled">
                    Rank Heat Ini
                </flux:button>
                @if ($formatTopN !== null)
                    <flux:button wire:click="advanceHeat" variant="filled">
                        Advance Top {{ $formatTopN }}
                    </flux:button>
                @endif
                <flux:button wire:click="finalizeHeatFinal" variant="primary">
                    Finalize Podium
                </flux:button>
                <flux:button wire:click="aggregateFinal" variant="ghost">
                    Generate Final Ranking
                </flux:button>
            </div>
        @endif
    @else
        {{-- Mass / score outcome --}}
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">No</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Nama</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">No. Peserta</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Desa</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Posisi</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Skor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($outcomes as $index => $outcome)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $loop->iteration }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">{{ $outcome['person_name'] }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $outcome['participant_number'] }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $outcome['desa'] }} / {{ $outcome['kelompok'] }}</td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="outcomes.{{ $index }}.position" type="number" min="0" size="sm" placeholder="-" class="w-16" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:select wire:model="outcomes.{{ $index }}.status" size="sm" class="w-28">
                                    <flux:select.option value="">--</flux:select.option>
                                    <flux:select.option value="Lolos">Lolos</flux:select.option>
                                    <flux:select.option value="Gugur">Gugur</flux:select.option>
                                    <flux:select.option value="Diskualifikasi">Diskualifikasi</flux:select.option>
                                    <flux:select.option value="Tidak Hadir">Tidak Hadir</flux:select.option>
                                </flux:select>
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="outcomes.{{ $index }}.score" type="number" step="0.01" min="0" size="sm" placeholder="-" class="w-20" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="outcomes.{{ $index }}.remarks" size="sm" placeholder="-" class="w-32" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Belum ada peserta terdaftar di kelas ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (!empty($outcomes))
            <div class="flex justify-end gap-2">
                @if ($canAutoRank)
                    <flux:button wire:click="autoRank" variant="filled">
                        Rank Otomatis
                    </flux:button>
                @endif
                <flux:button wire:click="saveOutcomes" variant="primary">
                    Simpan Outcome
                </flux:button>
            </div>
        @endif
    @endif
</div>
