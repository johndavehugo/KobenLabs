{{--
    Realtime listeners for this panel.

    Registered at PanelsRenderHook::SCRIPTS_AFTER, which is emitted *after*
    Filament's own `@filamentScripts` and after the inline block that builds
    `window.Echo`. Loading it anywhere earlier would mean the module ran before
    the client existed.

    echo.js still listens for `EchoLoaded` rather than relying on that ordering,
    because a Livewire navigation swaps the page without re-running this tag.
--}}
@vite(['resources/js/echo.js', 'resources/js/chat-typing.js'])