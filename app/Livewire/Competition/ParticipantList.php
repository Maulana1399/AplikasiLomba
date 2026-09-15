<?php

namespace App\Livewire\Competition;

use App\Models\CompetitionCategory;
use App\Models\CompetitionClass;
use App\Models\CompetitionRegistration;
use App\Models\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ParticipantList extends Component
{
    /**
     * Penanda struktural cabang lomba FASDA.
     *
     * Fasda2026Seeder membuat competition_category & competition_class
     * dengan `code` berawalan "F26-" (lihat categoryCode()/classCode()).
     * Event competition lain (UAT dummy, dummy aplikasi lomba, dll.)
     * memakai code "uat-*", "TNX", atau null — sehingga tidak masuk
     * dropdown Lomba, sekalipun namanya sama dengan lomba FASDA
     * (mis. "Khotbah" milik UAT vs "Khotbah" FASDA).
     */
    private const FASDA_CLASS_CODE_PREFIX = 'F26-';

    public string $competitionId = '';

    public string $competitionCategoryId = '';

    public string $competitionClassId = '';

    public function mount(): void
    {
        Gate::authorize('view-dashboard');
    }

    public function updatedCompetitionId($value): void
    {
        $this->competitionCategoryId = 'all';
        $this->competitionClassId = '';
    }

    public function updatedCompetitionCategoryId($value): void
    {
        $this->competitionClassId = '';
    }

    public function isShowingAllCompetition(): bool
    {
        return $this->competitionId === 'all';
    }

    public function isShowingAllCategory(): bool
    {
        return $this->competitionCategoryId === 'all';
    }

    public function getCompetitionsProperty()
    {
        return Event::query()
            ->where('event_type', 'competition')
            ->where('status', 'active')
            ->whereHas(
                'competitionClasses',
                fn ($query) => $query
                    ->where('is_active', true)
                    ->where(
                        'code',
                        'like',
                        self::FASDA_CLASS_CODE_PREFIX.'%'
                    )
            )
            ->orderBy('name')
            ->get();
    }

    public function getCategoriesProperty()
    {
        if (
            $this->competitionId === '' ||
            $this->competitionId === 'all'
        ) {
            return collect();
        }

        $eventId = (int) $this->competitionId;

        return CompetitionCategory::query()
            ->where(
                'is_active',
                true
            )
            ->where(
                fn ($query) => $query
                    ->where(
                        'event_id',
                        $eventId
                    )
                    ->orWhereHas(
                        'events',
                        fn ($query) => $query->where(
                            'events.id',
                            $eventId
                        )
                    )
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getClassesProperty()
    {
        if (
            $this->competitionId === '' ||
            $this->competitionId === 'all' ||
            $this->competitionCategoryId === '' ||
            $this->competitionCategoryId === 'all'
        ) {
            return collect();
        }

        return CompetitionClass::query()
            ->where(
                'event_id',
                (int) $this->competitionId
            )
            ->where(
                'competition_category_id',
                (int) $this->competitionCategoryId
            )
            ->where(
                'is_active',
                true
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view(
            'livewire.competition.participant-list',
            [
                'registrations' => $this->filteredRegistrations(),
                'competitions' => $this->competitions,
                'categories' => $this->categories,
                'classes' => $this->classes,
                'showingAllCompetition' =>
                    $this->isShowingAllCompetition(),
                'showingAllCategory' =>
                    $this->isShowingAllCategory(),
            ]
        );
    }

    /*
     * ================================================================
     * Registrasi SELALU di-anchor ke `participation.event_id`
     * (event asli peserta), PLUS dicek ulang ke `competition_class`.
     *
     * `competition_classes` GLOBAL (unique per category+name, bisa
     * dirujuk lintas event), jadi `competition_class.event_id` SAJA
     * tidak cukup: registrasi peserta event UAT yang menunjuk class
     * milik event Khotbah akan bocor. Gunakan participation.event_id
     * sebagai batas event yang benar. Tidak ada fallback ke event
     * lain; hasil kosong dibiarkan kosong.
     * ================================================================
     */
    private function filteredRegistrations()
    {
        /*
         * SEMUA LOMBA — registrasi dari event yang memang ada di
         * $competitions (participation = asal peserta sebenarnya).
         */
        if ($this->competitionId === 'all') {
            $competitionIds = $this->competitions
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($competitionIds === []) {
                return collect();
            }

            return $this->registrationQuery()
                ->whereHas(
                    'participation',
                    fn ($query) => $query->whereIn(
                        'event_id',
                        $competitionIds
                    )
                )
                ->orderBy('id')
                ->get();
        }

        if ($this->competitionId === '') {
            return collect();
        }

        $eventId = (int) $this->competitionId;

        $eventAnchor = fn ($query) => $query->where('event_id', $eventId);

        /*
         * SATU LOMBA — SEMUA KATEGORI — HANYA dari event terpilih,
         * dilihat dari participation peserta (bukan class).
         */
        if ($this->competitionCategoryId === 'all') {
            return $this->registrationQuery()
                ->whereHas('participation', $eventAnchor)
                ->whereHas(
                    'competitionClass',
                    fn ($query) => $query->where('event_id', $eventId)
                )
                ->orderBy('id')
                ->get();
        }

        /*
         * SATU LOMBA + KATEGORI + KELAS — validasi BOTH via
         * competition_class: event + category + class, dan tetap
         * di-anchor ke participation.event_id.
         */
        if (
            $this->competitionCategoryId !== '' &&
            $this->competitionClassId !== ''
        ) {
            return $this->registrationQuery()
                ->whereHas('participation', $eventAnchor)
                ->whereHas(
                    'competitionClass',
                    fn ($query) => $query
                        ->where('event_id', $eventId)
                        ->where(
                            'competition_category_id',
                            (int) $this->competitionCategoryId
                        )
                        ->where(
                            'id',
                            (int) $this->competitionClassId
                        )
                )
                ->orderBy('id')
                ->get();
        }

        return collect();
    }

    private function registrationQuery()
    {
        return CompetitionRegistration::query()
            ->with([
                'participation.person.desa',
                'participation.person.kelompok',
                'competitionCategory',
                'competitionClass',
            ]);
    }
}