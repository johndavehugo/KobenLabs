{{--
    The application stylesheet for the Filament panels.

    Rendered from a STYLES_AFTER hook in AppServiceProvider rather than with the panel's
    `viteTheme()`, which replaces the panel's stylesheet registration instead of adding to
    it and so removed Filament's own CSS along with the gap it was meant to fill.

    A view rather than a returned string, because a plain string is output literally and
    `@vite` is only compiled when Blade renders it - the same reason the neighbouring
    script hook renders views instead of concatenating tags.
--}}
@vite('resources/css/panel.css')