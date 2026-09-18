<?php

namespace App\Livewire\Competition\Class;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionHeatResult;
use App\Models\CompetitionOutcome;
use App\Models\CompetitionTeamOutcome;
use App\Models\Event;
use App\Support\ActiveEventContext;
use App\Support\CompetitionFormat;
use App\Support\CompetitionResultType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $newEventId = '';

    public string $newName = '';

    public string $newGender = '';

    public string $newCode = '';

    public string $newSortOrder = '';

    public string $newResultType = '';

    public string $newFormat = '';

    public string $newWinnerCount = '';

    public string $newHonorableMentionCount = '';

    public string $newTeamSize = '';

    public string $newCompetitionCategoryId = '';

    public ?int $editId = null;

    public string $editEventId = '';

    public string $editName = '';

    public string $editGender = '';

    public string $editCode = '';

    public string $editSortOrder = '';

    public string $editResultType = '';

    public string $editFormat = '';

    public string $editWinnerCount = '';

    public string $editHonorableMentionCount = '';

    public string $editTeamSize = '';

    public string $editCompetitionCategoryId = '';

    public bool $processing = false;

    /*
     * ---------------------------------------------------------------------
     * FILTER HALAMAN
     * ---------------------------------------------------------------------
     *
     * Hierarki:
     * - Pilih Lomba  -> Kategori hanya menampilkan kategori untuk Lomba tsb.
     * - Pilih Kategori -> daftar kelas hanya untuk Lomba + Kategori tsb.
     * - Gender / Format / Status bersifat tambahan.
     *
     * Nilai '' berarti "Semua" (tidak membatasi).
     *
     * Saat Lomba berubah -> Kategori di-reset.
     * Saat Kategori berubah -> pilihan kelas (daftar baris) menyesuaikan.
     */
    public string $filterEventId = '';

    public string $filterCategoryId = '';

    public string $filterGender = '';

    public string $filterFormat = '';

    public string $filterStatus = '';

    public function updatedFilterEventId(): void
    {
        $this->filterCategoryId = '';
        $this->resetValidation();
    }

    public function updatedFilterCategoryId(): void
    {
        $this->resetValidation();
    }

    public function getFilterCategoriesProperty(): \Illuminate\Support\Collection
    {
        if ($this->filterEventId === '' || $this->filterEventId === '0') {
            return collect();
        }

        return $this->getCategoriesForEvent($this->filterEventId);
    }

    public function mount(): void
    {
        $event = app(ActiveEventContext::class)->requireCurrent();
        $this->newEventId = (string) $event->id;
    }

    public function toggleCreateForm(): void
    {
        $this->showCreateForm = ! $this->showCreateForm;
        $this->reset(['newEventId', 'newName', 'newGender', 'newCode', 'newSortOrder', 'newResultType', 'newFormat', 'newWinnerCount', 'newHonorableMentionCount', 'newTeamSize', 'newCompetitionCategoryId']);
        if ($this->showCreateForm) {
            $event = app(ActiveEventContext::class)->current();
            $this->newEventId = (string) ($event?->id ?? '');
        }
        $this->resetErrorBag();
    }

    public function updatedNewEventId(): void
    {
        $validIds = $this->getCategoriesForEvent($this->newEventId)->pluck('id')->map(fn ($id) => (string) $id);
        if ($this->newCompetitionCategoryId !== '' && ! $validIds->contains($this->newCompetitionCategoryId)) {
            $this->newCompetitionCategoryId = '';
        }
        $this->resetValidation('newCompetitionCategoryId');
    }

    public function updatedEditEventId(): void
    {
        $validIds = $this->getCategoriesForEvent($this->editEventId)->pluck('id')->map(fn ($id) => (string) $id);
        if ($this->editCompetitionCategoryId !== '' && ! $validIds->contains($this->editCompetitionCategoryId)) {
            $this->editCompetitionCategoryId = '';
        }
        $this->resetValidation('editCompetitionCategoryId');
    }

    /**
     * 5 format UI yang diekspos AplikasiLomba.
     *
     * Untuk saat ini seluruh lomba dengan format "Heat" adalah LOMBA BEREGU.
     * Pilihan UI "Heat" SELALU disimpan sebagai internal
     * `CompetitionFormat::TEAM_HEAT` — individual_heat & team_mass tidak
     * diekspos dari Setting.
     */
    public function formatOptions(): array
    {
        return [
            CompetitionFormat::INDIVIDUAL_MASS => 'Massal',
            CompetitionFormat::INDIVIDUAL_VS_INDIVIDUAL => 'Individual vs Individual',
            CompetitionFormat::TEAM_VS_TEAM => 'Team vs Team',
            CompetitionFormat::TEAM_HEAT => 'Heat',
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
                'newEventId' => 'required|exists:events,id',
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
                'newHonorableMentionCount' => 'nullable|integer|min:0|max:100',
                'newTeamSize' => $this->teamSizeRule($this->newFormat),
                'newCompetitionCategoryId' => 'required|exists:competition_categories,id',
            ]);

            $event = Event::findOrFail($this->newEventId);

            if (! $event->isCompetition()) {
                $this->addError('newEventId', 'Lomba harus berupa event kompetisi.');

                return;
            }

            if (! CompetitionCategory::where('id', $this->newCompetitionCategoryId)->where('event_id', $event->id)->exists()) {
                $this->addError('newCompetitionCategoryId', 'Kategori harus berasal dari lomba yang dipilih.');

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
                'honorable_mention_count' => $this->newHonorableMentionCount !== '' ? (int) $this->newHonorableMentionCount : 0,
                'team_size' => $this->newTeamSize !== '' ? (int) $this->newTeamSize : null,
            ]);

            $this->showCreateForm = false;
            $this->reset(['newEventId', 'newName', 'newGender', 'newCode', 'newSortOrder', 'newResultType', 'newFormat', 'newWinnerCount', 'newHonorableMentionCount', 'newTeamSize', 'newCompetitionCategoryId']);
            session()->flash('success', 'Kelas berhasil dibuat.');
        } finally {
            $this->processing = false;
        }
    }

    public function formatRule(): string
    {
        return 'in:'.implode(',', array_keys($this->formatOptions()));
    }

    /**
     * Aturan tinjauan ukuran tim.
     *
     * Format Heat ("team_heat") adalah LOMBA BEREGU, jadi ukuran tim wajib
     * diisi lebih dari 1. Format lain tetap opsional.
     */
    public function teamSizeRule(string $uiFormat): array
    {
        if ($uiFormat === CompetitionFormat::TEAM_HEAT) {
            return ['required', 'integer', 'min:2', 'max:100'];
        }

        return ['nullable', 'integer', 'min:1', 'max:100'];
    }

    public function storedFormatRule(): string
    {
        return 'in:'.implode(',', array_merge(CompetitionFormat::ALL, ['individual_scoring']));
    }

    public function edit(int $id): void
    {
        $class = CompetitionClass::with('competitionCategory')->findOrFail($id);
        $this->editId = $class->id;
        $this->editEventId = (string) $class->event_id;
        $this->editName = $class->name;
        $this->editGender = $class->gender ?? '';
        $this->editCode = $class->code ?? '';
        $this->editSortOrder = $class->sort_order ?? '';
        $this->editResultType = $class->result_type ?? '';
        $this->editFormat = $this->uiFormat($class->format, $class->resultType());
        $this->editWinnerCount = (string) ($class->winner_count ?? 3);
        $this->editHonorableMentionCount = (string) ($class->honorable_mention_count ?? 0);
        $this->editTeamSize = (string) ($class->team_size ?? '');
        $this->editCompetitionCategoryId = (string) $class->competition_category_id;
    }

    public function update(): void
    {
        Gate::authorize('manage-events');

        $this->validate([
            'editEventId' => 'required|exists:events,id',
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
            'editHonorableMentionCount' => 'nullable|integer|min:0|max:100',
            'editTeamSize' => $this->teamSizeRule($this->editFormat),
            'editCompetitionCategoryId' => 'required|exists:competition_categories,id',
        ]);

        $class = CompetitionClass::findOrFail($this->editId);

        $event = Event::findOrFail($this->editEventId);

        if (! $event->isCompetition()) {
            $this->addError('editEventId', 'Lomba harus berupa event kompetisi.');

            return;
        }

        if (! CompetitionCategory::where('id', $this->editCompetitionCategoryId)->where('event_id', $event->id)->exists()) {
            $this->addError('editCompetitionCategoryId', 'Kategori harus berasal dari lomba yang dipilih.');

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
            'event_id' => $event->id,
            'competition_category_id' => $this->editCompetitionCategoryId,
            'name' => $this->editName,
            'gender' => $this->editGender ?: null,
            'code' => $this->editCode ?: null,
            'sort_order' => $this->editSortOrder !== '' ? (int) $this->editSortOrder : null,
            'format' => $format,
            'result_type' => $resultType,
            'winner_count' => $this->editWinnerCount !== '' ? (int) $this->editWinnerCount : 3,
            'honorable_mention_count' => $this->editHonorableMentionCount !== '' ? (int) $this->editHonorableMentionCount : 0,
            'team_size' => $this->editTeamSize !== '' ? (int) $this->editTeamSize : null,
        ]);

        $this->reset(['editId', 'editEventId', 'editName', 'editGender', 'editCode', 'editSortOrder', 'editResultType', 'editFormat', 'editWinnerCount', 'editHonorableMentionCount', 'editTeamSize', 'editCompetitionCategoryId']);
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
        $this->reset(['editId', 'editEventId', 'editName', 'editGender', 'editCode', 'editSortOrder', 'editResultType', 'editFormat', 'editWinnerCount', 'editHonorableMentionCount', 'editTeamSize', 'editCompetitionCategoryId']);
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-events');

        $class = CompetitionClass::findOrFail($id);
        $class->update(['is_active' => ! $class->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('manage-events');

        $class = CompetitionClass::findOrFail($id);

        if ($class->competitionRegistrations()->exists()) {
            session()->flash('error', 'Kelas tidak dapat dihapus karena masih memiliki pendaftaran peserta. Gunakan Nonaktifkan.');

            return;
        }

        if ($class->competitionSchedules()->exists()) {
            session()->flash('error', 'Kelas tidak dapat dihapus karena masih memiliki jadwal pertandingan. Gunakan Nonaktifkan.');

            return;
        }

        if ($class->competitionTeams()->exists()) {
            session()->flash('error', 'Kelas tidak dapat dihapus karena masih memiliki tim. Gunakan Nonaktifkan.');

            return;
        }

        if ($class->heatFormats()->exists()) {
            session()->flash('error', 'Kelas tidak dapat dihapus karena masih memiliki format heat. Gunakan Nonaktifkan.');

            return;
        }

        $class->delete();
        session()->flash('success', 'Kelas berhasil dihapus.');
    }

    public function render()
    {
        return view('livewire.competition.class.index', [
            'classes' => CompetitionClass::with('competitionCategory', 'event')
                ->whereHas('event', fn ($q) => $q->where('event_type', 'competition')->where('status', 'active'))
                ->when(
                    $this->filterEventId !== '' && $this->filterEventId !== '0',
                    fn ($q) => $q->where('event_id', (int) $this->filterEventId)
                )
                ->when(
                    $this->filterCategoryId !== '' && $this->filterCategoryId !== '0',
                    fn ($q) => $q->where('competition_category_id', (int) $this->filterCategoryId)
                )
                ->when(
                    $this->filterGender !== '' && $this->filterGender !== 'all',
                    fn ($q) => $q->where('gender', $this->filterGender)
                )
                ->when(
                    $this->filterFormat !== '' && $this->filterFormat !== 'all',
                    function ($q) {
                        if ($this->filterFormat === 'individual_scoring') {
                            $q->where('format', CompetitionFormat::INDIVIDUAL_MASS)
                                ->where('result_type', CompetitionResultType::SCORE);

                            return;
                        }

                        $q->where('format', $this->filterFormat);
                    }
                )
                ->when(
                    $this->filterStatus !== '' && $this->filterStatus !== 'all',
                    fn ($q) => $q->where('is_active', $this->filterStatus === 'active')
                )
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'events' => Event::where('event_type', 'competition')
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'categories' => $this->getCategoriesForEvent($this->newEventId),
            'editCategories' => $this->getCategoriesForEvent($this->editEventId),
        ]);
    }

    public function getCategoriesForEvent(string $eventId): \Illuminate\Support\Collection
    {
        if ($eventId === '' || $eventId === '0') {
            return collect();
        }

        return CompetitionCategory::where('event_id', $eventId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
