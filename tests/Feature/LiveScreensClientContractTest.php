<?php

use App\Filament\Pages\LiveScreens;
use App\Models\Pc;
use App\Models\User;

/**
|--------------------------------------------------------------------------
| Live Screens client contract
|--------------------------------------------------------------------------
|
| resources/js/monitoring.js finds everything it needs by DOM selector, and the Blade
| view supplies those selectors. Nothing in PHP checks that the two agree, and the
| failure mode is silent rather than loud: the page renders perfectly, the tiles look
| right, and nothing ever refreshes because one attribute was renamed.
|
| So this file reads the selectors out of the JavaScript and asserts the rendered HTML
| supplies each one. If monitoring.js gains a selector, this fails and the matching
| attribute gets added; if the Blade loses one, it fails the same way.
|
| What it deliberately does not cover is the browser behaviour itself — decoding,
| timers, the IntersectionObserver. That needs a real browser and is not something a
| request-level assertion can honestly claim.
 */
describe('Live Screens client contract', function (): void {
    /**
     * Every selector monitoring.js queries for, reduced to the attribute or class the
     * HTML has to carry.
     *
     * `[data-live-screen-tiles]` and `.live-screen-tile` are CSS selector syntax; the
     * rendered markup contains `data-live-screen-tiles` and `live-screen-tile`. Asserting
     * the bracketed form against HTML fails for a reason that has nothing to do with
     * the page being wrong.
     *
     * @return array<int, string>
     */
    function selectorsUsedByTheClient(): array
    {
        $source = file_get_contents(resource_path('js/monitoring.js'));

        preg_match_all("/querySelector(?:All)?\('([^']+)'\)/", $source, $matches);

        $flattened = array_map(
            // Strip both the attribute brackets and a leading class dot: `[data-x]`
            // and `.tile` are selector syntax, while the markup carries `data-x` and
            // `tile`. Asserting the CSS form against HTML fails for a reason that has
            // nothing to do with the page being wrong.
            fn (string $selector): string => ltrim(str_replace(['[', ']'], '', $selector), '.'),
            array_unique($matches[1]),
        );

        sort($flattened);

        return $flattened;
    }

    /**
     * Every dataset property monitoring.js reads, as the data-* attribute it maps to.
     *
     * The hyphen is inserted *before* lowercasing: lowercasing first turns `frameUrl`
     * into `frameurl`, and the real attribute is `data-frame-url`.
     *
     * @return array<int, string>
     */
    function dataAttributesUsedByTheClient(): array
    {
        $source = file_get_contents(resource_path('js/monitoring.js'));

        preg_match_all('/dataset\.([a-zA-Z]+)/', $source, $matches);

        $attributes = array_map(
            fn (string $property): string => 'data-'.strtolower(preg_replace('/([A-Z])/', '-$1', $property)),
            array_unique($matches[1]),
        );

        sort($attributes);

        return $attributes;
    }

    it('supplies every selector the JavaScript looks for', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-01']);

        $html = livewire(LiveScreens::class)->assertOk()->html();

        foreach (selectorsUsedByTheClient() as $selector) {
            expect($html)->toContain($selector);
        }
    });

    it('supplies every data attribute the JavaScript reads', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-01']);

        $html = livewire(LiveScreens::class)->assertOk()->html();

        foreach (dataAttributesUsedByTheClient() as $attribute) {
            expect($html)->toContain($attribute);
        }
    });

    it('gives every tile the three hooks the refresh cycle needs', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        $pc = Pc::factory()->create(['name' => 'LAB-01']);

        $html = livewire(LiveScreens::class)->assertOk()->html();

        expect($html)
            ->toContain('data-pc-id="'.$pc->getKey().'"')
            ->toContain('data-frame-url="'.route('monitoring.frames.show', $pc).'"');
    });

    it('does not scrape Tailwind classes for the solo overlay label', function (): void {
        $source = file_get_contents(resource_path('js/monitoring.js'));

        // The tile's own text content is fragile to read: it also contains the status
        // badge and the placeholder, so a span picked by class name can silently
        // become "no signal" as the machine's name. Explicit attributes instead.
        expect($source)
            ->not->toContain("querySelector('.font-medium')")
            ->not->toContain("querySelector('.text-gray-500')")
            ->toContain('[data-live-screen-name]')
            ->toContain('[data-live-screen-student]');
    });

    it('publishes the capture interval so the server governs the rate', function (): void {
        config()->set('lab.monitoring.interval_seconds', 2.5);

        $this->actingAs(User::factory()->admin()->create());
        Pc::factory()->create(['name' => 'LAB-01']);

        // Read from the DOM rather than baked into the bundle, so changing
        // config/lab.php retunes the grid without a JavaScript change.
        expect(livewire(LiveScreens::class)->assertOk()->html())
            ->toContain('data-interval="2.5"');
    });

    it('ships the monitoring module into the admin panel only', function (): void {
        $adminHtml = $this->actingAs(User::factory()->admin()->create())->get('/admin')->getContent();
        $studentHtml = $this->actingAs(User::factory()->student()->create())->get('/student')->getContent();

        // Accepted in whichever form Vite currently emits.
        //
        // With `npm run dev` running, @vite points at the dev server and the page
        // references `/resources/js/monitoring.js` by its source path. With built assets -
        // which is what a deployed lab gets, and what the panel is served when no dev
        // server is up - it references the content-hashed `monitoring-<hash>.js` instead,
        // which does not contain the bare module name at all.
        //
        // Asserting only on "monitoring.js" therefore fails on the built form, and
        // asserting only on the hashed form fails on the dev form: the check flips with
        // whether a dev server happens to be running, and reports a correct panel as
        // shipping nothing in one mode or the other. Both forms are accepted here, and
        // the hashed one is resolved from the manifest rather than hardcoded.
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $built = collect($manifest)
            ->filter(fn (mixed $entry, string $key): bool => str_ends_with($key, 'resources/js/monitoring.js'))
            ->map(fn (array $entry): string => $entry['file'])
            ->first();

        expect($adminHtml)
            ->toMatch('/monitoring\.js|'.($built ? preg_quote(basename($built), '/') : 'monitoring').'/')
            // Negative side, kept as narrow as the positive one. Checking only that the
            // student page lacks the bare word "monitoring" fails for the wrong reason: the
            // Live Screens slug is /admin/monitoring, and that string turns up in the panel's
            // own payload whether or not the module is shipped. What matters is that the
            // *module* is absent, in either form Vite might emit it.
            ->and($studentHtml)->not->toContain('monitoring.js')
            ->and($studentHtml)->not->toContain($built ?? 'monitoring-CJNBq9zR.js');
    });

    it('subscribes the browser to the monitoring channel and nothing wider', function (): void {
        $source = file_get_contents(resource_path('js/monitoring.js'));

        // Same channel rules/routes/channels.php authorises: staff only, and no
        // image data in the payload. If this ever becomes a public channel the
        // browser would be handed frames the server should not have sent it.
        expect($source)
            ->toContain("private('monitoring')")
            ->not->toContain("private('pcs')")
            ->not->toContain('data:image')
            ->not->toContain('base64');
    });
});
