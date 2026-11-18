{{-- Tenant brand colors. Defaults reproduce the stock look, so unthemed tenants see no change. --}}
<style>
    :root {
        --theme-primary: {{ $activeTheme['primary_color'] }};
        --theme-secondary: {{ $activeTheme['secondary_color'] }};
        --theme-accent: {{ $activeTheme['accent_color'] }};
        --theme-text: {{ $activeTheme['text_color'] }};
        --theme-bg: {{ $activeTheme['background_color'] }};
        --theme-primary-dark: color-mix(in srgb, var(--theme-primary) 82%, black);
        --theme-accent-dark: color-mix(in srgb, var(--theme-accent) 82%, black);
        --theme-accent-soft: color-mix(in srgb, var(--theme-accent) 12%, white);
    }

    /* Page backdrop + header band */
    body .min-h-screen.bg-gray-100 { background-color: var(--theme-bg) !important; }
    body header.bg-white { background-color: var(--theme-secondary) !important; }
    body { color: var(--theme-text); }

    /* Primary buttons (Breeze gray-800) follow the tenant primary color */
    body .bg-gray-800 { background-color: var(--theme-primary) !important; }
    body .hover\:bg-gray-700:hover { background-color: var(--theme-primary-dark) !important; }
    body .focus\:bg-gray-700:focus { background-color: var(--theme-primary-dark) !important; }
    body .active\:bg-gray-900:active { background-color: var(--theme-primary-dark) !important; }

    /* Links + active states follow the accent color */
    body a.text-indigo-600 { color: var(--theme-accent) !important; }
    body a.hover\:text-indigo-900:hover { color: var(--theme-accent-dark) !important; }
    body .text-indigo-700 { color: var(--theme-accent) !important; }
    body .bg-indigo-50 { background-color: var(--theme-accent-soft) !important; }
    body .border-indigo-400 { border-color: var(--theme-accent) !important; }
    body .focus\:border-indigo-500:focus { border-color: var(--theme-accent) !important; }
    body .focus\:border-indigo-700:focus { border-color: var(--theme-accent-dark) !important; }
    body .focus\:ring-indigo-500:focus { --tw-ring-color: var(--theme-accent) !important; }

    /* Top navigation bar wears the primary color; dropdown panels stay white */
    .themed-nav { background-color: var(--theme-primary) !important; border-color: transparent !important; }
    .themed-nav a > svg.text-gray-800 { color: #fff !important; }
    .themed-nav-links a { color: rgba(255, 255, 255, 0.72) !important; }
    .themed-nav-links a:hover { color: #fff !important; border-color: rgba(255, 255, 255, 0.4) !important; }
    .themed-nav-links a.border-indigo-400 { color: #fff !important; border-color: var(--theme-accent) !important; }
    .themed-nav-trigger { color: rgba(255, 255, 255, 0.85) !important; }
    .themed-nav-trigger:hover { color: #fff !important; background-color: rgba(255, 255, 255, 0.12) !important; }
</style>
