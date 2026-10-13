<div
    role="alert"
    class="rounded-lg border border-danger-500/40 bg-danger-500/10 p-4 text-sm text-gray-950 dark:text-white"
>
    <p class="font-semibold">
        Live updates are off: the Reverb credentials are blank.
    </p>

    <p class="mt-1 text-gray-600 dark:text-gray-300">
        Typing indicators, roster updates, screen signals, and PC status will
        silently stay frozen until this is fixed. Run
        <code class="rounded bg-gray-950/5 px-1 dark:bg-white/10">php artisan app:setup-reverb-env</code>,
        then
        <code class="rounded bg-gray-950/5 px-1 dark:bg-white/10">npm run build</code>
        so browsers pick up the new values. Details in
        <code class="rounded bg-gray-950/5 px-1 dark:bg-white/10">docs/installation.md</code>.
    </p>
</div>
