/**
 * Group chat typing.
 *
 * Watches the composer and asks the server, once a second, that this person is typing.
 * The server throttles the broadcast anyway; the throttle here is what stops a burst of
 * keydowns becoming a burst of round trips in the first place.
 *
 * Nothing is rendered here. The name shown comes from the broadcast - from the *other*
 * people typing - and is drawn into `[data-typing-indicator]` by echo.js, which already
 * owns the group channel subscription. This module's whole job is deciding when to speak.
 */
export function registerChatTyping() {
    if (window.kobenChatTypingRegistered) {
        return
    }

    window.kobenChatTypingRegistered = true

    // Read once per bind rather than on every keystroke: the composer is replaced whenever
    // the component re-renders, so a listener attached to the element it replaced would
    // stop firing without any visible sign.
    const bind = () => {
        const composer = document.querySelector('[data-chat-draft]')

        if (! composer || composer.dataset.typingBound === '1') {
            return
        }

        composer.dataset.typingBound = '1'

        composer.addEventListener('input', () => {
            // An emptied box means the message was sent or cleared, so stop claiming to be
            // mid-sentence.
            if (composer.value.trim() === '') {
                return
            }

            window.Livewire.dispatch('koben:chat-typing')
        })
    }

    bind()

    document.addEventListener('livewire:navigated', bind)
    window.Livewire?.hook('morph.updated', bind)
    window.Livewire?.hook('component.init', bind)
}

/**
 * Read the typing context the server put on the page.
 *
 * An inline <script> would do this, and would also mean a user id and a timeout embedded
 * in markup on every render. Reading the attributes the Blade already emitted keeps the
 * page free of script and means the values cannot disagree with what the server rendered.
 */
export function readTypingContext() {
    const indicator = document.querySelector('[data-typing-indicator]')

    window.kobenEchoUserId = indicator?.dataset.typingUserId
        ? Number(indicator.dataset.typingUserId)
        : null

    window.kobenTypingTtl = indicator?.dataset.typingTtl
        ? Number(indicator.dataset.typingTtl)
        : 6
}

registerChatTyping()