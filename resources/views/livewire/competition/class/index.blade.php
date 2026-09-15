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

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Lomba</label>
            <flux:select wire:model.live="filterEventId">
                <flux:select.option value="">Semua</flux:select.option>
                @foreach ($events as $event)
                    <flux:select.option value="{{ $event->id }}">{{ $event->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Kategori</label>
            <flux:select wire:model.live="filterCategoryId">
                <flux:select.option value="">Semua</flux:select.option>
                @foreach ($this->filterCategories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Gender</label>
            <flux:select wire:model.live="filterGender">
                <flux:select.option value="">Semua</flux:select.option>
                <flux:select.option value="L">Laki - Laki</flux:select.option>
                <flux:select.option value="P">Perempuan</flux:select.option>
                <flux:select.option value="M">Campuran</flux:select.option>
            </flux:select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Format</label>
            <flux:select wire:model.live="filterFormat">
                <flux:select.option value="">Semua</flux:select.option>
                @foreach ($this->formatOptions() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
            <flux:select wire:model.live="filterStatus">
                <flux:select.option value="">Semua</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
        </div>
    </div>

    @if ($showCreateForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-950">
            <h2 class="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Kelas Baru</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Lomba</label>
                    <flux:select wire:model.live="newEventId" placeholder="Pilih lomba">
                        @foreach ($events as $event)
                            <flux:select.option value="{{ $event->id }}">{{ $event->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('newEventId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
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
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Honorable Mention</label>
                    <flux:input wire:model="newHonorableMentionCount" type="number" min="0" max="100" placeholder="0 (opsional, tidak mempengaruhi ranking/podium)" />
                    @error('newHonorableMentionCount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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

    <style>#class-table-scroll{scrollbar-width:none;-ms-overflow-style:none}#class-table-scroll::-webkit-scrollbar{display:none;width:0;height:0}</style>
    <div id="class-table-wrap" class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950 overflow-hidden">
        <div class="px-3 pt-3 pb-2 text-xs text-zinc-500 dark:text-zinc-400 lg:hidden" aria-hidden="true">Geser tabel ke kiri/kanan untuk melihat semua kolom →</div>
        <div id="class-table-scroll" tabindex="0" class="overflow-x-auto overscroll-x-contain focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 dark:focus-visible:ring-zinc-600" style="scrollbar-width:none;-ms-overflow-style:none">
            <table class="w-full min-w-[1120px] divide-y divide-zinc-200 dark:divide-zinc-800">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Lomba</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Kategori</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Nama</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Gender</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Format</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Metode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">Pemenang</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">H.M.</th>
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
                                <flux:select wire:model.live="editEventId" size="sm">
                                    @foreach ($events as $event)
                                        <flux:select.option value="{{ $event->id }}">{{ $event->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="px-4 py-2">
                                <flux:select wire:model="editCompetitionCategoryId" size="sm">
                                    @foreach ($editCategories as $category)
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
                                <flux:input wire:model="editHonorableMentionCount" size="sm" type="number" min="0" max="100" placeholder="0" />
                                @error('editHonorableMentionCount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->event?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->competitionCategory?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">{{ $class->name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $class->gender === 'L' ? 'Laki - Laki' : ($class->gender === 'P' ? 'Perempuan' : 'Campuran') }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ $this->formatOptions()[$this->uiFormat($class->format, $class->resultType())] ?? $class->format }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ $this->resultTypeLabel($class->resultType()) }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->winner_count ?? 3 }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500">{{ $class->honorable_mention_count ?? 0 }}</td>
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
                                    <flux:button wire:click="delete({{ $class->id }})" size="sm" variant="danger"
                                        wire:confirm="Yakin hapus kelas ini? Tindakan tidak dapat dibatalkan.">Hapus</flux:button>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="13" class="px-4 py-8 text-center text-sm text-zinc-500">Belum ada kelas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div id="class-table-scrollbar" class="fixed bottom-0 z-30 hidden overflow-x-auto overflow-y-hidden border border-zinc-200 bg-white shadow-[0_-2px_10px_rgba(0,0,0,0.08)] dark:border-zinc-800 dark:bg-zinc-900/95 dark:shadow-none [scrollbar-width:thin] [&::-webkit-scrollbar]:h-2 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-zinc-300 dark:[&::-webkit-scrollbar-thumb]:bg-zinc-700" aria-hidden="true" style="scrollbar-width:thin">
            <div id="class-table-scrollbar-inner" class="h-px"></div>
        </div>
    </div>
    @push('scripts')
    <script>
    (() => {
        const idScroll = 'class-table-scroll';
        const idWrap = 'class-table-wrap';
        const idBar = 'class-table-scrollbar';
        const idInner = 'class-table-scrollbar-inner';
        let ro, onBar, onScroll, onWinScroll, onWinResize;
        const setup = () => {
            const s = document.getElementById(idScroll);
            const w = document.getElementById(idWrap);
            const b = document.getElementById(idBar);
            const i = document.getElementById(idInner);
            if (!s || !w || !b || !i) return;
            if (onBar) b.removeEventListener('scroll', onBar);
            if (onScroll) s.removeEventListener('scroll', onScroll);
            if (onWinScroll) window.removeEventListener('scroll', onWinScroll);
            if (onWinResize) window.removeEventListener('resize', onWinResize);
            if (ro) ro.disconnect();
            let needsOverflow = false;
            let rafSync = 0;
            let rafPos = 0;
            const isWrapVisible = () => {
                const r = w.getBoundingClientRect();
                return r.width > 0 && r.height > 0 && r.bottom > 8 && r.top < window.innerHeight;
            };
            const applyPos = () => {
                const r = w.getBoundingClientRect();
                b.style.left = r.left + 'px';
                b.style.width = r.width + 'px';
                b.style.bottom = '0px';
            };
            const update = () => {
                const show = needsOverflow && isWrapVisible();
                b.classList.toggle('hidden', !show);
                b.setAttribute('aria-hidden', show ? 'false' : 'true');
                if (show) { applyPos(); b.scrollLeft = s.scrollLeft; }
            };
            const syncWidth = () => {
                i.style.width = s.scrollWidth + 'px';
                needsOverflow = s.scrollWidth > s.clientWidth + 1;
                update();
            };
            const sync = (src, dst) => {
                if (rafSync) return;
                rafSync = requestAnimationFrame(() => { dst.scrollLeft = src.scrollLeft; rafSync = 0; });
            };
            const onPos = () => {
                if (rafPos) return;
                rafPos = requestAnimationFrame(() => { update(); rafPos = 0; });
            };
            onBar = () => sync(b, s);
            onScroll = () => sync(s, b);
            onWinScroll = onPos;
            onWinResize = () => { syncWidth(); onPos(); };
            b.addEventListener('scroll', onBar, { passive: true });
            s.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('scroll', onWinScroll, { passive: true });
            window.addEventListener('resize', onWinResize);
            ro = new ResizeObserver(() => { syncWidth(); });
            ro.observe(s);
            ro.observe(w);
            if (s.firstElementChild) ro.observe(s.firstElementChild);
            syncWidth();
        };
        const init = () => {
            setup();
            if (window.Livewire && Livewire.hook) { try { Livewire.hook('morph.updated', setup); } catch (e) {} }
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
        else init();
        document.addEventListener('livewire:navigated', setup);
        document.addEventListener('livewire:updated', setup);
    })();
    </script>
    @endpush
</div>
