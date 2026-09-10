<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Kelas Competition</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Kelola kelas perlombaan dalam setiap kategori.</p>
        </div>
        <flux:button wire:click="toggleCreateForm" variant="primary">
            {{ $showCreateForm ? 'Batal' : 'Tambah Kelas' }}
        </flux:button>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($showCreateForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Kelas Baru</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kategori</label>
                    <flux:select wire:model="newCompetitionCategoryId" placeholder="Pilih kategori">
                        @foreach ($categories as $category)
                            <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('newCompetitionCategoryId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Kelas</label>
                    <flux:input wire:model="newName" placeholder="Nama kelas" />
                    @error('newName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Gender</label>
                    <flux:select wire:model="newGender" placeholder="Pilih gender">
                        <flux:select.option value="L">Laki - Laki</flux:select.option>
                        <flux:select.option value="P">Perempuan</flux:select.option>
                        <flux:select.option value="M">Campuran</flux:select.option>
                    </flux:select>
                    @error('newGender') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kode</label>
                    <flux:input wire:model="newCode" placeholder="Kode (opsional)" />
                    @error('newCode') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Urutan</label>
                    <flux:input wire:model="newSortOrder" type="number" placeholder="Urutan (opsional)" />
                    @error('newSortOrder') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Format</label>
                    <flux:select wire:model="newFormat" placeholder="Pilih format">
                        @foreach ($this->formatOptions() as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('newFormat') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Metode Penilaian</label>
                    <flux:select wire:model="newResultType" placeholder="Ikuti default format">
                        <flux:select.option value="">Ikuti default format</flux:select.option>
                        <flux:select.option value="{{ \App\Support\CompetitionResultType::SCORE }}">Skor/Nilai</flux:select.option>
                        <flux:select.option value="{{ \App\Support\CompetitionResultType::TIME }}">Waktu</flux:select.option>
                        <flux:select.option value="{{ \App\Support\CompetitionResultType::RANKING }}">Urutan Finish</flux:select.option>
                        <flux:select.option value="{{ \App\Support\CompetitionResultType::WIN_LOSS }}">Pemenang</flux:select.option>
                    </flux:select>
                    @error('newResultType') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Jumlah Pemenang</label>
                    <flux:input wire:model="newWinnerCount" type="number" min="1" max="100" placeholder="3" />
                    @error('newWinnerCount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ukuran Tim</label>
                    <flux:input wire:model="newTeamSize" type="number" min="1" max="100" placeholder="Kosong = mengikuti aturan default (kelompok terkecil)" />
                    @error('newTeamSize') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-4 flex justify-end">
                <flux:button wire:click="create" variant="primary" :loading="$processing">Simpan</flux:button>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kategori</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Nama</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Gender</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Format</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Metode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Pemenang</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">U. Tim</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Urutan</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($classes as $class)
                    @if ($editId === $class->id)
                        <tr class="bg-amber-50 dark:bg-amber-950/20">
                            <td class="px-4 py-2">
                                <flux:select wire:model="editCompetitionCategoryId" size="sm">
                                    @foreach ($categories as $category)
                                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="editName" size="sm" />
                                @error('editName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-2">
                                <flux:select wire:model="editGender" size="sm">
                                    <flux:select.option value="L">Laki - Laki</flux:select.option>
                                    <flux:select.option value="P">Perempuan</flux:select.option>
                                    <flux:select.option value="M">Campuran</flux:select.option>
                                </flux:select>
                            </td>
                            <td class="px-4 py-2">
                                @if ($this->canEditFormat($class))
                                    <flux:select wire:model="editFormat" size="sm">
                                        @foreach ($this->formatOptions() as $value => $label)
                                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('editFormat') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                @else
                                    <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $this->formatOptions()[$class->format] ?? $class->format }}</div>
                                    <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">Format tidak dapat diubah karena sudah ada jadwal/hasil.</div>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                @if ($this->canEditResultType($class))
                                    <flux:select wire:model="editResultType" size="sm">
                                        <flux:select.option value="">Ikuti default format</flux:select.option>
                                        <flux:select.option value="{{ \App\Support\CompetitionResultType::SCORE }}">Skor/Nilai</flux:select.option>
                                        <flux:select.option value="{{ \App\Support\CompetitionResultType::TIME }}">Waktu</flux:select.option>
                                        <flux:select.option value="{{ \App\Support\CompetitionResultType::RANKING }}">Urutan Finish</flux:select.option>
                                        <flux:select.option value="{{ \App\Support\CompetitionResultType::WIN_LOSS }}">Pemenang</flux:select.option>
                                    </flux:select>
                                    @error('editResultType') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                @else
                                    <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $this->resultTypeLabel($class->resultType()) }}</div>
                                    <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">Metode penilaian tidak dapat diubah karena hasil pertandingan sudah tersedia.</div>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="editWinnerCount" size="sm" type="number" min="1" max="100" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="editTeamSize" size="sm" type="number" min="1" max="100" placeholder="-" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="editCode" size="sm" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="editSortOrder" type="number" size="sm" />
                            </td>
                            <td class="px-4 py-2 text-sm">
                                <div class="text-sm text-zinc-900 dark:text-zinc-100">{{ $class->is_active ? 'Active' : 'Inactive' }}</div>
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex gap-1">
                                    <flux:button wire:click="update" size="sm" variant="primary">Simpan</flux:button>
                                    <flux:button wire:click="cancelEdit" size="sm" variant="ghost">Batal</flux:button>
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->competitionCategory?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">{{ $class->name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $class->gender === 'L' ? 'Laki - Laki' : ($class->gender === 'P' ? 'Perempuan' : 'Campuran') }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ $this->formatOptions()[$this->uiFormat($class->format, $class->resultType())] ?? $class->format }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ $this->resultTypeLabel($class->resultType()) }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->winner_count ?? 3 }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->team_size ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->code ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->sort_order ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                    'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $class->is_active,
                                    'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' => !$class->is_active,
                                ])>{{ $class->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <div class="flex gap-1">
                                    <flux:button wire:click="edit({{ $class->id }})" size="sm" icon-trailing="pencil">Edit</flux:button>
                                    <flux:button wire:click="toggleActive({{ $class->id }})" size="sm" variant="{{ $class->is_active ? 'danger' : 'primary' }}">
                                        {{ $class->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="11" class="px-4 py-8 text-center text-sm text-zinc-500">Belum ada kelas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
