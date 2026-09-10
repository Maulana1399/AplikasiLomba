<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Kategori Competition</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Kelola cabang lomba.</p>
        </div>
        <flux:button wire:click="toggleCreateForm" variant="primary">
            {{ $showCreateForm ? 'Batal' : 'Tambah Kategori' }}
        </flux:button>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($showCreateForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Kategori Baru</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Kategori</label>
                    <flux:input wire:model="newName" placeholder="Nama kategori" />
                    @error('newName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Nama</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Urutan</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($categories as $category)
                    @if ($editId === $category->id)
                        <tr class="bg-amber-50 dark:bg-amber-950/20">
                            <td class="px-4 py-2">
                                <flux:input wire:model="editName" size="sm" />
                                @error('editName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="editCode" size="sm" />
                            </td>
                            <td class="px-4 py-2">
                                <flux:input wire:model="editSortOrder" type="number" size="sm" />
                            </td>
                            <td class="px-4 py-2 text-sm">{{ $category->is_active ? 'Active' : 'Inactive' }}</td>
                            <td class="px-4 py-2">
                                <div class="flex gap-1">
                                    <flux:button wire:click="update" size="sm" variant="primary">Simpan</flux:button>
                                    <flux:button wire:click="cancelEdit" size="sm" variant="ghost">Batal</flux:button>
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">{{ $category->name }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $category->code ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $category->sort_order ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                    'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $category->is_active,
                                    'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' => !$category->is_active,
                                ])>{{ $category->is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <div class="flex gap-1">
                                    <flux:button wire:click="edit({{ $category->id }})" size="sm" icon-trailing="pencil">Edit</flux:button>
                                    <flux:button wire:click="toggleActive({{ $category->id }})" size="sm" variant="{{ $category->is_active ? 'danger' : 'primary' }}">
                                        {{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">Belum ada kategori.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($editId !== null)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">Kategori Konflik (Exclusive)</h2>
            <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Tandai kategori yang tidak boleh diikuti peserta secara bersamaan. Berlaku dua arah otomatis.</p>
            <div class="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                @forelse ($candidateCategories as $candidate)
                    <flux:checkbox wire:model="editExclusiveIds" :value="$candidate->id">
                        {{ $candidate->name }}
                    </flux:checkbox>
                @empty
                    <p class="text-sm text-zinc-500">Belum ada kategori lain dalam event ini.</p>
                @endforelse
            </div>
            @error('editExclusiveIds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif
</div>
