import { distance } from './geo';

const SEND_AFTER_METRES = 25;
const SEND_EVERY = 30000;

/**
 * On the page of a cabinet, follows where the person is and gives it to the page so each box shows its distance.
 * Nothing is kept: the position only lives in the open page. The high-accuracy tracking stops while the page is
 * hidden (screen off, app in the background) and starts again when it comes back.
 */
export default () => ({
    watch: null,
    last: null,
    sentAt: 0,
    denied: false,
    deniedText: '',
    onVisibility: null,

    init() {
        this.deniedText = this.$el.dataset.denied ?? '';

        if (!navigator.geolocation) {
            return;
        }

        this.onVisibility = () => (document.hidden ? this.stop() : this.begin());
        document.addEventListener('visibilitychange', this.onVisibility);

        if (!document.hidden) {
            this.begin();
        }
    },

    begin() {
        this.watch ??= navigator.geolocation.watchPosition(
            ({ coords }) => this.found(coords),
            (error) => { this.denied = error.code === 1; },
            { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 },
        );
    },

    stop() {
        if (this.watch !== null) {
            navigator.geolocation.clearWatch(this.watch);
            this.watch = null;
        }
    },

    destroy() {
        this.stop();

        if (this.onVisibility !== null) {
            document.removeEventListener('visibilitychange', this.onVisibility);
        }
    },

    found(coords) {
        const here = { latitude: coords.latitude, longitude: coords.longitude };
        const moved = this.last === null || distance(this.last, here) >= SEND_AFTER_METRES;

        if (!moved && Date.now() - this.sentAt < SEND_EVERY) {
            return;
        }

        this.last = here;
        this.sentAt = Date.now();
        this.$wire.locate(coords.latitude, coords.longitude, coords.accuracy);
    },
});
