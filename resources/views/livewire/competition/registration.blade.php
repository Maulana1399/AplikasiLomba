<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Registrasi Competition</span>
    </div>

    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Registrasi Peserta Competition</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Isi data peserta, lalu daftarkan ke kategori dan kelas lomba.</p>
    </div>

    {{-- Success State --}}
    @if ($successData)
        <div class="rounded-xl border-2 border-green-300 bg-green-50 p-6 dark:border-green-700 dark:bg-green-950">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-green-200 text-2xl dark:bg-green-800">✅</div>
                <div>
                    <h2 class="text-xl font-bold text-green-800 dark:text-green-200">Registrasi Berhasil!</h2>
                    <p class="text-sm text-green-700 dark:text-green-300">{{ $successData['status'] }}</p>
                </div>
            </div>
            <div class="mt-4 grid gap-2 rounded-lg border border-green-200 bg-white p-4 text-sm dark:border-green-800 dark:bg-zinc-900">
                <div class="flex justify-between"><span class="text-zinc-500">Nama:</span><span class="font-semibold text-zinc-900 dark:text-white">{{ $successData['person_name'] }}</span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Kelas Peserta:</span><span>{{ $successData['kelas'] }}</span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Kategori:</span><span>{{ $successData['category_name'] }}</span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Kelas:</span><span>{{ $successData['class_name'] }}</span></div>
                <div class="flex justify-between"><span class="text-zinc-500">No. Peserta:</span><span class="font-mono font-bold">{{ $successData['participant_number'] }}</span></div>
            </div>
            <div class="mt-4">
                <flux:button wire:click="resetForm" variant="primary">Daftarkan Peserta Lain</flux:button>
            </div>
        </div>
    @endif

    {{-- Registration Form --}}
    @if (! $successData)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Data Peserta</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nama Lengkap</label>
                    <flux:input wire:model.live="nama" placeholder="Nama peserta" />
                    @error('nama') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelas Peserta</label>
                    <flux:select wire:model="participantClassId" placeholder="Pilih kelas peserta">
                        @foreach ($participantClasses as $participantClass)
                            <flux:select.option value="{{ $participantClass->id }}">{{ $participantClass->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('participantClassId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Jenis Kelamin</label>
                    <flux:select wire:model="jenisKelamin" placeholder="Pilih jenis kelamin">
                        <flux:select.option value="L">Laki - Laki</flux:select.option>
                        <flux:select.option value="P">Perempuan</flux:select.option>
                    </flux:select>
                    @error('jenisKelamin') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tanggal Lahir</label>
                    <flux:input wire:model="tanggalLahir" type="date" />
                    @error('tanggalLahir') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Desa</label>
                    <flux:select wire:model.live="desaId" placeholder="Pilih desa">
                        @foreach ($desas as $d)
                            <flux:select.option value="{{ $d->id }}">{{ $d->desa_asal }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('desaId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelompok</label>
                    <flux:select wire:model="kelompokId" placeholder="{{ blank($desaId) ? 'Pilih desa terlebih dahulu' : 'Pilih kelompok' }}">
                        @foreach ($kelompoks as $k)
                            <flux:select.option value="{{ $k->id }}">{{ $k->kelompok_asal }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('kelompokId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <h2 class="mb-4 mt-8 text-lg font-semibold text-zinc-900 dark:text-white">Lomba</h2>

            {{-- Registered Classes --}}
            @if ($personParticipations)
                <div class="mb-4">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500">Kelas Terdaftar ({{ count($personParticipations) }})</p>
                    @if (count($personParticipations) > 0)
                        <div class="space-y-1">
                            @foreach ($personParticipations as $p)
                                <div class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs dark:border-green-800 dark:bg-green-950">
                                    <span class="text-green-600">✓</span>
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $p['category_name'] }}</span>
                                    <span class="text-zinc-500">/</span>
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $p['class_name'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-zinc-400">Belum ada kelas terdaftar.</p>
                    @endif
                </div>
            @endif

            @if ($alreadyRegistered)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    Peserta sudah terdaftar di kelas ini. Pilih kelas lain.
                </div>
            @endif

            @if ($conflictMessage)
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                    {{ $conflictMessage }}
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
                    @error('competitionCategoryId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kelas Lomba</label>
                    <flux:select wire:model.live="competitionClassId" placeholder="{{ blank($competitionCategoryId) ? 'Pilih kategori terlebih dahulu' : 'Pilih kelas' }}">
                        @foreach ($classes as $class)
                            <flux:select.option value="{{ $class->id }}">{{ $class->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('competitionClassId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6 flex items-center justify-between">
                <div class="flex items-center gap-2 text-sm text-zinc-500">
                    @if ($processing)
                        <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-zinc-300 border-t-blue-600"></span>
                        Menyimpan...
                    @endif
                </div>
                <flux:button wire:click="submit" variant="primary" :loading="$processing" :disabled="$alreadyRegistered">
                    Daftarkan
                </flux:button>
            </div>
        </div>
    @endif
</div>