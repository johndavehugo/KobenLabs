/**
 * Live Screens — the machine list, and the overlay that shows one machine's screen.
 *
 * The tiles carry no image. A minimised tile answers "which machine is this and who is
 * sitting at it", and the frame is fetched only while the overlay for that machine is on
 * screen. That is the whole reason this file is shaped as it is: forty enrolled PCs used
 * to pull roughly 4 MB/s of JPEGs to render pictures nobody had opened, because every
 * visible tile ran its own timer. One machine being watched now costs one machine's worth.
 *
 * So the tile registry below keeps no timers at all. It exists to answer "what is this
 * machine, and where do I fetch its screen", and the single overlay poller below does all
 * the fetching.
 */

/** Refresh cadence floor, in ms. Guards against a server asking for 0.05s. */
const MIN_INTERVAL_MS = 250

/**
 * @type {Map<string, object>}
 */
const machines = new Map()

/** Set once registration has run; this module is re-executed on Livewire navigation. */
const FLAG = 'kobenMonitoringRegistered'

/** How many frames the open overlay has fetched. Used to vary the request URL. */
let fetchCount = 0

/** Timer for the open overlay only. Null whenever no overlay is open. */
let overlayTimer = null

/** The machine the overlay is currently showing, or null. */
let openMachine = null

/** Guards against a slow frame overwriting a newer one. */
let requestInFlight = false

function intervalMs() {
    const grid = document.querySelector('[data-live-screen-tiles]')
    const seconds = Number.parseFloat(grid?.dataset.interval ?? '')

    if (! Number.isFinite(seconds) || seconds <= 0) {
        return 1000
    }

    return Math.max(MIN_INTERVAL_MS, seconds * 1000)
}

/**
 * Make each request for a frame a distinct URL.
 *
 * The frame path is stable — /admin/monitoring/frames/3 — so polling it unchanged asks
 * for a byte-identical URL and the browser is entitled to reuse the image it already
 * decoded. The endpoint sends `Cache-Control: private, no-store` (deliberately, so no
 * shared or back-button cache ever holds a student's screen), which means it may not
 * reuse those bytes either: the overlay goes blank for the length of a round trip, once
 * per interval, which reads as a strobe.
 *
 * A query parameter rather than a header, because the frame is fetched with `new Image()`
 * and an <img> src, and neither can carry one. `v` is inert to the route — the controller
 * reads the PC from the path and never inspects the query string.
 */
function cacheBusted(url, counter) {
    return url + (url.includes('?') ? '&' : '?') + 'v=' + counter
}

/**
 * Note that a frame was refused.
 *
 * Deliberately does nothing to the tile's badge. That badge is rendered by the server
 * from Filament's own palette, on a five-second poll, and this module used to overwrite it
 * with hand-written `text-success-700` / `bg-success-500` classes - none of which exist in
 * this stylesheet, because the app is built greyscale. So the first frame to arrive
 * replaced a correctly coloured badge with an uncoloured one that then stayed wrong until
 * the next poll corrected it, and a machine going quiet was reported late by up to five
 * seconds while the page insisted it was live.
 *
 * The overlay's own "no signal" message still reflects the fetch, since it is about what
 * this request returned rather than about the machine's enrolment.
 */
function noteFrameRefused() {
    // Intentionally empty. See above.
}

/**
 * One overlay refresh.
 *
 * Skipped while a request is already out, so a slow network cannot queue frames faster
 * than they arrive and paint them out of order.
 */
