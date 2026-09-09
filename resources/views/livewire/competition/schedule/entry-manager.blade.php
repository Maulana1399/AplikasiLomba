<div class="space-y-6">
    <div class="text-sm text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('competition.dashboard', app(\App\Support\ActiveEventContext::class)->current()) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Dashboard</a>
        <span class="mx-1">/</span>
        <a href="{{ route('competition.schedule.index', ['event' => app(\App\Support\ActiveEventContext::class)->current()], absolute: false) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">Jadwal</a>
        <span class="mx-1">/</span>
        <span class="text-zinc-800 dark:text-zinc-200 font-medium">Atur Peserta</span>
    </div>

    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $categoryName }} / {{ $className }}</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Venue: {{ $venueName }} &middot; Pilih peserta untuk jadwal ini.</p>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">{{ session('success') }}</div>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        {{-- Available Participants --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold text-zinc-900 dark:text-white">Tersedia ({{ count($available) }})</h2>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($available as $entry)
                    <div class="flex items-center justify-between px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $entry['name'] }}</p>
                            <p class="text-xs text-zinc-500">{{ $entry['number'] }} &middot; {{ $entry['desa'] }}</p>
                        </div>
                        <button wire:click="assign({{ $entry['id'] }})"
                                class="ml-2 flex h-8 w-8 items-center justify-center rounded-lg border border-blue-300 text-blue-600 hover:bg-blue-50 dark:border-blue-600 dark:text-blue-400 dark:hover:bg-blue-950"
                                title="Tugaskan ke jadwal">&gt;</button>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-zinc-500">Semua peserta sudah ditugaskan.</div>
                @endforelse
            </div>
        </div>

        {{-- Assigned Participants --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold text-zinc-900 dark:text-white">Dijadwalkan ({{ count($assigned) }})</h2>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($assigned as $entry)
                    <div class="flex items-center gap-2 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                        <div class="flex flex-col">
                            <button wire:click="moveUp({{ $entry['id'] }})" class="text-xs text-zinc-400 hover:text-zinc-600" title="Naik">&uarr;</button>
                            <button wire:click="moveDown({{ $entry['id'] }})" class="text-xs text-zinc-400 hover:text-zinc-600" title="Turun">&darr;</button>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $entry['name'] }}</p>
                            <p class="text-xs text-zinc-500">{{ $entry['number'] }} &middot; {{ $entry['desa'] }}</p>
                        </div>
                        <button wire:click="unassign({{ $entry['id'] }})"
                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-600 dark:text-red-400 dark:hover:bg-red-950"
                                title="Hapus dari jadwal">&lt;</button>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-zinc-500">Belum ada peserta dijadwalkan.</div>
                @endforelse
            </div>
        </div>
    </div>

    @if (count($assigned) > 0)
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-center text-sm text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300">
            ✅ {{ count($assigned) }} peserta ditugaskan. Perubahan tersimpan otomatis.
        </div>
    @endif
</div>
