@props(['name' => null])

<svg viewBox="0 0 24 24" aria-hidden="true">
    @switch($name)
        @case('grid')
            <rect x="3" y="3" width="7" height="7" rx="1.5"/>
            <rect x="14" y="3" width="7" height="7" rx="1.5"/>
            <rect x="3" y="14" width="7" height="7" rx="1.5"/>
            <rect x="14" y="14" width="7" height="7" rx="1.5"/>
            @break

        @case('calendar')
            <rect x="3" y="4.5" width="18" height="16.5" rx="2"/>
            <path d="M8 2v5M16 2v5M3 10h18"/>
            @break

        @case('chart')
            <path d="M4 20V11M10 20V4M16 20v-6M22 20H2"/>
            @break

        @case('settings')
            <path d="M4 6h16M4 12h16M4 18h16"/>
            <circle cx="9" cy="6" r="2.2"/>
            <circle cx="15" cy="12" r="2.2"/>
            <circle cx="7" cy="18" r="2.2"/>
            @break

        @case('users')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13A4 4 0 0 1 16 11"/>
            @break

        @case('switch')
            <path d="M4 8h13l-3-3M20 16H7l3 3"/>
            @break

        @case('external')
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
            <path d="M15 3h6v6M10 14 21 3"/>
            @break

        @case('logout')
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <path d="M16 17l5-5-5-5M21 12H9"/>
            @break

        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16"/>
            @break

        @case('chevrons-left')
            <path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/>
            @break
    @endswitch
</svg>
