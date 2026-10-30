import { downscaleImage } from '../components/downscale';
import { fetchWithTimeout } from '../components/http';

const fill = (template, values) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, String(value)), template ?? '');

const xsrf = () => {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

/**
 * « 📷 Ajouter des photos » in the detail of a task: camera or gallery, several at once, each downscaled on the
 * phone, sent one after the other with the progress on the button, then the gallery reloads.
 */
export default () => ({
    busy: false,
    label: '',
    texts: {},

    init() {
        this.texts = JSON.parse(this.$el.dataset.texts ?? '{}');
        this.label = `📷 ${this.texts.add ?? ''}`;
    },

    pick() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/webp';
        input.multiple = true;
        input.addEventListener('change', () => this.send([...input.files]));
        input.click();
    },

    async send(files) {
        if (files.length === 0) {
            return;
        }

        this.busy = true;
        let sent = 0;
        let failed = 0;

        for (const [index, file] of files.entries()) {
            this.label = files.length > 1 ? fill(this.texts.progress, { n: index + 1, total: files.length }) : '…';
            (await this.upload(file)) ? sent++ : failed++;
        }

        if (files.length > 1) {
            this.label = [sent > 0 ? fill(this.texts.sent_count, { count: sent }) : '', failed > 0 ? fill(this.texts.failed_count, { count: failed }) : ''].filter(Boolean).join(' · ');
        } else {
            this.label = sent > 0 ? this.texts.sent_one : this.texts.failed_one;
        }

        this.busy = false;

        setTimeout(() => { this.label = `📷 ${this.texts.add ?? ''}`; }, 2500);

        if (sent > 0) {
            this.$wire.$refresh();
        }
    },

    async upload(file) {
        const blob = (await downscaleImage(file)) ?? file;
        const form = new FormData();
        form.append('date', this.$el.dataset.date);
        form.append('target', this.$el.dataset.target);
        form.append('phase', this.$el.dataset.phase);
        form.append('file', blob, 'photo.jpg');

        try {
            const response = await fetchWithTimeout(this.$el.dataset.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: form,
            });

            return response.ok;
        } catch {
            return false;
        }
    },
});