function tickOverlay() {
    if (! openMachine || requestInFlight) {
        return
    }

    const machine = openMachine
    const dialog = document.querySelector('[data-live-screen-solo]')
    const current = dialog?.querySelector('[data-live-solo-frame]')
    const placeholder = dialog?.querySelector('[data-live-solo-placeholder]')

    machine.placeholder = placeholder

    if (! current) {
        return
    }

    requestInFlight = true
    fetchCount += 1

    const image = new Image()

    image.onload = () => {
        requestInFlight = false

        if (openMachine !== machine) {
            return
        }

        // Swap the element rather than assigning to its src. Because `no-store` forbids
        // the browser from reusing the bytes it just fetched, `img.src = url` triggers a
        // second network round trip and the overlay shows nothing at all while it
        // happens — once per interval, which reads as a strobe. `new Image()` already
        // holds the decoded bitmap, so moving it into the DOM paints immediately: no
        // gap, no second request. The privacy decision is untouched, since the bytes
        // were fetched once and never stored.
        //
        // The marker attribute has to travel with the element. `current` is found by
        // querying for it, and the element that gets swapped in is a fresh Image — so
        // without this, the second tick finds no frame at all and the overlay freezes on
        // the first picture it ever received. Which is what happened.
        image.dataset.liveSoloFrame = ''
        image.className = 'h-full w-full object-contain'
        image.alt = `Screen of ${machine.nameEl?.textContent?.trim() || 'machine'}`
        image.decoding = 'sync'

        current.replaceWith(image)
        machine.soloFrame = image

        if (machine.placeholder) {
            machine.placeholder.classList.add('hidden')
        }

    }

    image.onerror = () => {
        requestInFlight = false

        if (openMachine !== machine) {
            return
        }

        // A 404 means the server refused, or the machine has no current frame. Say so
        // rather than leaving the last frame up, which would read as live.
        if (machine.placeholder) {
            machine.placeholder.classList.remove('hidden')
        }

        noteFrameRefused()
    }

    image.src = cacheBusted(machine.url, fetchCount)
}

function openOverlay(machine, dialog) {
    openMachine = machine

    if (machine.placeholder) {
        machine.placeholder.classList.remove('hidden')
    }

    const label = dialog.querySelector('[data-live-solo-label]')
    const occupant = machine.studentEl?.textContent?.trim() ?? ''
    const name = machine.nameEl?.textContent?.trim() ?? 'Machine'

    if (label) {
        label.textContent = occupant && occupant !== 'Free' ? `${name} — ${occupant}` : name
    }

    // Re-fetch immediately rather than waiting out the current interval: a teacher who
    // has just clicked a machine is looking at it now.
    tickOverlay()

    if (overlayTimer !== null) {
        window.clearInterval(overlayTimer)
    }

    overlayTimer = window.setInterval(tickOverlay, intervalMs())

    dialog.showModal()
}

function closeOverlay(dialog) {
    openMachine = null
    requestInFlight = false

    if (overlayTimer !== null) {
        window.clearInterval(overlayTimer)
        overlayTimer = null
    }

    const current = dialog?.querySelector('[data-live-solo-frame]')

    if (current) {
        current.removeAttribute('src')
    }
}

/**
 * Read the tiles off the page.
 *
 * Rebuilt from scratch on every pass rather than merged, because Livewire's polling
 * replaces the tile elements: an entry merged into the previous map would keep pointing
 * at a detached node, and its status updates would go nowhere while appearing to work.
 * Elements that survive a morph are already wired, so each is checked against the set.
 */
function syncMachines() {
    const grid = document.querySelector('[data-live-screen-tiles]')

    if (! grid) {
        machines.clear()

        return
    }

    const seen = new Set()

    for (const element of grid.querySelectorAll('.live-screen-tile')) {
        const id = element.dataset.pcId

        if (! id) {
            continue
        }

        seen.add(id)

        const existing = machines.get(id)

        if (existing && existing.element === element) {
            continue
        }

        const machine = {
            id,
            element,
            url: element.dataset.frameUrl,
            nameEl: element.querySelector('[data-live-screen-name]'),
            studentEl: element.querySelector('[data-live-screen-student]'),
            statusLive: null,
            statusEnrolled: true,
            soloFrame: null,
        }

        // Enrolment is no longer read back out of the badge. The badge is rendered by the
        // server as a Filament badge from its own palette, so there is no data- attribute
        // to read and nothing for this module to keep in step - it refreshes on the page's
        // five-second poll instead.
        machines.set(id, machine)

        if (! wired.has(element)) {
            wired.add(element)
            element.addEventListener('click', () => {
                const dialog = document.querySelector('[data-live-screen-solo]')

                if (dialog) {
                    openOverlay(machines.get(id) ?? machine, dialog)
                }
            })
        }
    }

    for (const id of [...machines.keys()]) {
        if (! seen.has(id)) {
            machines.delete(id)
        }
    }
}

