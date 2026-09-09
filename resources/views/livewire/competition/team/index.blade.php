<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard', app(\App\Support\ActiveEventContext::class)->current()) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Teams</span>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Teams Competition</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Satu Kelompok = satu Team per lomba. Auto formation berdasarkan kelompok terkecil.</p>
        </div>

        @if ($selectedClass)
            <div class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <span class="text-zinc-500">Format:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $formatLabel }}</span>
                @if ($selectedClass->isTeamFormat())
                    <span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-200">team</span>
                @endif
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
                    <flux:select.option value="{{ $class->id }}">{{ $class->name }} ({{ \App\Support\CompetitionFormat::label($class->format) }})</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($selectedClass)
        <div class="flex items-center justify-between gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="text-sm text-zinc-600 dark:text-zinc-300">
                @if ($selectedClass->isTeamFormat())
                    Auto team formation: ukuran team = kelompok terkecil, sisa menjadi cadangan.
                    @if ($isFormed)
                        <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                            Tim sudah dibentuk. "Bentuk Ulang Tim" akan mengganti pembagian saat ini.
                        </div>
                    @endif
                @else
                    Format bukan team — Teams tidak diperlukan untuk lomba ini.
                @endif
            </div>
            @if ($selectedClass->isTeamFormat())
                @if ($isFormed)
                    <flux:button wire:click="rebuildFormation" variant="filled" :loading="$processing"
                        wire:confirm="Tim sudah dibentuk. Membentuk ulang akan mengganti pembagian. Lanjutkan?">
                        Bentuk Ulang Tim
                    </flux:button>
                @else
                    <flux:button wire:click="autoFormation" variant="primary" :loading="$processing">
                        Bentuk Tim Otomatis
                    </flux:button>
                @endif
            @endif
        </div>

        @if ($isFormed)
            <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-950">
                <span class="text-zinc-500">Total Team:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $summary['total_teams'] }}</span>
                <span class="mx-2 text-zinc-300 dark:text-zinc-700">·</span>
                <span class="text-zinc-500">Team Size:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $summary['team_size'] ?? '-' }} pemain</span>
            </div>
        @endif

        @if ($teams->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                Belum ada team. Jalankan Auto Formation untuk format team, atau tambahkan anggota secara manual.
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($teams as $team)
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <h2 class="font-semibold text-zinc-900 dark:text-white">{{ $team->name }}</h2>
                                <p class="text-xs text-zinc-500">
                                    {{ $team->players->count() }} pemain · {{ $team->substitutes->count() }} cadangan
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:button size="xs" variant="ghost" wire:click="shuffle({{ $team->id }})">Shuffle</flux:button>
                            </div>
                        </div>

                        <div class="mb-2">
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Players</p>
                            <ol class="space-y-1">
                                @foreach ($team->players as $member)
                                    <li class="flex items-center gap-2 rounded-md bg-zinc-50 px-3 py-1.5 text-sm dark:bg-zinc-800">
                                        <span class="text-zinc-400">{{ $loop->iteration }}.</span>
                                        <span class="flex-1">{{ $member->competitionRegistration?->participation?->person?->nama ?? '-' }}</span>
                                        <button wire:click="moveToSubstitutes({{ $team->id }}, {{ $member->id }})" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400" title="Pindah ke cadangan">→ cadangan</button>
                                        <button wire:click="removeMember({{ $team->id }}, {{ $member->id }})" class="text-xs text-red-500 hover:text-red-700" title="Hapus">hapus</button>
                                    </li>
                                @endforeach
                            </ol>
                        </div>

                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Substitutes</p>
                            <ol class="space-y-1">
                                @foreach ($team->substitutes as $member)
                                    <li class="flex items-center gap-2 rounded-md bg-amber-50 px-3 py-1.5 text-sm dark:bg-amber-950/40">
                                        <span class="text-zinc-400">{{ $loop->iteration }}.</span>
                                        <span class="flex-1">{{ $member->competitionRegistration?->participation?->person?->nama ?? '-' }}</span>
                                        <button wire:click="moveToPlayers({{ $team->id }}, {{ $member->id }})" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400" title="Pindah ke pemain">→ pemain</button>
                                        <button wire:click="removeMember({{ $team->id }}, {{ $member->id }})" class="text-xs text-red-500 hover:text-red-700" title="Hapus">hapus</button>
                                    </li>
                                @endforeach
                            </ol>
                        </div>

                        @if ($availableRegistrations->isNotEmpty())
                            <div class="mt-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Tersedia (belum masuk team)</p>
                                <div class="max-h-28 space-y-1 overflow-y-auto">
                                    @foreach ($availableRegistrations as $reg)
                                        <div class="flex items-center gap-2 rounded-md bg-zinc-50 px-3 py-1 text-xs dark:bg-zinc-800">
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
