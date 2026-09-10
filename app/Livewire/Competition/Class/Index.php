<?php

namespace App\Livewire\Competition\Class;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionTeamOutcome;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use App\Support\CompetitionResultType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $newName = '';

    public string $newGender = '';

    public string $newCode = '';

    public string $newSortOrder = '';

    public string $newResultType = '';

    public string $newFormat = '';

    public string $newWinnerCount = '';

    public string $newTeamSize = '';

    public string $newCompetitionCategoryId = '';

    public ?int $editId = null;

    public string $editName = '';

    public string $editGender = '';

    public string $editCode = '';

    public string $editSortOrder = '';

    public string $editResultType = '';

    public string $editFormat = '';

    public string $editWinnerCount = '';

    public string $editTeamSize = '';

    public string $editCompetitionCategoryId = '';

    public bool $processing = false;

    public function mount(): void
    {
        app(ActiveEventContext::class)->requireCurrent();
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newName', 'newGender', 'newCode', 'newSortOrder', 'newResultType', 'newFormat', 'newWinnerCount', 'newTeamSize', 'newCompetitionCategoryId']);
        $this->resetErrorBag();
    }

    public function formatOptions(): array
    {
        return [
            CompetitionFormat::INDIVIDUAL_MASS => 'Massal',
            CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL => 'Individual vs Individual',
            CompetitionFormat::TEAM_VS_TEAM => 'Team vs Team',
            CompetitionFormat::INDIVIDUAL_HEAT => 'Heat',
            'individual_scoring' => 'Individual Scoring',
        ];
    }

    public function uiFormat(string $format, ?string $resultType): string
    {
        if ($format === CompetitionFormat::INDIVIDUAL_MASS
            && $resultType === CompetitionResultType::SCORE) {
            return 'individual_scoring';
        }

        return $format;
    }

    public function resolveStoredFormat(string $uiFormat, ?string &$resultType): string
    {
        if ($uiFormat === 'individual_scoring') {
            if ($resultType === '' || $resultType === null) {
                $resultType = CompetitionResultType::SCORE;
            }

            return CompetitionFormat::INDIVIDUAL_MASS;
        }

        return $uiFormat;
    }

    public function resultTypeLabel(?string $resultType): string
    {
        return [
            CompetitionResultType::SCORE => 'Skor/Nilai',
            CompetitionResultType::TIME => 'Waktu',
            CompetitionResultType::RANKING => 'Urutan Finish',
            CompetitionResultType::WIN_LOSS => 'Pemenang',
        ][$resultType] ?? '-';
    }

    public function create(): void
    {
        Gate::authorize('manage-events');

        if ($this->processing) {
            return;
        }
        $this->processing = true;

        try {
            $this->validate([
                'newName' => [
                    'required', 'string', 'max:255',
                    Rule::unique('competition_classes', 'name')->where('competition_category_id', $this->newCompetitionCategoryId),
                ],
                'newGender' => 'required|in:L,P,M',
                'newCode' => 'nullable|string|max:50',
                'newSortOrder' => 'nullable|integer|min:0',
                'newFormat' => 'required|string|'.$this->formatRule(),
                'newResultType' => 'nullable|in:'.implode(',', CompetitionResultType::ALL),
                'newWinnerCount' => 'nullable|integer|min:1|max:100',
                'newTeamSize' => 'nullable|integer|min:1|max:100',
                'newCompetitionCategoryId' => 'required|exists:competition_categories,id',
            ]);

            $event = app(ActiveEventContext::class)->requireCurrent();

            if (! CompetitionCategory::where('id', $this->newCompetitionCategoryId)->where('event_id', $event->id)->exists()) {
                $this->addError('newCompetitionCategoryId', 'Kategori harus berasal dari event aktif.');

                return;
            }

            $resultType = $this->newResultType !== '' ? $this->newResultType : null;
            $format = $this->resolveStoredFormat($this->newFormat, $resultType);

            CompetitionClass::create([
                'event_id' => $event->id,
                'competition_category_id' => $this->newCompetitionCategoryId,
                'name' => $this->newName,
                'gender' => $this->newGender ?: null,
                'code' => $this->newCode ?: null,
                'sort_order' => $this->newSortOrder !== '' ? (int) $this->newSortOrder : null,
                'format' => $format,
                'result_type' => $resultType,
                'winner_count' => $this->newWinnerCount !== '' ? (int) $this->newWinnerCount : 3,
                'team_size' => $this->newTeamSize !== '' ? (int) $this->newTeamSize : null,
            ]);

            $this->showCreateForm = false;
$this->reset(['newName', 'newGender', 'newCode', 'newSortOrder', 'newResultType', 'newFormat', 'newWinnerCount', 'newTeamSize', 'newCompetitionCategoryId']);
            session()->flash('success', 'Kelas berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function formatRule(): string
    {
        return 'in:'.implode(',', array_keys($this->formatOptions()));
    }

    public function storedFormatRule(): string
    {
        return 'in:'.implode(',', array_merge(CompetitionFormat::ALL, ['individual_scoring']));
    }

    public function edit(int $id): void
    {
        $class = CompetitionClass::with('competitionCategory')->findOrFail($id);
        $this->editId = $class->id;
        $this->editName = $class->name;
        $this->editGender = $class->gender ?? '';
        $this->editCode = $class->code ?? '';
        $this->editSortOrder = $class->sort_order ?? '';
        $this->editResultType = $class->result_type ?? '';
        $this->editFormat = $this->uiFormat($class->format, $class->resultType());
        $this->editWinnerCount = (string) ($class->winner_count ?? 3);
        $this->editTeamSize = (string) ($class->team_size ?? '');
        $this->editCompetitionCategoryId = (string) $class->competition_category_id;
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $this->validate([
            'editName' => [
                'required', 'string', 'max:255',
                Rule::unique('competition_classes', 'name')
                    ->where('competition_category_id', $this->editCompetitionCategoryId)
                    ->ignore($this->editId),
            ],
            'editGender' => 'required|in:L,P,M',
            'editCode' => 'nullable|string|max:50',
            'editSortOrder' => 'nullable|integer|min:0',
            'editFormat' => 'required|string|'.$this->storedFormatRule(),
            'editResultType' => 'nullable|in:'.implode(',', CompetitionResultType::ALL),
            'editWinnerCount' => 'nullable|integer|min:1|max:100',
            'editTeamSize' => 'nullable|integer|min:1|max:100',
            'editCompetitionCategoryId' => 'required|exists:competition_categories,id',
        ]);

        $class = CompetitionClass::findOrFail($this->editId);

        if (! CompetitionCategory::where('id', $this->editCompetitionCategoryId)->where('event_id', $class->event_id)->exists()) {
            $this->addError('editCompetitionCategoryId', 'Kategori harus berasal dari event yang sama.');

            return;
        }

        $canEditFormat = $this->canEditFormat($class);
        $canEditResultType = $this->canEditResultType($class);

        $resultType = $canEditResultType
            ? ($this->editResultType !== '' ? $this->editResultType : $class->result_type)
            : $class->result_type;

        $format = $class->format;
        if ($canEditFormat && array_key_exists($this->editFormat, $this->formatOptions())) {
            $format = $this->resolveStoredFormat($this->editFormat, $resultType);
        }

        $class->update([
            'competition_category_id' => $this->editCompetitionCategoryId,
            'name' => $this->editName,
            'gender' => $this->editGender ?: null,
            'code' => $this->editCode ?: null,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : null,
            'format' => $format,
            'result_type' => $resultType,
            'winner_count' => $this->editWinnerCount !== '' ? (int) $this->editWinnerCount : 3,
            'team_size' => $this->editTeamSize !== '' ? (int) $this->editTeamSize : null,
        ]);

        $this->reset(['editId', 'editName', 'editGender', 'editCode', 'editSortOrder', 'editResultType', 'editFormat', 'editWinnerCount', 'editTeamSize', 'editCompetitionCategoryId']);
        session()->flash('success', 'Kelas berhasil diperbarui.');
    }

    public function canEditFormat(CompetitionClass $class): bool
    {
        if ($class->competitionSchedules()->exists()) {
            return false;
        }

        return $this->canEditResultType($class);
    }

    public function canEditResultType(CompetitionClass $class): bool
    {
        $scheduleIds = $class->competitionSchedules()->pluck('id');

        if ($scheduleIds->isNotEmpty()) {
            if (CompetitionHeatResult::whereIn('competition_schedule_id', $scheduleIds)->exists()) {
                return false;
            }
        }

        if (CompetitionOutcome::whereHas('competitionRegistration', fn ($query) => $query->where('competition_class_id', $class->id))->exists()) {
            return false;
        }

        if (CompetitionTeamOutcome::whereHas('team', fn ($query) => $query->where('competition_class_id', $class->id))->exists()) {
            return false;
        }

        return true;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editId', 'editName', 'editGender', 'editCode', 'editSortOrder', 'editResultType', 'editFormat', 'editWinnerCount', 'editTeamSize', 'editCompetitionCategoryId']);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $class = CompetitionClass::findOrFail($id);
        $class->update(['is_active' => ! $class->is_active]);
    }

    public function render()
    {
        $event = app(ActiveEventContext::class)->current();

        return view('livewire.competition.class.index', [
            'classes' => CompetitionClass::with('competitionCategory')
                ->where('event_id', $event?->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'categories' => CompetitionCategory::where('event_id', $event?->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
