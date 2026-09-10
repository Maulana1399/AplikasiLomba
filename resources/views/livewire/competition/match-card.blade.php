@php
    $participantNames = $schedule->scheduleEntries->map(function ($e) {
        return $e->competitionRegistration?->participation?->person?->nama ?? $e->team?->name ?? '?';
    })->filter()->values();
    $participantsCount = $schedule->participants_count ?? 0;
    $required = $schedule->minParticipantsToStart();
    $participantsComplete = $participantsCount >= $required;

    $format = $schedule->competitionClass?->format;
    $isHeat = in_array($format, [\App\Support\CompetitionFormat::INDIVIDUAL_HEAT, \App\Support\CompetitionFormat::TEAM_HEAT], true);
    $isBracketMatch = $schedule->bracketMatch()->exists();
    $requiresOfficial = app(\App\Services\Competition\CompetitionWorkflowService::class)->requiresOfficial($schedule);
@endphp
<div @class([
    'rounded-xl border-2 bg-white p-5 dark:bg-zinc-950',
    'border-green-400 dark:border-green-600' => $schedule->status === 'Playing',
    'border-yellow-400 dark:border-yellow-600' => $schedule->status === 'Waiting Result',
    'border-blue-300 dark:border-blue-700' => $schedule->status === 'Ready',
])>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-3">
                <span @class([
                    'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wider',
                    'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $schedule->status === 'Playing',
                    'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' => $schedule->status === 'Waiting Result',
                    'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' => $schedule->status === 'Ready',
                ])>{{ $schedule->status }}</span>
                @if ($isHeat)
                    <span class="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-violet-700 dark:bg-violet-900 dark:text-violet-200">HEAT</span>
                @elseif ($isBracketMatch)
                    <span class="inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:bg-orange-900 dark:text-orange-200">BRACKET</span>
                @endif
                @if ($schedule->venue)
                    <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ $schedule->venue->name }}</span>
                @endif
            </div>
            <div class="mt-2">
                <span class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $schedule->competitionClass?->competitionCategory?->name ?? '' }}</span>
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white truncate">{{ $schedule->competitionClass?->name ?? '-' }}</h3>
            </div>

            @if ($schedule->matchOfficials->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ($schedule->matchOfficials as $official)
                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ $official->user?->name ?? '?' }}
                            <span class="text-zinc-400">({{ $official->role }})</span>
                        </span>
                    @endforeach
                </div>
            @endif

            @if ($participantNames->isNotEmpty())
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @foreach ($participantNames as $i => $name)
                        <span class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-sm font-semibold"
                              @class([
                                  'border-red-200 bg-red-50 text-red-700 dark:border-red-700 dark:bg-red-900/50 dark:text-red-200' => $i === 0,
                                  'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-900/50 dark:text-blue-200' => $i === 1 && $participantNames->count() > 1,
                                  'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200' => $i > 1,
                              ])>
                            @if ($i === 0)🔴 @elseif($i === 1)🔵 @endif
                            {{ $name }}
                        </span>
                        @if ($i === 0 && $participantNames->count() > 1)
                            <span class="text-base font-bold text-zinc-400 dark:text-zinc-500">VS</span>
                        @endif
                    @endforeach
                </div>
            @else
                <div class="mt-3 text-sm text-zinc-400 dark:text-zinc-500">Belum ada peserta.</div>
            @endif
        </div>
        <div class="flex flex-col gap-2 shrink-0">
            @if ($schedule->status === 'Ready' && $participantsComplete)
                <flux:button wire:click="startMatch({{ $schedule->id }})" variant="primary" class="whitespace-nowrap">
                    Start Match
                </flux:button>
            @endif
            @if ($schedule->status === 'Playing')
                <flux:button wire:click="moveToWaitingResult({{ $schedule->id }})" variant="danger" class="whitespace-nowrap">
                    Finish Match
                </flux:button>
                @if ($requiresOfficial)
                    <flux:button :href="route('competition.official-panel', ['schedule' => $schedule->id], absolute: false)" variant="ghost" class="whitespace-nowrap">
                        Buka Official Panel
                    </flux:button>
                @else
                    <flux:button :href="route('competition.schedule.outcomes', ['schedule' => $schedule->id], absolute: false)" variant="ghost" class="whitespace-nowrap">
                        Input Hasil
                    </flux:button>
                @endif
            @endif
            @if ($schedule->status === 'Waiting Result')
                @if ($requiresOfficial)
                    <flux:button :href="route('competition.official-panel', ['schedule' => $schedule->id], absolute: false)" variant="primary" class="whitespace-nowrap">
                        Buka Official Panel
                    </flux:button>
                @else
                    <flux:button :href="route('competition.schedule.outcomes', ['schedule' => $schedule->id], absolute: false)" variant="primary" class="whitespace-nowrap">
                        Input Hasil
                    </flux:button>
                @endif
            @endif
            @can('manage-officials')
                <flux:button wire:click="openOfficialDialog({{ $schedule->id }})" size="sm" variant="ghost" class="whitespace-nowrap">
                    Atur Official
                </flux:button>
            @endcan
            <flux:button :href="route('competition.schedule.entries', ['schedule' => $schedule->id], absolute: false)"
                         :variant="$schedule->status === 'Ready' && !$participantsComplete ? 'primary' : 'ghost'"
                         size="sm" class="whitespace-nowrap">
                Atur Peserta
            </flux:button>
        </div>
    </div>
</div>
