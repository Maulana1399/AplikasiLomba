<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <a href="{{ route('competition.teams', absolute: false) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Pembagian Tim</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Daftar Tim</span>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Daftar Tim</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Menampilkan tim yang sudah terbentuk. Untuk membentuk kembali, gunakan menu
                <a href="{{ route('competition.teams', absolute: false) }}" wire:navigate class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Pembentukan Tim</a>.
            </p>
        </div>

        @if ($selectedClass)
            <div class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <span class="text-zinc-500 dark:text-zinc-400">Format:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $formatLabel }}</span>
                <span class="ml-2 inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900 dark:text-blue-200">team</span>
                <span class="ml-1 inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ $genderLabel }}</span>
            </div>
        @endif
    </div>

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
        </div>
    </div>

    @if ($selectedClass)
        @if ($teams->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                Belum ada tim yang terbentuk untuk kelas ini.
            </div>
        @else
            <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-800 dark:bg-zinc-950">
                <span class="text-zinc-500 dark:text-zinc-400">Total Team:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $summary['total_teams'] }}</span>
                <span class="mx-2 text-zinc-300 dark:text-zinc-700">·</span>
                <span class="text-zinc-500 dark:text-zinc-400">Anggota Utama:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $summary['players'] }}</span>
                <span class="mx-2 text-zinc-300 dark:text-zinc-700">·</span>
                <span class="text-zinc-500 dark:text-zinc-400">Cadangan:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $summary['substitutes'] }}</span>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($teams as $team)
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <div>
                                <h2 class="font-semibold text-zinc-900 dark:text-white">#{{ $loop->iteration }} · {{ $team->name }}</h2>
                                @if ($team->kelompok)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Kelompok: {{ $team->kelompok->kelompok_asal }}</p>
                                @endif
                            </div>
                            <div class="text-right text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $team->players->count() }} pemain · {{ $team->substitutes->count() }} cadangan
                            </div>
                        </div>

                        <div class="mb-2">
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Anggota Utama</p>
                            <ol class="space-y-1">
                                @forelse ($team->players as $member)
                                    <li class="flex items-center gap-2 rounded-md bg-zinc-50 px-3 py-1.5 text-sm text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ $loop->iteration }}.</span>
                                        <span class="flex-1">{{ $member->competitionRegistration?->participation?->person?->nama ?? '-' }}</span>
                                        @if ($member->competitionRegistration?->participation?->person?->kelas)
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $member->competitionRegistration->participation->person->kelas }}</span>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-sm text-zinc-500 dark:text-zinc-400">Tidak ada anggota utama.</li>
                                @endforelse
                            </ol>
                        </div>

                        <div>
                            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Cadangan</p>
                            <ol class="space-y-1">
                                @forelse ($team->substitutes as $member)
                                    <li class="flex items-center gap-2 rounded-md bg-amber-50 px-3 py-1.5 text-sm text-zinc-800 dark:bg-amber-950/40 dark:text-amber-50">
                                        <span class="text-zinc-500 dark:text-amber-200">{{ $loop->iteration }}.</span>
                                        <span class="flex-1">{{ $member->competitionRegistration?->participation?->person?->nama ?? '-' }}</span>
                                        @if ($member->competitionRegistration?->participation?->person?->kelas)
                                            <span class="text-xs text-zinc-500 dark:text-amber-200/70">{{ $member->competitionRegistration->participation->person->kelas }}</span>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-sm text-zinc-500 dark:text-zinc-400">Tidak ada cadangan.</li>
                                @endforelse
                            </ol>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>