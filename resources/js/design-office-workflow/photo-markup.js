import { showToast } from '../components/toast';
import { xsrf } from '../audit/sync';

export const TOOLS = ['circle', 'arrow', 'pen', 'text'];
export const MAX_POINTS = 400;
const SVG = 'http://www.w3.org/2000/svg';
const SMALLEST = 0.01;

const round = (value) => Math.round(Math.min(1, Math.max(0, value)) * 10000) / 10000;

/**
 * Where the pointer is on the photo, as fractions of its width and height (the shapes then fit the photo at any size).
 */
export const toFraction = (rect, clientX, clientY) => [round((clientX - rect.left) / (rect.width || 1)), round((clientY - rect.top) / (rect.height || 1))];

/**
 * Keeps at most MAX_POINTS points of a free line, evenly spread, first and last included.
 */
export function thinPoints(points, max = MAX_POINTS) {
    if (points.length <= max) {
        return points;
    }

    const step = (points.length - 1) / (max - 1);

    return Array.from({ length: max }, (_, index) => points[Math.round(index * step)]);
}

/**
 * The shape drawn from where the pointer went down to where it went up, or null when it is too small to mean anything.
 */
export function shapeFrom(draft) {
    if (draft.type === 'pen') {
        return draft.points.length >= 2 ? { ...draft, points: thinPoints(draft.points) } : null;
    }

    if (draft.type === 'text') {
        return draft.text.trim() === '' ? null : { ...draft, text: draft.text.trim().slice(0, 120) };
    }

    return Math.abs(draft.x2 - draft.x1) + Math.abs(draft.y2 - draft.y1) < SMALLEST ? null : draft;
}

/**
 * The SVG elements of a shape over a photo of the given size (in pixels of the photo).
 *
 * @returns {Array<{tag: string, attrs: Record<string, string|number>, text?: string}>}
 */
export function elementsOf(shape, width, height) {
    const size = Math.max(width, height);
    const stroke = { 'stroke-width': Math.max(2, Math.round(size / 250)), fill: 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', class: `photo-markup__shape photo-markup__shape--${shape.colour}` };
    const x = (value) => Math.round(value * width);
    const y = (value) => Math.round(value * height);

    switch (shape.type) {
        case 'circle':
            return [{ tag: 'ellipse', attrs: { ...stroke, cx: x((shape.x1 + shape.x2) / 2), cy: y((shape.y1 + shape.y2) / 2), rx: Math.abs(x(shape.x2) - x(shape.x1)) / 2, ry: Math.abs(y(shape.y2) - y(shape.y1)) / 2 } }];
        case 'arrow': {
            const [x1, y1, x2, y2] = [x(shape.x1), y(shape.y1), x(shape.x2), y(shape.y2)];
            const angle = Math.atan2(y2 - y1, x2 - x1);
            const head = size / 30;
            const wing = (side) => ({ tag: 'line', attrs: { ...stroke, x1: x2, y1: y2, x2: Math.round(x2 - head * Math.cos(angle + side * Math.PI / 7)), y2: Math.round(y2 - head * Math.sin(angle + side * Math.PI / 7)) } });

            return [{ tag: 'line', attrs: { ...stroke, x1, y1, x2, y2 } }, wing(1), wing(-1)];
        }
        case 'pen':
            return [{ tag: 'polyline', attrs: { ...stroke, points: shape.points.map(([px, py]) => `${x(px)},${y(py)}`).join(' ') } }];
        case 'text':
            return [{ tag: 'text', attrs: { x: x(shape.x), y: y(shape.y), 'font-size': Math.round(size / 30), class: `photo-markup__label photo-markup__label--${shape.colour}` }, text: shape.text }];
        default:
            return [];
    }
}

const fill = (template, values = {}) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value ?? '')), template ?? '');

/**
 * A photo of the design office workflow with the shapes drawn over it. The design office and the supervisors draw
 * theirs (circle, arrow, free line, text) and save them; the photo itself is never changed. Everyone else of the
 * workflow only sees them.
 */
