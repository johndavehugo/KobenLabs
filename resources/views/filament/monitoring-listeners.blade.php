{{--
    Live Screens client, staff panel only.

    Registered separately from echo-listeners because it is only meaningful where a
    Live Screens grid can exist. Loading it on the student panel would subscribe
    nothing and find no tiles, and the student panel should not carry a viewer for
    staff screens at all.
--}}
@vite('resources/js/monitoring.js')