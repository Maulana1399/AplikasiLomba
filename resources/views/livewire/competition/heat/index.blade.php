<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Heat Manager</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Atur pembagian peserta ke heat, jumlah lolos, dan progression antar-babak.
            </p>
        </div>
        @if ($selected)
            <div class="flex shrink-0 gap-2">
                <flux:button :href="route('competition.match-center', ['event' => app(\App\Support\ActiveEventContext::class)->current()], absolute: false)" icon="play" variant="ghost">Match Center</flux:button>
                <flux:button wire:click="toggleFormatForm" variant="primary" icon="plus">
                    {{ $showFormatForm ? 'Batal' : 'Buat Format' }}
                </flux:button>
            </div>
        @endif
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

    {{-- Class Selector --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
        <div class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Kelas Heat</div>
        <div class="flex flex-wrap gap-2">
            @forelse ($classes as $class)
                <flux:button
                    wire:click="selectClass({{ $class->id }})"
                    variant="{{ $selected && $selected->id === $class->id ? 'primary' : 'outline' }}"
                    size="sm"
                >
                    {{ $class->name }}
                    @if ($selected && $selected->id === $class->id)
                        <flux:icon.check class="size-3!" />
                    @endif
                </flux:button>
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Belum ada kelas dengan format {{ implode(' / ', App\Support\CompetitionFormat::ALL) }} yang aktif.
                </p>
            @endforelse
        </div>
    </div>

    @if ($selected)
        {{-- Class summary --}}
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Peserta Terdaftar</div>
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
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Peserta per Heat</label>
                        <flux:input wire:model="formatParticipants" type="number" min="1" max="99" placeholder="7" />
                        @error('formatParticipants') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Minimum Peserta Untuk Start</label>
                        <flux:input wire:model="formatMinParticipants" type="number" min="1" max="99" placeholder="2" />
                        @error('formatMinParticipants') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Lolos per Heat</label>
                        <flux:input wire:model="formatQualifiers" type="number" min="1" max="99" placeholder="3" />
                        @error('formatQualifiers') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    Contoh: 28 peserta, 7 per heat, 3 lolos → 4 heat, 12 qualifier keseluruhan.
                    Jumlah lolos tidak boleh melebihi peserta per heat. Minimum peserta untuk start adalah syarat agar heat bisa dimainkan tanpa harus penuh (default 2).
                </p>
                <div class="mt-4 flex justify-end">
                    <flux:button wire:click="createFormat" variant="primary" icon="plus">Simpan Format Round {{ $formatRound }}</flux:button>
                </div>
            </div>
        @endif

        {{-- Rounds --}}
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
                                    $event = app(\App\Support\ActiveEventContext::class)->current();
                                @endphp
                                <div class="mt-auto flex flex-wrap gap-1.5 pt-2">
                                    <flux:button :href="route('competition.schedule.entries', ['event' => $event, 'schedule' => $card['id']], absolute: false)" size="xs" icon="users" class="flex-1 whitespace-nowrap">Peserta</flux:button>
                                    <flux:button :href="route('competition.match-center', ['event' => $event], absolute: false)" size="xs" icon="play" variant="primary" class="flex-1 whitespace-nowrap">Match Center</flux:button>
                                    <flux:button :href="route('competition.schedule.outcomes', ['event' => $event, 'schedule' => $card['id']], absolute: false)" size="xs" icon="clipboard-document-list" class="flex-1 whitespace-nowrap">Input Hasil</flux:button>
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
</div>