<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Pembagian Tim</span>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Pembagian Tim</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Dua mode: <span class="font-medium">Berdasarkan Kelompok</span> (1 kelompok = 1 tim) atau
                <span class="font-medium">Random &amp; Balanced</span> (seimbang tim, kelas, dan gender).
            </p>
        </div>

        @if ($selectedClass)
            <div class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <span class="text-zinc-500">Format:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $formatLabel }}</span>
                <span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-200">team</span>
            </div>
        @endif
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error') || $errors->has('formationMode'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            {{ session('error') ?? $errors->first('formationMode') }}
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kategori Lomba</label>
            <flux:select wire:model.live="competitionCategoryId" placeholder="Pilih kategori">
                @foreach ($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelas</label>
            <flux:select wire:model.live="competitionClassId" placeholder="Pilih kelas">
                @foreach ($classes as $class)
                    <flux:select.option value="{{ $class->id }}">{{ $class->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($selectedClass)
                <p class="mt-1 text-xs text-zinc-400">Ukuran tim tersimpan di Settings kelas: {{ $selectedClass->team_size ?? 'belum diatur (default: kelompok terkecil)' }}</p>
            @endif
        </div>
    </div>

    @if ($selectedClass)
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Mode Pembagian</label>
                    <flux:select wire:model.live="formationMode">
                        <flux:select.option value="group">Berdasarkan Kelompok</flux:select.option>
                        <flux:select.option value="balanced">Random &amp; Balanced</flux:select.option>
                    </flux:select>
                    <p class="mt-1 text-xs text-zinc-400">
                        @if ($formationMode === 'group')
                            1 kelompok = 1 tim. Ukuran default: {{ $defaultTeamSize ?? 'kelompok terkecil' }} pemain.
                        @else
                            Campur peserta lintas kelompok, seimbang ukuran/kelas/gender. Ukuran tim wajib diisi bila belum ada di Settings.
                        @endif
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ukuran Tim (override)</label>
                    <flux:input wire:model="teamSizeInput" type="number" min="1" max="100" placeholder="{{ $formationMode === 'balanced' ? 'contoh: 5' : 'kosong = default' }}" />
                    @error('teamSizeInput') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-end gap-2">
                    <flux:button wire:click="previewFormation" variant="outline" :loading="$processing">Preview</flux:button>
                    @if ($isFormed)
                        <flux:button wire:click="generateFormation" variant="filled" :loading="$processing"
                            wire:confirm="Tim sudah dibentuk. Membentuk ulang akan mengganti pembagian. Lanjutkan?">
                            Bentuk Ulang Tim
                        </flux:button>
                    @else
                        <flux:button wire:click="generateFormation" variant="primary" :loading="$processing">
                            Bentuk Tim
                        </flux:button>
                    @endif
                </div>
            </div>
        </div>

        @if ($showPreview && !empty($previewBreakdown))
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-zinc-900 dark:border-blue-800 dark:bg-blue-950/70 dark:text-zinc-100">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-blue-900 dark:text-blue-200">
                        Preview Pembagian — Ukuran Tim {{ $previewBreakdown['team_size'] }} pemain
                        @if ($previewBreakdown['mode'] === 'balanced')
                            · {{ $previewBreakdown['team_count'] }} tim
                        @endif
                    </h2>
                    <flux:button size="xs" variant="ghost" wire:click="clearPreview">tutup preview</flux:button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-blue-700 dark:text-blue-300">
                                @if ($previewBreakdown['mode'] === 'group')
                                    <th class="px-2 py-1">Kelompok</th>
                                @else
                                    <th class="px-2 py-1">Tim</th>
                                @endif
                                <th class="px-2 py-1">Pemain</th>
                                <th class="px-2 py-1">Cadangan</th>
                                @if ($previewBreakdown['mode'] === 'balanced')
                                    <th class="px-2 py-1">Kelas</th>
                                    <th class="px-2 py-1">L/P</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($previewBreakdown['teams'] as $row)
                                <tr class="border-t border-blue-100 dark:border-blue-900">
                                    @if ($previewBreakdown['mode'] === 'group')
                                        <td class="px-2 py-1.5 font-medium">{{ $row['kelompok'] }} <span class="text-xs text-blue-600 dark:text-blue-400">({{ $row['participants'] }} peserta)</span></td>
                                    @else
                                        <td class="px-2 py-1.5 font-medium">{{ $row['name'] }}</td>
                                    @endif
                                    <td class="px-2 py-1.5">{{ $row['players'] }}</td>
                                    <td class="px-2 py-1.5">{{ $row['substitutes'] }}</td>
                                    @if ($previewBreakdown['mode'] === 'balanced')
                                        <td class="px-2 py-1.5 text-xs text-zinc-600 dark:text-zinc-300">
                                            @foreach ($row['kelas'] as $kelas => $count)
                                                {{ $kelas }}:{{ $count }}@if (! $loop->last) · @endif
                                            @endforeach
                                        </td>
                                        <td class="px-2 py-1.5 text-xs">{{ $row['laki'] }}L / {{ $row['perempuan'] }}P</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-blue-700 dark:text-blue-300">
                    Hasil final akan diacak ulang saat tombol "Bentuk Tim"/"Bentuk Ulang Tim" ditekan.
                </p>
            </div>
        @endif

        @if ($isFormed)
            <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-950">
                <span class="text-zinc-500">Total Team:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $summary['total_teams'] }}</span>
                <span class="mx-2 text-zinc-300 dark:text-zinc-700">·</span>
                <span class="text-zinc-500 dark:text-zinc-400">Team Size:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $summary['team_size'] ?? '-' }} pemain</span>
            </div>
        @endif

        @if ($teams->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                Belum ada team. Gunakan Preview lalu "Bentuk Tim" untuk pembagian otomatis, atau tambahkan anggota secara manual.
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($teams as $team)
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ $team->name }}</h2>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $team->players->count() }} pemain · {{ $team->substitutes->count() }} cadangan
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:button size="xs" variant="ghost" wire:click="shuffle({{ $team->id }})">Shuffle</flux:button>
                            </div>
                        </div>

                        <div class="mb-2">
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Pemain</p>
                            <ol class="space-y-1">
                                @foreach ($team->players as $member)
                                    <li class="flex items-center gap-2 rounded-md bg-zinc-50 px-3 py-1.5 text-sm text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ $loop->iteration }}.</span>
                                        <span class="flex-1">{{ $member->competitionRegistration?->participation?->person?->nama ?? '-' }}</span>
                                        @if (! empty($swapOptions[$member->id] ?? []))
                                            <flux:select size="xs" wire:model="swapWith.{{ $member->id }}" class="w-44">
                                                <flux:select.option value="0">tukar dengan…</flux:select.option>
                                                @foreach ($swapOptions[$member->id] as $candidate)
                                                    <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['label'] }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                            <flux:button size="xs" variant="subtle" wire:click="swapMember({{ $team->id }}, {{ $member->id }})">Tukar</flux:button>
                                        @endif
                                        <button wire:click="moveToSubstitutes({{ $team->id }}, {{ $member->id }})" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400" title="Pindah ke cadangan">→ cadangan</button>
                                        <button wire:click="removeMember({{ $team->id }}, {{ $member->id }})" class="text-xs text-red-500 hover:text-red-700" title="Hapus">hapus</button>
                                    </li>
                                @endforeach
                            </ol>
                        </div>

                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Cadangan</p>
                            <ol class="space-y-1">
                                @foreach ($team->substitutes as $member)
                                    <li class="flex items-center gap-2 rounded-md bg-amber-50 px-3 py-1.5 text-sm text-zinc-800 dark:bg-amber-950/40 dark:text-amber-50">
                                        <span class="text-zinc-500 dark:text-amber-200">{{ $loop->iteration }}.</span>
                                        <span class="flex-1">{{ $member->competitionRegistration?->participation?->person?->nama ?? '-' }}</span>
                                        @if (! empty($swapOptions[$member->id] ?? []))
                                            <flux:select size="xs" wire:model="swapWith.{{ $member->id }}" class="w-44">
                                                <flux:select.option value="0">tukar dengan…</flux:select.option>
                                                @foreach ($swapOptions[$member->id] as $candidate)
                                                    <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['label'] }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                            <flux:button size="xs" variant="subtle" wire:click="swapMember({{ $team->id }}, {{ $member->id }})">Tukar</flux:button>
                                        @endif
                                        <button wire:click="moveToPlayers({{ $team->id }}, {{ $member->id }})" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400" title="Pindah ke pemain">→ pemain</button>
                                        <button wire:click="removeMember({{ $team->id }}, {{ $member->id }})" class="text-xs text-red-500 hover:text-red-700" title="Hapus">hapus</button>
                                    </li>
                                @endforeach
                            </ol>
                        </div>

                        @if ($availableRegistrations->isNotEmpty())
                            <div class="mt-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tersedia (belum masuk team)</p>
                                <div class="max-h-28 space-y-1 overflow-y-auto">
                                    @foreach ($availableRegistrations as $reg)
                                        <div class="flex items-center gap-2 rounded-md bg-zinc-50 px-3 py-1 text-xs text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
                                            <span class="flex-1">{{ $reg->participation?->person?->nama ?? '#' . $reg->id }}</span>
                                            <button wire:click="addMember({{ $team->id }}, {{ $reg->id }}, false)" class="text-blue-600 hover:text-blue-800 dark:text-blue-400" title="Tambah sebagai pemain">+ pemain</button>
                                            <button wire:click="addMember({{ $team->id }}, {{ $reg->id }}, true)" class="text-amber-600 hover:text-amber-800 dark:text-amber-400" title="Tambah sebagai cadangan">+ cadangan</button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>