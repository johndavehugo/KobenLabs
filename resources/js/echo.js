/**
 * Realtime listeners for the Filament panels.
 *
 * Filament already builds `window.Echo` for us (config/filament.php) and fires an
 * `EchoLoaded` event once it exists, so nothing here needs to import Echo or
 * Pusher - the whole point is that the websocket client is Filament's, not ours.
 *
 * What this file does NOT do:
 *
 * - It never renders anything. A broadcast only asks Livewire to re-render, and the
 *   server decides what that looks like. Trusting the broadcast payload to build DOM
 *   would mean trusting Reverb with row content.
 * - It never opens a channel it was not told to. Channel names come from the server,
 *   so a student cannot talk a browser into subscribing to a group they were removed
 *   from - the server would refuse the auth request anyway, but we should not ask.
 *
 * Channels and who may have them (enforced in routes/channels.php):
 *
 *   pcs             staff only
 *   class-sessions  any authenticated user, for the running class
 *   groups.{id}     members of that group, or staff
 */

/**
 * Livewire listens by event name. Broadcasting to every subscriber of a channel would
 * mean every PC row re-rendered for one PC's status change, so listeners are
 * per-topic and each dispatches one narrow Livewire event.
 */
const topics = {
    'private-pcs': {
        '.pc.status.updated': 'koben:pc-status-updated',
    },
    'private-class-sessions': {
        '.class-session.started': 'koben:class-session-started',
        '.class-session.ended': 'koben:class-session-ended',
        '.student.joined-class': 'koben:class-session-started',
    },
}

/** Group chat is per-group, and the group changes as the user picks a transcript. */
const groupTopic = '.group-chat.message.sent'

/** Channels already opened, so a re-run cannot stack duplicate subscriptions. */
let joined = new Set()

/**
 * Subscribe to one group's chat channel. Called from {@link syncGroupChannel}, which
 * knows which group the page is showing.
 */
export function listenForGroup(groupId) {
    const echo = window.Echo

    if (! echo || ! groupId) {
        return
    }

    const channel = `private-groups.${groupId}`

    if (joined.has(channel)) {
        return
    }

    joined.add(channel)

    echo.private(channel).listen(groupTopic, () => {
        window.Livewire.dispatch('koben:group-message-sent')
    })
}

/**
 * Close every group subscription.
 *
 * Channel authorization is checked when a subscription is opened, not per message, so
 * a member removed by staff would keep receiving that group's chat until the tab was
 * reloaded. Dropping the channel on navigation closes that window. The pc and
 * class-session channels are left alone: they are role-scoped rather than
 * membership-scoped, so they cannot outlive access the user does not have.
 */
function leaveGroupChannels() {
    for (const channel of [...joined]) {
        if (! channel.startsWith('private-groups.')) {
            continue
        }

        joined.delete(channel)
        window.Echo?.leave(channel)
    }
}

/**
 * Which group is on screen, read from Livewire component state.
 *
 * Read from state rather than announced by the page: an announcement would have to be
 * dispatched from `mount()`, and that runs before this module is registered (Filament
 * builds Echo and starts Livewire above SCRIPTS_AFTER), so on a first page load it
 * would be dispatched into nothing. State works on first load, on navigation, and
 * after the Select switches group.
 */
function groupIdFromComponents() {
    const components = window.Livewire?.all?.() ?? []

    for (const component of components) {
        const groupId = component?.ephemeral?.groupId

        if (groupId) {
            return groupId
        }
    }

    return null
}

/** Open whichever group channel the page needs. Safe to call repeatedly. */
export function syncGroupChannel() {
    listenForGroup(groupIdFromComponents())
}

/** Open the role-scoped channels, once each. */
function joinSharedChannels() {
    const echo = window.Echo

    if (! echo) {
        return
    }

    for (const [channel, events] of Object.entries(topics)) {
        if (joined.has(channel)) {
            continue
        }

        joined.add(channel)

        // private() both authorizes and subscribes, and returns the channel. Calling
        // it once and chaining listen() keeps this to a single subscription -
        // re-fetching with echo.channel() would add a second, public one.
        const subscription = echo.private(channel)

        for (const [broadcasted, livewireEvent] of Object.entries(events)) {
            subscription.listen(broadcasted, () => {
                window.Livewire.dispatch(livewireEvent)
            })
        }
    }
}

/**
 * Re-assert whatever channels the current page needs.
 *
 * Called on first load, on every Livewire navigation, and whenever Echo announces
 * itself - the order of those is not something this file controls.
 */
function boot() {
    joinSharedChannels()
    syncGroupChannel()
}

/**
 * Register the listeners once per page load.
 *
 * Guarded because Vite tags this file `data-navigate-track`, and Livewire navigation
 * re-runs module scripts. Without the guard every navigation would add another set of
 * document listeners and Livewire hooks, and they would all fire - one Echo dispatch
 * would trigger N Livewire refreshes, compounding with each page visited.
 */
export function registerEchoListeners() {
    if (window.kobenEchoRegistered) {
        return
    }

    window.kobenEchoRegistered = true

    // `EchoLoaded` is dispatched by Filament's base layout on the page load that
    // built Echo. On a Livewire navigation Echo already exists, so boot immediately
    // as well; `joined` makes the second call a no-op.
    window.addEventListener('EchoLoaded', boot)
    document.addEventListener('livewire:navigated', boot)
    document.addEventListener('livewire:navigating', leaveGroupChannels)

    // Switching group in the Select re-points the subscription without a navigation.
    window.Livewire.hook('component.updated', ({ component }) => {
        if (component?.ephemeral?.groupId !== undefined) {
            syncGroupChannel()
        }
    })

    // A lazily-loaded component can appear after boot.
    window.Livewire.hook('component.init', syncGroupChannel)

    boot()
}

registerEchoListeners()

if (typeof window !== 'undefined') {
    window.kobenEcho = { listenForGroup, syncGroupChannel }
}