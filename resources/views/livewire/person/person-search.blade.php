<div>
    {{-- Search Input --}}
    <div>
        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Cari Person</label>
        <flux:input wire:model.live.debounce.300ms="query" placeholder="Cari berdasarkan nama..." />
    </div>

    {{-- Search Results --}}
    @if ($query && strlen($query) >= 2 && $results->isEmpty())
        <div class="mt-3 rounded-lg border border-dashed border-zinc-300 p-4 text-center dark:border-zinc-600">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Person tidak ditemukan.</p>
            @if (!$showCreateForm)
                <flux:button wire:click="toggleCreateForm" variant="primary" size="sm" class="mt-2">
                    + Buat Person Baru
                </flux:button>
            @endif
        </div>
    @endif

    {{-- Result Cards --}}
    @if ($results->isNotEmpty())
        <div class="mt-3 grid gap-2">
            @foreach ($results as $person)
                <div class="cursor-pointer rounded-lg border border-zinc-200 bg-white p-4 transition hover:border-blue-300 hover:bg-blue-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-blue-600 dark:hover:bg-blue-950"
                     wire:click="selectPerson({{ $person->id }})">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $person->nama }}</div>
                            <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                @if ($person->kelas)
                                    <span class="inline-block rounded bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">{{ $person->kelas }}</span>
                                    &middot;
                                @endif
                                {{ $person->jenis_kelamin_label }}
                                @if ($person->tanggal_lahir)
                                    &middot; {{ $person->tanggal_lahir->format('d/m/Y') }}
                                @endif
                            </div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $person->desa?->desa_asal ?? '-' }}
                                @if ($person->kelompok)
                                    / {{ $person->kelompok->kelompok_asal }}
                                @endif
                            </div>
                        </div>
                        <flux:button size="sm" variant="primary">Pilih</flux:button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Create Person Form --}}
    @if ($showCreateForm)
        <div class="mt-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <h3 class="mb-3 text-base font-semibold text-zinc-900 dark:text-white">Person Baru</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Lengkap</label>
                    <flux:input wire:model="newNama" placeholder="Nama lengkap" />
                    @error('newNama') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelas</label>
                    <flux:input wire:model="newKelas" placeholder="Contoh: SD2, SMP1" />
                    @error('newKelas') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Jenis Kelamin</label>
                    <flux:select wire:model="newJenisKelamin" placeholder="Pilih">
                        <flux:select.option value="L">Laki - Laki</flux:select.option>
                        <flux:select.option value="P">Perempuan</flux:select.option>
                    </flux:select>
                    @error('newJenisKelamin') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tanggal Lahir</label>
                    <flux:input wire:model="newTanggalLahir" type="date" />
                    @error('newTanggalLahir') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Desa</label>
                    <flux:select wire:model.live="newDesaId" placeholder="Pilih desa">
                        @foreach (\App\Models\desa::orderBy('desa_asal')->get() as $desa)
                            <flux:select.option value="{{ $desa->id }}">{{ $desa->desa_asal }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('newDesaId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelompok</label>
                    <flux:select wire:model="newKelompokId" placeholder="Pilih kelompok">
                        @if ($newDesaId)
                            @foreach (\App\Models\kelompok::where('desa_id', $newDesaId)->orderBy('kelompok_asal')->get() as $kel)
                                <flux:select.option value="{{ $kel->id }}">{{ $kel->kelompok_asal }}</flux:select.option>
                            @endforeach
                        @endif
                    </flux:select>
                    @error('newKelompokId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <flux:button wire:click="toggleCreateForm" variant="ghost" size="sm">Batal</flux:button>
                <flux:button wire:click="createPerson" variant="primary" size="sm">Simpan Person</flux:button>
            </div>
        </div>
    @endif
</div>
