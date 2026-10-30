/**
 * Countdown of a design office validation request, from the deadline given by the server (the screen only shows it;
 * alerts and lateness are decided on the server). Past the deadline it shows the late label and the time over.
 */
const format = (seconds) => {
    const minutes = Math.floor(seconds / 60);
    const rest = String(seconds % 60).padStart(2, '0');

    return `${minutes}:${rest}`;
};

export default () => ({
    text: '',
    late: false,
    timer: null,

    init() {
        this.tick();
        this.timer = setInterval(() => this.tick(), 1000);
    },

    destroy() {
        clearInterval(this.timer);
    },

    tick() {
        const deadline = Date.parse(this.$el.dataset.deadline ?? '');

        if (Number.isNaN(deadline)) {
            this.text = '';

            return;
        }

        const remaining = Math.round((deadline - Date.now()) / 1000);
        this.late = remaining < 0;
        this.text = this.late ? `${this.$el.dataset.lateLabel ?? ''} +${format(-remaining)}` : format(remaining);
    },
});
