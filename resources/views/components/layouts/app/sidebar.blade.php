<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" >
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @php
            $activeEvent = app(\App\Support\ActiveEventContext::class)->current();
        @endphp
        <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="/" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                <span class="text-lg font-bold text-zinc-900 dark:text-white">AplikasiLomba</span>
            </a>

            @if ($activeEvent)
                <div class="px-3 py-2 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $activeEvent->name }}
                </div>

                <flux:navlist variant="outline">
                    {{-- 1. Registrasi --}}
                    <flux:navlist.group expandable heading="Registrasi" class="grid">
                        <flux:navlist.item icon="user-plus" :href="route('competition.registration', absolute: false)" :current="request()->routeIs('competition.registration')" wire:navigate>{{ __('Registrasi Peserta') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('competition.participants', absolute: false)" :current="request()->routeIs('competition.participants')" wire:navigate>{{ __('Daftar Peserta') }}</flux:navlist.item>
                    </flux:navlist.group>

                    {{-- 2. Setting --}}
                    <flux:navlist.group expandable heading="Setting" class="grid">
                        <flux:navlist.item icon="cog-6-tooth" :href="route('competition.category.index', absolute: false)" :current="request()->routeIs('competition.category.index')" wire:navigate>{{ __('Kategori') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('competition.class.index', absolute: false)" :current="request()->routeIs('competition.class.index')" wire:navigate>{{ __('Kelas') }}</flux:navlist.item>
                    </flux:navlist.group>

                    {{-- 3. Pembagian Tim --}}
                    <flux:navlist.group expandable heading="Pembagian Tim" class="grid">
                        <flux:navlist.item icon="users" :href="route('competition.teams', absolute: false)" :current="request()->routeIs('competition.teams')" wire:navigate>{{ __('Tim') }}</flux:navlist.item>
                    </flux:navlist.group>

                    {{-- 4. Lomba --}}
                    <flux:navlist.group expandable heading="Lomba" class="grid">
                        <flux:navlist.item icon="calendar" :href="route('competition.heat.index', absolute: false)" :current="request()->routeIs('competition.heat.index')" wire:navigate>{{ __('Heat') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('competition.bracket-manager', absolute: false)" :current="request()->routeIs('competition.bracket-manager')" wire:navigate>{{ __('Bracket') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('competition.match-center', absolute: false)" :current="request()->routeIs('competition.match-center')" wire:navigate>{{ __('Match Center') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('competition.operator-dashboard', absolute: false)" :current="request()->routeIs('competition.operator-dashboard')" wire:navigate>{{ __('Operator') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('competition.official-panel', absolute: false)" :current="request()->routeIs('competition.official-panel')" wire:navigate>{{ __('Official Panel') }}</flux:navlist.item>
                    </flux:navlist.group>
                </flux:navlist>
            @else
                <div class="px-3 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    <p>Tidak ada event aktif.</p>
                </div>
            @endif

            <flux:spacer />
        </flux:sidebar>

        <!-- Mobile Header -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            <flux:spacer />
            <span class="text-sm font-semibold text-zinc-900 dark:text-white">AplikasiLomba</span>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