export default () => ({
    config: {},
    annotations: [],
    mine: [],
    draft: null,
    tool: 'circle',
    colour: 'red',
    text: '',
    saving: false,
    width: 0,
    height: 0,

    init() {
        this.config = JSON.parse(this.$el.dataset.config);
        this.annotations = this.config.annotations ?? [];
        this.mine = (this.annotations.find((annotation) => annotation.author_id === this.config.userId)?.shapes ?? []).map((shape) => ({ ...shape }));
        this.colour = this.config.colours?.[0] ?? 'red';
        this.$nextTick(() => this.$refs.photo?.complete && this.$refs.photo.naturalWidth > 0 && this.loaded());
    },

    t(key, values) {
        return fill(this.config.texts?.[key] ?? key, values);
    },

    get canDraw() { return Boolean(this.config.canDraw); },
    get photoUrl() { return this.config.photoUrl ?? ''; },
    get tools() { return TOOLS; },
    get colours() { return this.config.colours ?? []; },
    get textTool() { return this.tool === 'text'; },
    get textPlaceholder() { return this.t('text_placeholder'); },
    get nothingToUndo() { return this.mine.length === 0; },
    get noAnnotations() { return this.annotations.length === 0; },
    get backText() { return `← ${this.t('back')}`; },
    get undoText() { return `↶ ${this.t('undo')}`; },
    get clearText() { return this.t('clear'); },
    get saveText() { return this.t('save'); },
    get emptyText() { return this.t('none'); },

    toolLabel(tool) { return this.t(`tool_${tool}`); },
    toolClass(tool) { return this.tool === tool ? 'is-active' : ''; },
    colourLabel(colour) { return this.t(`colour_${colour}`); },
    swatchClass(colour) { return `photo-markup__swatch--${colour}${this.colour === colour ? ' is-active' : ''}`; },

    authorText(annotation) {
        const when = annotation.at ? new Date(annotation.at).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'short', timeStyle: 'short' }) : '';

        return this.t('by', { who: annotation.by, time: when, count: annotation.shapes.length });
    },

    pick(tool) {
        this.tool = tool;
    },

    paint(colour) {
        this.colour = colour;
    },

    back() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.close();
        }
    },

    loaded() {
        this.width = this.$refs.photo.naturalWidth;
        this.height = this.$refs.photo.naturalHeight;
        this.draw();
    },

    point(event) {
        return toFraction(this.$refs.layer.getBoundingClientRect(), event.clientX, event.clientY);
    },

    down(event) {
        if (!this.canDraw || this.width === 0) {
            return;
        }

        const [x, y] = this.point(event);

        if (this.tool === 'text') {
            const shape = shapeFrom({ type: 'text', colour: this.colour, x, y, text: this.text });

            if (shape) {
                this.mine = [...this.mine, shape];
                this.text = '';
                this.draw();
            }

            return;
        }

        event.target.setPointerCapture?.(event.pointerId);
        this.draft = this.tool === 'pen' ? { type: 'pen', colour: this.colour, points: [[x, y]] } : { type: this.tool, colour: this.colour, x1: x, y1: y, x2: x, y2: y };
    },

    move(event) {
        if (this.draft === null) {
            return;
        }

        const [x, y] = this.point(event);

        if (this.draft.type === 'pen') {
            this.draft.points.push([x, y]);
        } else {
            this.draft.x2 = x;
            this.draft.y2 = y;
        }

        this.draw();
    },

    up() {
        if (this.draft === null) {
            return;
        }

        const shape = shapeFrom(this.draft);

        this.draft = null;

        if (shape) {
            this.mine = [...this.mine, shape];
        }

        this.draw();
    },

    undo() {
        this.mine = this.mine.slice(0, -1);
        this.draw();
    },

    clearMine() {
        this.mine = [];
        this.draw();
    },

    /**
     * The shapes of the others, then the person's own (being edited), then the one being drawn.
     */
    draw() {
        const layer = this.$refs.layer;

        if (!layer || this.width === 0) {
            return;
        }

        layer.setAttribute('viewBox', `0 0 ${this.width} ${this.height}`);
        layer.replaceChildren();

        const others = this.annotations.filter((annotation) => annotation.author_id !== this.config.userId || !this.canDraw).flatMap((annotation) => annotation.shapes);
        const shapes = [...others, ...(this.canDraw ? this.mine : []), ...(this.draft ? [this.draft] : [])];

        for (const shape of shapes) {
            for (const { tag, attrs, text } of elementsOf(shape, this.width, this.height)) {
                const element = document.createElementNS(SVG, tag);

                Object.entries(attrs).forEach(([name, value]) => element.setAttribute(name, String(value)));

                if (text !== undefined) {
                    element.textContent = text;
                }

                layer.appendChild(element);
            }
        }
    },

    async save() {
        this.saving = true;

        try {
            const response = await fetch(this.config.saveUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ shapes: this.mine }),
            });
            const answer = await response.json().catch(() => ({}));

            if (!response.ok) {
                showToast(answer.message ?? this.t('save_failed'));

                return;
            }

            this.annotations = answer.annotations ?? [];
            showToast(this.t('saved'));
        } catch {
            showToast(this.t('save_failed'));
        } finally {
            this.saving = false;
            this.draw();
        }
    },
});