/**
 * Tiles whose click listener is already attached.
 *
 * A WeakSet rather than a `data-` flag on the element. The attribute would be another
 * thing the Blade has to supply for no reason — the flag is bookkeeping for this module,
 * not state the server renders — and a WeakSet drops its entries when the element does,
 * which is the same lifetime the entry needs.
 */
const wired = new WeakSet()

/**
 * Rebuild after a Livewire navigation or a poll response.
 *
 * The previous overlay timer is cleared first. Otherwise each visit to this page leaves
 * its interval running against detached DOM, which is a slow leak of network activity
 * that never shows up as a bug — it just quietly keeps fetching a screen nobody is
 * looking at.
 */
function boot() {
    // Deliberately does NOT close an open overlay.
    //
    // It used to start with `closeOverlay(...)`, on the reasonable assumption that boot()
    // only runs when the page is first entered. It does not: boot() is also wired to
    // `EchoLoaded`, and Reverb takes a couple of seconds to connect. So a teacher would
    // click a machine, and two or three seconds later — exactly as the websocket came up —
    // the overlay would shut itself again. The symptom reads as "it opens and then closes
    // by itself"; the cause was a websocket arriving, not a click.
    //
    // Leaving the page is handled by `livewire:navigating`, which is the only event that
    // actually means "this is no longer the page being looked at".
    syncMachines()

    const dialog = document.querySelector('[data-live-screen-solo]')

    if (! dialog) {
        return
    }

    dialog.querySelector('[data-live-solo-close]')?.addEventListener('click', () => dialog.close())

    // Clicking the backdrop closes, which is what people expect of a full-bleed overlay.
    //
    // Tested against the content box rather than against `event.target === dialog`. The
    // dialog is full-bleed and its content does not fill it, so every click on the dark
    // area around the screen — which is most of the overlay, and is where the pointer
    // naturally lands when someone reaches in to enlarge or click the image — resolved to
    // the dialog element itself. Checking the coordinates against the content's own
    // rectangle means only a click genuinely outside the panel closes it.
    dialog.addEventListener('click', (event) => {
        const content = dialog.firstElementChild

        if (! content) {
            if (event.target === dialog) {
                dialog.close()
            }

            return
        }

        const box = content.getBoundingClientRect()
        const outside =
            event.clientX < box.left ||
            event.clientX > box.right ||
            event.clientY < box.top ||
            event.clientY > box.bottom

        if (outside) {
            dialog.close()
        }
    })

    dialog.addEventListener('close', () => closeOverlay(dialog))

    window.Echo?.private('monitoring')
        ?.listen('.screen-frame.available', (payload) => {
            // Only the machine on screen is worth acting on. A teacher watching one
            // machine should not pay to refresh thirty-nine they cannot see.
            if (openMachine && String(payload?.pc_id) === openMachine.id) {
                tickOverlay()
            }
        })
}

export function registerLiveScreens() {
    if (window[FLAG]) {
        return
    }

    window[FLAG] = true

    window.addEventListener('EchoLoaded', boot)
    document.addEventListener('livewire:navigated', boot)

    // Leaving the page must stop the requests, or a teacher who browses away keeps
    // fetching a screen nobody is looking at.
    document.addEventListener('livewire:navigating', () => {
        closeOverlay(document.querySelector('[data-live-screen-solo]'))
    })

    // The tiles re-render on every poll, so the registry is rebuilt after each one rather
    // than only on navigation.
    window.Livewire.hook('morph.updated', (el) => {
        if (el?.getAttribute?.('data-live-screen-tiles') !== null) {
            syncMachines()
        }
    })

    window.Livewire.hook('component.init', boot)

    boot()
}

registerLiveScreens()

// Exported for the overlay's own frame element lookup; keeps the module testable without
// reaching into the DOM from a test.
export const __testing = { cacheBusted, intervalMs, syncMachines, machines }