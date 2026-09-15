<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Peserta Competition</span>
    </div>

    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Daftar Peserta Competition</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Pilih lomba, lalu kategori dan kelas untuk menampilkan peserta.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Lomba <span class="text-red-500">*</span></label>
            <flux:select wire:model.live="competitionId" placeholder="Pilih lomba">
                <flux:select.option value="all">Semua</flux:select.option>
                @foreach ($competitions as $competition)
                    <flux:select.option value="{{ $competition->id }}">{{ $competition->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kategori</label>
            <flux:select wire:key="category-select-{{ $competitionId }}" wire:model.live="competitionCategoryId" placeholder="{{ $showingAllCompetition ? 'Tidak perlu dipilih' : (blank($competitionId) ? 'Pilih lomba terlebih dahulu' : 'Pilih kategori') }}" :disabled="$showingAllCompetition || blank($competitionId)">
                <flux:select.option value="all">Semua</flux:select.option>
                @foreach ($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelas</label>
            <flux:select wire:key="class-select-{{ $competitionId }}-{{ $competitionCategoryId }}" wire:model.live="competitionClassId" placeholder="{{ $showingAllCategory || $showingAllCompetition ? 'Tidak perlu dipilih' : (blank($competitionId) ? 'Pilih lomba terlebih dahulu' : 'Pilih kelas') }}" :disabled="$showingAllCategory || $showingAllCompetition || blank($competitionId) || blank($competitionCategoryId)">
                @foreach ($classes as $class)
                    <flux:select.option value="{{ $class->id }}">{{ $class->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">No</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Nama</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">No. Peserta</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Desa</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kelompok</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kategori</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kelas</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Tipe</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($registrations as $reg)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                        <td class="px-4 py-3 text-sm text-zinc-500">{{ $loop->iteration }}</td>
                        <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">{{ $reg->participation?->person?->nama ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-zinc-500">{{ $reg->participation?->participant_number ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-zinc-500">{{ $reg->participation?->person?->desa?->desa_asal ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-zinc-500">{{ $reg->participation?->person?->kelompok?->kelompok_asal ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-zinc-500">{{ $reg->competitionCategory?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-zinc-500">{{ $reg->competitionClass?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $reg->registration_type }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-zinc-500">
                            @if ($showingAllCompetition)
                                Belum ada peserta terdaftar di lomba mana pun.
                            @elseif ($showingAllCategory)
                                Belum ada peserta terdaftar di lomba ini.
                            @elseif ($competitionClassId)
                                Belum ada peserta di kelas ini.
                            @elseif (blank($competitionId))
                                Pilih lomba untuk menampilkan peserta.
                            @else
                                Pilih kategori dan kelas untuk menampilkan peserta.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
