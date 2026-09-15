<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard') }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Hasil</span>
    </div>

    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Hasil Lomba</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Daftar hasil akhir dan pemenang semua kelas lomba.</p>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ session('error') }}</div>
    @endif

    {{-- Filter bar --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <flux:select wire:model.live="filterEventId" label="Lomba">
                    <option value="">Semua Lomba</option>
                    @foreach ($events as $event)
                        <option value="{{ $event->id }}">{{ $event->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="filterCategoryId" label="Kategori">
                    <option value="">Semua Kategori</option>
                    @foreach ($filterCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="filterClassId" label="Kelas Lomba">
                    <option value="">Semua Kelas</option>
                    @foreach ($filterClasses as $class)
                        <option value="{{ $class->id }}">{{ $class->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="filterStatus" label="Status">
                    <option value="">Semua</option>
                    <option value="selesai">Selesai</option>
                    <option value="belum">Belum Selesai</option>
                </flux:select>
            </div>
        </div>
    </div>

    {{-- Results table --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr class="text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                    <th class="px-4 py-3">Peringkat</th>
                    <th class="px-4 py-3">Peserta/Tim</th>
                    <th class="px-4 py-3">Lomba</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3">Kelas</th>
                    <th class="px-4 py-3">Hasil</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-4 py-3">
                            @if ($row['position'])
                                <span @class([
                                    'inline-flex items-center gap-1 font-semibold',
                                    'text-yellow-600 dark:text-yellow-300' => $row['position'] === 1,
                                    'text-zinc-500 dark:text-zinc-400' => $row['position'] === 2,
                                    'text-amber-700 dark:text-amber-400' => $row['position'] === 3,
                                    'text-zinc-700 dark:text-zinc-300' => $row['position'] > 3,
                                ])>
                                    @if ($row['is_honorable'])
                                        <flux:icon name="star" variant="solid" class="h-4 w-4 text-zinc-400" />
                                        Honorable Mention
                                    @else
                                        <flux:icon name="trophy" variant="solid" class="h-4 w-4" />
                                        Juara {{ $row['position'] }}
                                    @endif
                                </span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $row['event_name'] }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $row['category_name'] }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $row['class_name'] }}
                            <p class="text-xs text-zinc-400">{{ $row['format'] }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ $row['score_text'] }}</td>
                        <td class="px-4 py-3">
                            @if ($row['status'] === 'Selesai')
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900 dark:text-green-300">
                                    <flux:icon name="check-badge" variant="solid" class="mr-1 h-3.5 w-3.5" />
                                    Selesai
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    Belum Selesai
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-500">
                            Belum ada hasil yang cocok dengan filter ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>