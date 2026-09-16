<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Registrasi Ulang</span>
    </div>

    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Registrasi Ulang</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Cari nama peserta, periksa data, lalu tandai hadir pada hari pelaksanaan.</p>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if (! $selected)
        <div class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950">
            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Cari nama peserta</label>
            <flux:input
                wire:model.live.debounce.400ms="search"
                placeholder="Cari nama peserta..."
                icon="magnifying-glass"
                size="lg"
                autofocus
            />

            @if (mb_strlen(trim($search)) < 2)
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Ketik minimal 2 huruf untuk menampilkan peserta.</p>
            @elseif ($results->isEmpty())
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Tidak ada peserta yang cocok pada event ini.</p>
            @else
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $results->count() }} peserta ditemukan.</p>
            @endif
        </div>

        <div class="space-y-3">
            @foreach ($results as $person)
                <button
                    type="button"
                    wire:key="result-{{ $person->id }}"
                    wire:click="select({{ $person->id }})"
                    class="block w-full rounded-xl border border-zinc-200 bg-white p-4 text-left transition hover:border-zinc-400 hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-zinc-600"
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <div class="text-base font-semibold text-zinc-900 dark:text-white">{{ $person->nama ?? '-' }}</div>
                            <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                <span class="font-mono font-medium">{{ $person->participations->pluck('participant_number')->filter()->unique()->implode(', ') ?: '-' }}</span>
                                <span class="mx-1">•</span>
                                {{ $person->kelas ?? '-' }}
                                <span class="mx-1">•</span>
                                {{ $person->jenis_kelamin_label ?? '-' }}
                            </div>
                        </div>
                        @if ($this->isPersonReregistered($person))
                            <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">Sudah Daftar Ulang</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200">Belum Daftar Ulang</span>
                        @endif
                    </div>
                    <div class="mt-3 grid gap-1 text-sm text-zinc-500 dark:text-zinc-400 sm:grid-cols-2">
                        <div>Desa: <span class="text-zinc-700 dark:text-zinc-300">{{ $person->desa?->desa_asal ?? '-' }}</span></div>
                        <div>Kelompok: <span class="text-zinc-700 dark:text-zinc-300">{{ $person->kelompok?->kelompok_asal ?? '-' }}</span></div>
                        <div class="sm:col-span-2">
                            Lomba:
                            @php($printed = false)
                            @foreach ($person->participations as $participation)
                                @foreach ($participation->competitionRegistrations as $registration)
                                    @php($printed = true)
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $registration->competitionCategory?->name ?? '-' }} — {{ $registration->competitionClass?->name ?? '-' }}</span>@if (! $loop->last || ! $loop->parent->last)<span>, </span>@endif
                                @endforeach
                            @endforeach
                            @if (! $printed)
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $person->participations->pluck('event.name')->filter()->implode(', ') ?: '-' }}</span>
                            @endif
                        </div>
                    </div>
                </button>
            @endforeach
        </div>
    @else
        <div>
            <flux:button wire:click="closeDetail" variant="ghost" icon="arrow-left" size="sm">Kembali ke pencarian</flux:button>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $selected->nama ?? '-' }}</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">No. Peserta: <span class="font-mono font-semibold text-zinc-800 dark:text-zinc-200">{{ $selected->participations->pluck('participant_number')->filter()->unique()->implode(', ') ?: '-' }}</span></p>
                </div>
                @if ($this->isPersonReregistered($selected))
                    <span class="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800 dark:bg-green-900 dark:text-green-200">Sudah Daftar Ulang</span>
                @else
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200">Belum Daftar Ulang</span>
                @endif
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-zinc-500">Kelas Peserta</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $selected->kelas ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-zinc-500">Jenis Kelamin</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $selected->jenis_kelamin_label ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-zinc-500">Desa</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $selected->desa?->desa_asal ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-zinc-500">Kelompok</dt>
                    <dd class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $selected->kelompok?->kelompok_asal ?? '-' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide text-zinc-500">Lomba yang diikuti</dt>
                    <dd class="mt-1 space-y-1 text-sm text-zinc-900 dark:text-white">
                        @php($printed = false)
                        @foreach ($selected->participations as $participation)
                            @foreach ($participation->competitionRegistrations as $registration)
                                @php($printed = true)
                                <div>{{ $registration->competitionCategory?->name ?? '-' }} — {{ $registration->competitionClass?->name ?? '-' }}</div>
                            @endforeach
                        @endforeach
                        @if (! $printed)
                            <div>{{ $selected->participations->pluck('event.name')->filter()->implode(', ') ?: '-' }}</div>
                        @endif
                    </dd>
                </div>
            </dl>

            @if (! $editing)
                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <flux:button wire:click="startEdit" icon="pencil-square" class="w-full sm:w-auto">Edit</flux:button>

                    @if ($this->isPersonReregistered($selected))
                        <flux:button variant="filled" disabled icon="check-badge" class="w-full sm:w-auto">Sudah Daftar Ulang</flux:button>
                    @else
                        <flux:button wire:click="markPresent" variant="primary" icon="check-badge" class="w-full sm:w-auto">Hadir</flux:button>
                    @endif
                </div>
            @endif

            @if ($editing)
                <div class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-800">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Edit Data Peserta</h3>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama</label>
                            <flux:input wire:model="editNama" placeholder="Nama peserta" />
                            @error('editNama') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelas Peserta</label>
                            <flux:select wire:model="editKelas" placeholder="Pilih kelas peserta">
                                @if ($editKelas !== '' && ! $participantClasses->contains('name', $editKelas))
                                    <flux:select.option value="{{ $editKelas }}">{{ $editKelas }}</flux:select.option>
                                @endif
                                @foreach ($participantClasses as $participantClass)
                                    <flux:select.option value="{{ $participantClass->name }}">{{ $participantClass->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('editKelas') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Jenis Kelamin</label>
                            <flux:select wire:model="editJenisKelamin" placeholder="Pilih jenis kelamin">
                                <flux:select.option value="L">Laki - Laki</flux:select.option>
                                <flux:select.option value="P">Perempuan</flux:select.option>
                            </flux:select>
                            @error('editJenisKelamin') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Desa</label>
                            <flux:select wire:model.live="editDesaId" placeholder="Pilih desa">
                                <flux:select.option value="">-</flux:select.option>
                                @foreach ($desas as $desa)
                                    <flux:select.option value="{{ $desa->id }}">{{ $desa->desa_asal }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('editDesaId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelompok</label>
                            <flux:select wire:model="editKelompokId" placeholder="{{ $editDesaId === '' ? 'Pilih desa terlebih dahulu' : 'Pilih kelompok' }}">
                                <flux:select.option value="">-</flux:select.option>
                                @foreach ($kelompoks as $kelompok)
                                    <flux:select.option value="{{ $kelompok->id }}">{{ $kelompok->kelompok_asal }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @error('editKelompokId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                        <flux:button wire:click="saveEdit" variant="primary" class="w-full sm:w-auto">Simpan</flux:button>
                        <flux:button wire:click="cancelEdit" variant="ghost" class="w-full sm:w-auto">Batal</flux:button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
