import { downscaleImage } from './downscale';

/**
 * File input of a Livewire form that sends photos downscaled on the device: each picked image is reduced to 2560 px
 * JPEG, then uploaded into the component property named in data-property (several at once).
 */
export default () => ({
    busy: false,

    get label() {
        return this.busy ? this.$root.dataset.sending : this.$root.dataset.pick;
    },

    async pick(event) {
        const files = [...event.target.files];
        event.target.value = '';

        if (files.length === 0) {
            return;
        }

        this.busy = true;
        const ready = [];

        for (const file of files) {
            const blob = await downscaleImage(file);
            ready.push(blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file);
        }

        this.$wire.uploadMultiple(this.$root.dataset.property, ready, () => { this.busy = false; }, () => { this.busy = false; });
    },
});
