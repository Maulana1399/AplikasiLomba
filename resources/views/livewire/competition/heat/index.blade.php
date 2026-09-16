<div class="space-y-6">
    {{-- Flash messages --}}
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

    @if ($selected)
        {{-- ============================================================ --}}
        {{-- LEVEL 2: DETAIL HEAT (setelah klik Buka Heat)              --}}
        {{-- ============================================================ --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <flux:button wire:click="backToList" variant="ghost" icon="arrow-left" size="sm">Kembali</flux:button>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $selected->name }}</h1>
                </div>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($isTeamHeat)
                        Kelola pembagian Team ke heat: pilih Team dari daftar tersedia, lalu masukkan ke heat. Roster Team dikelola di Pembagian Tim.
                    @else
                        Atur pembagian peserta ke heat, jumlah lolos, dan progression antar-babak.
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 gap-2">
                <flux:button wire:click="toggleFormatForm" variant="primary" icon="plus">
                    {{ $showFormatForm ? 'Batal' : 'Buat Format' }}
                </flux:button>
            </div>
        </div>

        {{-- Class summary --}}
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $isTeamHeat ? 'Team Terdaftar' : 'Peserta Terdaftar' }}</div>
                <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $poolCount }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Result Type</div>
                <div class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ $resultTypeLabel }}</div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $resultDirection }}</div>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Format Terpasang</div>
                <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $formats->count() }}</div>
                <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">round terbanyak: {{ $formats->max('round') ?? 0 }}</div>
            </div>
        </div>

        {{-- Format form --}}
        @if ($showFormatForm)
            <div class="rounded-xl border border-blue-200 bg-blue-50/40 p-6 dark:border-blue-800 dark:bg-blue-950/20">
                <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">
                    Format Heat Baru — {{ $selected->name }}
                </h2>
                <div class="grid gap-4 sm:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Round</label>
                        <flux:input wire:model="formatRound" type="number" min="1" placeholder="1" />
                        @error('formatRound') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $isTeamHeat ? 'Tim per Heat' : 'Peserta per Heat' }}</label>
                        <flux:input wire:model="formatParticipants" type="number" min="1" max="99" placeholder="{{ $isTeamHeat ? '4' : '7' }}" />
                        @error('formatParticipants') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $isTeamHeat ? 'Minimum Tim Untuk Start' : 'Minimum Peserta Untuk Start' }}</label>
                        <flux:input wire:model="formatMinParticipants" type="number" min="1" max="99" placeholder="2" />
                        @error('formatMinParticipants') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $isTeamHeat ? 'Tim Lolos per Heat' : 'Lolos per Heat' }}</label>
                        <flux:input wire:model="formatQualifiers" type="number" min="1" max="99" placeholder="{{ $isTeamHeat ? '2' : '3' }}" />
                        @error('formatQualifiers') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($isTeamHeat)
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                        Contoh: 16 tim, 4 tim per heat, 2 tim lolos → 4 heat, 8 tim lolos.
                        Jumlah tim lolos tidak boleh melebihi tim per heat. Minimum tim untuk start adalah syarat agar heat bisa dimainkan tanpa harus penuh (default 2).
                    </p>
                @else
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                        Contoh: 28 peserta, 7 per heat, 3 lolos → 4 heat, 12 qualifier keseluruhan.
                        Jumlah lolos tidak boleh melebihi peserta per heat. Minimum peserta untuk start adalah syarat agar heat bisa dimainkan tanpa harus penuh (default 2).
                    </p>
                @endif
                <div class="mt-4 flex justify-end">
                    <flux:button wire:click="createFormat" variant="primary" icon="plus">Simpan Format Round {{ $formatRound }}</flux:button>
                </div>
            </div>
        @endif

        {{-- Rounds --}}
        @if ($isTeamHeat)
            @php
                $currentFormat = $formats->first(fn ($f) => (int) $f->round === (int) $teamRound);
                $event = $selected->event;
            @endphp

            @if ($currentFormat === null)
                <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Belum ada format heat untuk <strong>{{ $selected->name }}</strong>. Klik <strong>Buat Format</strong> untuk mulai.
                    </p>
                </div>
            @else
                {{-- Round switcher + aksi round --}}
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-zinc-600 dark:text-zinc-300">Round:</span>
                        @foreach ($formats as $fmt)
                            <flux:button wire:click="$set('teamRound', {{ $fmt->round }})" size="sm"
                                variant="{{ (int) $fmt->round === (int) $teamRound ? 'primary' : 'ghost' }}">
                                R{{ $fmt->round }}
                            </flux:button>
                        @endforeach
                        <span class="ml-2 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $currentFormat->participants_per_heat }} team/heat &middot; Top {{ $currentFormat->qualifiers_per_heat }} lolos &middot; min start {{ $currentFormat->min_participants_to_start }}
                            @if ($heatCountForRound !== null)
                                &middot; ±{{ $heatCountForRound }} heat
                            @endif
                        </span>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if ($teamHeats->isEmpty())
                            <flux:button wire:click="generateRound({{ $teamRound }})" size="sm" variant="primary" icon="plus">Generate Heat</flux:button>
                        @else
                            @if ($teamNeedsRebuild)
                                <flux:button wire:click="rebuildRound({{ $teamRound }})" size="sm" variant="danger" icon="arrow-path" class="whitespace-nowrap"
                                    wire:confirm="Bangun ulang heat Round {{ $teamRound }} dari format terbaru? Heat lama yang belum dimainkan akan diganti (hasil/outcome tetap dilindungi).">
                                    Generate Ulang Babak Ini
                                </flux:button>
                            @endif
                            <flux:button wire:click="removeRound({{ $teamRound }})" size="sm" variant="danger" icon="trash" class="whitespace-nowrap">Hapus Heat Babak Ini</flux:button>
                        @endif
                        <flux:button wire:click="deleteFormat({{ $currentFormat->id }})" size="sm" variant="ghost" icon="x-mark" class="whitespace-nowrap">Hapus Format</flux:button>
                    </div>
                </div>

                @if ($teamNeedsRebuild)
                    <div class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        Susunan Heat yang ada tidak sesuai format saat ini
                        ({{ $currentFormat->participants_per_heat }} tim/heat untuk {{ $activeTeamsCount }} tim). Klik
                        <strong>Generate Ulang Babak Ini</strong> agar jumlah heat dihitung ulang dari jumlah Team
                        ({{ $heatCountForRound ?? '—' }} heat). Heat dibangun ulang kosong, lalu Team didistribusikan ulang.
                        Heat yang sudah dimainkan atau punya hasil tidak akan disentuh.
                    </div>
                @endif

                {{-- Team tersedia --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Team Tersedia</h2>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $availableTeams->count() }} dari {{ $activeTeamsCount }} team aktif belum masuk heat round ini.
                                Pilih team lalu tentukan heat tujuan, atau gunakan Distribusi Otomatis.
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <flux:input wire:model.live="teamSearch" placeholder="Cari team…" class="w-56" />
                            <flux:button wire:click="autoAssignTeams({{ $teamRound }})" size="sm" variant="ghost" icon="arrow-path">Auto Distribusi</flux:button>
                        </div>
                    </div>

                    @if ($availableTeams->isEmpty())
                        <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                @if ($teamHeats->isEmpty())
                                    Belum ada Team yang akan dimasukkan ke heat round ini.
                                @else
                                    Semua team sudah masuk ke heat round ini.
                                @endif
                            </p>
                        </div>
                    @else
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($availableTeams as $team)
                                @if ($teamSearch !== '' && ! Str::contains(strtolower($team['name']), strtolower($teamSearch)))
                                    @continue
                                @endif
                                <div class="flex flex-col rounded-lg border border-zinc-200 bg-zinc-50/60 p-3 dark:border-zinc-700 dark:bg-zinc-900/40">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="truncate font-semibold text-zinc-900 dark:text-white">{{ $team['name'] }}</div>
                                            <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $team['kelompok'] ?? '—' }}</div>
                                        </div>
                                    </div>
                                    <details class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        <summary class="cursor-pointer">Anggota ({{ $team['players']->count() }} main + {{ $team['substitutes']->count() }} cadangan)</summary>
                                        <ul class="mt-1 list-inside list-disc space-y-0.5">
                                            @foreach ($team['players'] as $member)
                                                <li>{{ $member }}</li>
                                            @endforeach
                                            @if ($team['substitutes']->isNotEmpty())
                                                <li class="text-zinc-400">Cadangan: {{ $team['substitutes']->join(', ') }}</li>
                                            @endif
                                        </ul>
                                    </details>
                                    <div class="mt-3 flex items-end gap-2">
                                        <flux:select wire:model="assignTargets.{{ $team['id'] }}" size="sm" class="flex-1">
                                            <flux:select.option value="0">Pilih Heat…</flux:select.option>
                                            @foreach ($assignableHeats as $ah)
                                                <flux:select.option value="{{ $ah['index'] }}">{{ $ah['label'] }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:button wire:click="assignTeam({{ $teamRound }}, {{ $team['id'] }})" size="sm" variant="primary" icon="plus">Masukkan ke Heat</flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Heat cards round berjalan --}}
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-bold text-zinc-900 dark:text-white">Heat — Round {{ $teamRound }}</h2>
                    </div>

                    @if ($teamHeats->isEmpty())
                        <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Belum ada heat. Klik <strong>Generate Heat</strong> untuk membuat {{ $heatCountForRound ?? '—' }} heat round ini, lalu masukkan Team.
                            </p>
                        </div>
                    @else
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($teamHeats as $heat)
                                <div @class([
                                    'flex flex-col rounded-xl border-2 bg-white p-4 dark:bg-zinc-950',
                                    'border-zinc-200 dark:border-zinc-800' => $heat['status'] === 'Scheduled',
                                    'border-blue-300 dark:border-blue-700' => $heat['status'] === 'Ready',
                                    'border-green-400 dark:border-green-600' => $heat['status'] === 'Playing',
                                    'border-yellow-300 dark:border-yellow-600' => $heat['status'] === 'Waiting Result',
                                    'border-zinc-400 dark:border-zinc-600' => $heat['status'] === 'Finished',
                                ])>
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <div>
                                            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Round {{ $teamRound }} &middot; Heat {{ $heat['heat_index'] }}</div>
                                            <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ $heat['heat_label'] }}</h3>
                                        </div>
                                        <span @class([
                                            'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                            'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200' => $heat['status'] === 'Scheduled',
                                            'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' => $heat['status'] === 'Ready',
                                            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $heat['status'] === 'Playing',
                                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' => $heat['status'] === 'Waiting Result',
                                            'bg-zinc-800 text-white dark:bg-black dark:text-zinc-300' => $heat['status'] === 'Finished',
                                        ])>
                                            {{ $heat['status'] }}
                                        </span>
                                    </div>

                                    <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $heat['participants_count'] }} / {{ $heat['required_participants'] }} team
                                        &middot; Top {{ $currentFormat->qualifiers_per_heat }}
                                        &middot; min start {{ $currentFormat->min_participants_to_start }}
                                    </p>

                                    <ul class="mb-3 space-y-1">
                                        @forelse ($heat['entries'] as $entry)
                                            <li class="rounded-md bg-zinc-50 px-2.5 py-1.5 text-sm dark:bg-zinc-900">
                                                <div class="flex items-center justify-between gap-2">
                                                    <div class="min-w-0">
                                                        <div class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $entry['name'] }}</div>
                                                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $entry['kelompok'] ?? '—' }}</div>
                                                    </div>
                                                    <div class="flex shrink-0 items-center gap-1.5">
                                                        @if ($entry['position'])
                                                            <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">P{{ $entry['position'] }}</span>
                                                        @endif
                                                        @if ($entry['status'])
                                                            <span class="rounded-full bg-zinc-200/70 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $entry['status'] }}</span>
                                                        @endif
                                                    </div>
                                                </div>
                                                @php
                                                    $canEditHeat = ! in_array($heat['status'], ['Playing', 'Waiting Result', 'Finished'], true);
                                                @endphp
                                                @if ($canEditHeat)
                                                    <div class="mt-1.5 flex items-center gap-1.5">
                                                        <flux:select wire:model="moveTargets.{{ $entry['team_id'] }}" size="xs" class="flex-1">
                                                            <flux:select.option value="0">Pilih Heat…</flux:select.option>
                                                            @foreach ($assignableHeats as $ah)
                                                                <flux:select.option value="{{ $ah['index'] }}">{{ $ah['label'] }}</flux:select.option>
                                                            @endforeach
                                                        </flux:select>
                                                        <flux:button wire:click="moveTeam({{ $teamRound }}, {{ $heat['heat_index'] }}, {{ $entry['team_id'] }})" size="xs" icon="arrow-right-circle">Pindah</flux:button>
                                                        <flux:button wire:click="removeTeam({{ $teamRound }}, {{ $heat['heat_index'] }}, {{ $entry['team_id'] }})" size="xs" variant="danger" icon="x-mark">Keluarkan</flux:button>
                                                    </div>
                                                @endif
                                            </li>
                                        @empty
                                            <li class="text-xs text-zinc-400 dark:text-zinc-500">Belum ada team.</li>
                                        @endforelse
                                    </ul>

                                    <div class="mt-auto flex flex-wrap gap-1.5 pt-2">
                                        <flux:button :href="route('competition.schedule.entries', ['event' => $event, 'schedule' => $heat['id']], absolute: false)" size="xs" icon="users" class="flex-1 whitespace-nowrap">Team</flux:button>
                                        <flux:button :href="route('competition.schedule.outcomes', ['event' => $event, 'schedule' => $heat['id']], absolute: false)" size="xs" icon="clipboard-document-list" variant="primary" class="flex-1 whitespace-nowrap">Input Hasil</flux:button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

        @else
            @forelse ($rounds as $round)
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-zinc-900 dark:text-white">ROUND {{ $round['round'] }}</h2>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $round['format']->participants_per_heat }} peserta/heat &middot; Top {{ $round['format']->qualifiers_per_heat }} lolos &middot; min start {{ $round['format']->min_participants_to_start }}
                            @if ($round['estimated_heat_count'] !== null)
                                &middot; ±{{ $round['estimated_heat_count'] }} heat
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($round['schedules']->isEmpty())
                            <flux:button wire:click="generateRound({{ $round['round'] }})" size="sm" variant="primary" icon="plus">Generate Heat</flux:button>
                        @else
                            <flux:button wire:click="advanceRound({{ $round['round'] }})" size="sm" variant="primary" icon="play" class="whitespace-nowrap">
                                Generate Round Berikutnya
                            </flux:button>
                            @if ($round['needs_rebuild'])
                                <flux:button wire:click="rebuildRound({{ $round['round'] }})" size="sm" variant="danger" icon="arrow-path" class="whitespace-nowrap">
                                    Generate Ulang Babak Ini
                                </flux:button>
                            @endif
                            <flux:button wire:click="removeRound({{ $round['round'] }})" size="sm" variant="danger" icon="trash" class="whitespace-nowrap">Hapus Heat Babak Ini</flux:button>
                        @endif
                        <flux:button wire:click="deleteFormat({{ $round['format']->id }})" size="sm" variant="ghost" icon="x-mark" class="whitespace-nowrap">Hapus Format</flux:button>
                    </div>
                </div>

                @if ($round['needs_rebuild'])
                    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        Heat yang ada tidak sesuai format: kapasitasnya bukan {{ $round['format']->participants_per_heat }} peserta/heat. Klik
                        <strong>Generate Ulang Babak Ini</strong> agar heat dibangun ulang dari format ({{ $round['format']->participants_per_heat }} peserta/heat, Top {{ $round['format']->qualifiers_per_heat }} lolos). Data hasil akan dilindungi — round yang sudah dimulai atau punya hasil tidak akan disentuh.
                    </div>
                @endif

                @if ($round['schedules']->isEmpty())
                    <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Belum ada heat. Klik <strong>Generate Heat</strong> untuk membagi peserta ke {{ $round['estimated_heat_count'] ?? '—' }} heat.
                        </p>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($round['schedules'] as $card)
                            @php
                                $heatIndex = max(1, $card['sort_order'] - ($round['round'] * 100));
                                $heatLetter = str_pad((string) $heatIndex, 2, '0', STR_PAD_LEFT);
                            @endphp
                            <div @class([
                                'flex flex-col rounded-xl border-2 bg-white p-4 dark:bg-zinc-950',
                                'border-zinc-200 dark:border-zinc-800' => $card['status'] === 'Scheduled',
                                'border-blue-300 dark:border-blue-700' => $card['status'] === 'Ready',
                                'border-green-400 dark:border-green-600' => $card['status'] === 'Playing',
                                'border-yellow-300 dark:border-yellow-600' => $card['status'] === 'Waiting Result',
                                'border-zinc-400 dark:border-zinc-600' => $card['status'] === 'Finished',
                            ])>
                                <div class="mb-2 flex items-start justify-between gap-2">
                                    <div>
                                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            Round {{ $round['round'] }} &middot; Heat {{ $heatLetter }}
                                        </div>
                                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Heat {{ $heatLetter }}</h3>
                                    </div>
                                    <span @class([
                                        'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200' => $card['status'] === 'Scheduled',
                                        'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' => $card['status'] === 'Ready',
                                        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $card['status'] === 'Playing',
                                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' => $card['status'] === 'Waiting Result',
                                        'bg-zinc-800 text-white dark:bg-black dark:text-zinc-300' => $card['status'] === 'Finished',
                                    ])>
                                        {{ $card['status'] }}
                                    </span>
                                </div>

                                <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $card['participants_count'] }} / {{ $card['required_participants'] }} peserta
                                    &middot; Top {{ $round['format']->qualifiers_per_heat }}
                                    &middot; min start {{ $round['format']->min_participants_to_start }}
                                </p>

                                <ul class="mb-3 space-y-1">
                                    @forelse ($card['entries'] as $entry)
                                        <li class="flex items-center justify-between gap-2 rounded-md bg-zinc-50 px-2.5 py-1 text-sm dark:bg-zinc-900">
                                            <span class="truncate text-zinc-800 dark:text-zinc-200">{{ $entry['number'] }} &middot; {{ $entry['name'] }}</span>
                                            <span class="flex shrink-0 items-center gap-2">
                                                @if ($entry['position'])
                                                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">P{{ $entry['position'] }}</span>
                                                @endif
                                                @if ($entry['status'])
                                                    <span class="rounded-full bg-zinc-200/70 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $entry['status'] }}</span>
                                                @endif
                                            </span>
                                        </li>
                                    @empty
                                        <li class="text-xs text-zinc-400 dark:text-zinc-500">Belum ada peserta.</li>
                                    @endforelse
                                </ul>

                                @php
                                    $event = $selected->event;
                                @endphp
                                <div class="mt-auto flex flex-wrap gap-1.5 pt-2">
                                    <flux:button :href="route('competition.schedule.entries', ['event' => $event, 'schedule' => $card['id']], absolute: false)" size="xs" icon="users" class="flex-1 whitespace-nowrap">Peserta</flux:button>
                                    <flux:button :href="route('competition.schedule.outcomes', ['event' => $event, 'schedule' => $card['id']], absolute: false)" size="xs" icon="clipboard-document-list" variant="primary" class="flex-1 whitespace-nowrap">Input Hasil</flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Belum ada format heat untuk <strong>{{ $selected->name }}</strong>. Klik <strong>Buat Format</strong> untuk mulai.
                </p>
            </div>
        @endforelse
        @endif

    @else
        {{-- ============================================================ --}}
        {{-- LEVEL 1: LISTING KELAS HEAT (filter + card grid)           --}}
        {{-- ============================================================ --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Heat Manager</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Pilih kelas untuk mengelola pembagian peserta ke heat.
                </p>
            </div>
        </div>

        {{-- Filter bar --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kategori</label>
                    <flux:select wire:model.live="categoryId">
                        <flux:select.option value="">Semua Kategori</flux:select.option>
                        @foreach ($categories as $cat)
                            <flux:select.option value="{{ $cat->id }}">{{ $cat->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="flex-1">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                    <flux:select wire:model.live="statusFilter">
                        @foreach ($statusLabels as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        </div>

        {{-- Class cards --}}
        @forelse ($classes as $class)
            @php
                $cat = $class->competitionCategory;
            @endphp
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $class->name }}</h2>
                            @if (! $class->is_active)
                                <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Non Aktif</span>
                            @endif
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($cat)
                                <span>Kategori: {{ $cat->name }}</span>
                            @endif
                            @if ($class->gender)
                                <span>Gender: {{ $class->gender }}</span>
                            @endif
                            <span>Format: {{ App\Support\CompetitionFormat::label($class->format) }}</span>
                            <span>Result: {{ App\Support\CompetitionResultType::label($class->resultType()) }}</span>
                        </div>
                    </div>
                    <div class="shrink-0">
                        <flux:button wire:click="selectClass({{ $class->id }})" variant="primary" icon="play">
                            Buka Heat
                        </flux:button>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Belum ada kelas dengan format Heat yang tersedia.
                </p>
            </div>
        @endforelse
    @endif
</div>
