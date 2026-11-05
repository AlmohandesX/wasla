<?php

// Only overrides; every other Livewire option keeps the package default.
return [
    // Livewire would otherwise inject inline styles and scripts into every full HTML page, which the strict CSP on
    // the public and owner pages forbids. Filament's own layout includes the Livewire assets under the /admin CSP.
    'inject_assets' => false,
];
