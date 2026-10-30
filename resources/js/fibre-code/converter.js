import { CAPACITIES, CODE_KEYS, FIBRES_PER_TUBE, FT_ORDER, PALETTE, createCable, isValidCustomOrder, readStoredSettings, storeSettings } from './fibre-code';

/** At most this many tube buttons in the colour picker. */
const PICKER_LIMIT = 48;

const COPY_FEEDBACK_MS = 1500;

/**
 * @param {string} text
 * @param {Record<string, number>} params
 */
const format = (text, params) => Object.entries(params).reduce((result, [name, value]) => result.replaceAll(`:${name}`, String(value)), text ?? '');

const toInteger = (value) => parseInt(value, 10);

/**
 * The « Code Fibre » screen (integrated page and standalone app): cable settings, the two converters, the 12-colour
 * legend and the whole cable table. Texts come from the `fibre_code` translations in data-texts; settings are kept
 * on the device (same key and shape as before, so both screens share them).
 */
export default () => ({
    texts: {},
    code: 'ft',
    fibresPerTube: 6,
    capacity: 144,
    start: 1,
    customOrder: [...FT_ORDER],
    customMark: '',
    number: '27',
    tube: '5',
    fibre: '3',
    highlighted: null,
    copyState: 'idle',
    copyTimer: null,

    init() {
        this.texts = JSON.parse(this.$root.dataset.texts ?? '{}');
        this.customMark = this.texts.marks?.dashed ?? '';
        this.restore(readStoredSettings());
        this.refresh();
    },

    restore(stored) {
        if (stored === null) {
            return;
        }

        if (CODE_KEYS.includes(stored.code)) {
            this.code = stored.code;
        }

        if (FIBRES_PER_TUBE.includes(Number(stored.fpt))) {
            this.fibresPerTube = Number(stored.fpt);
        }

        if (CAPACITIES.includes(Number(stored.cap))) {
            this.capacity = Number(stored.cap);
        }

        if (stored.start !== null && stored.start !== undefined) {
            this.start = toInteger(stored.start) || 0;
        }

        if (isValidCustomOrder(stored.custom)) {
            this.customOrder = [...stored.custom];
        }

        if (typeof stored.mark === 'string' && stored.mark !== '') {
            this.customMark = stored.mark;
        }
    },

    get cable() {
        return createCable({
            code: this.code,
            fibresPerTube: this.fibresPerTube,
            capacity: this.capacity,
            start: this.start,
            customOrder: this.customOrder,
            customMark: this.customMark,
        }, this.texts);
    },

    /**
     * Settings changed: remember them, then convert both inputs again (the second one wins the highlighted row).
     */
    refresh() {
        const cable = this.cable;
        const mark = this.customMark.trim() || this.texts.marks?.fallback;

        // The default mark is not stored, so it follows the language of the screen.
        storeSettings({
            fpt: this.fibresPerTube,
            cap: this.capacity,
            start: this.start,
            code: this.code,
            custom: [...this.customOrder],
            mark: mark === this.texts.marks?.dashed ? '' : mark,
        });

        this.highlighted = null;
        this.highlightNumber(cable);
        this.highlightTubeFibre(cable);
    },

    highlightNumber(cable = this.cable) {
        const number = toInteger(this.number);

        if (cable.checkNumber(number) === null) {
            this.highlighted = number;
        }
    },

    highlightTubeFibre(cable = this.cable) {
        const tube = toInteger(this.tube);
        const fibre = toInteger(this.fibre);

        if (cable.checkTubeFibre(tube, fibre) === null) {
            this.highlighted = cable.encode(tube, fibre);
        }
    },

    setCode(event) {
        this.code = event.target.value;
        this.refresh();
    },

    setFibresPerTube(event) {
        this.fibresPerTube = Number(event.target.value);
        this.refresh();
    },

    setCapacity(event) {
        this.capacity = Number(event.target.value);
        this.refresh();
    },

    setStart(event) {
        this.start = toInteger(event.target.value) || 0;
        this.refresh();
    },

    setCustomColour(index, event) {
        const order = [...this.customOrder];
        order[index] = event.target.value;
        this.customOrder = order;
        this.refresh();
    },

    setCustomMark(event) {
        this.customMark = event.target.value;
        this.refresh();
    },

    setNumber(event) {
        this.number = event.target.value;
        this.highlightNumber();
    },

    setTube(event) {
        this.tube = event.target.value;
        this.highlightTubeFibre();
    },

    setFibre(event) {
        this.fibre = event.target.value;
        this.highlightTubeFibre();
    },

    pickTube(position) {
        this.tube = String(position);
        this.highlightTubeFibre();
    },

    pickFibre(position) {
        this.fibre = String(position);
        this.highlightTubeFibre();
    },

    get codeOptions() {
        return CODE_KEYS.map((key) => ({ value: key, label: this.texts.codes?.[key]?.option ?? key, selected: key === this.code }));
    },

    get fibresPerTubeOptions() {
        return FIBRES_PER_TUBE.map((value) => ({ value, selected: value === this.fibresPerTube }));
    },

    get capacityOptions() {
        return CAPACITIES.map((value) => ({ value, selected: value === this.capacity }));
    },

    get isCustom() {
        return this.code === 'custom';
    },

    get codeName() {
        return this.cable.codeName();
    },

    get codeNote() {
        return this.cable.codeNote();
    },

    get tubeCount() {
        return this.cable.tubeCount();
    },

    get customSlots() {
        return this.customOrder.map((key, index) => ({
            index,
            position: index + 1,
            swatchClass: `fibre-code__dot fibre-code__dot--small fibre-code__dot--${key}`,
            options: Object.keys(PALETTE).map((option) => ({ value: option, label: this.texts.colours?.[option] ?? option, selected: option === key })),
        }));
    },

    lineOf(key, value, colour) {
        return { key, label: this.texts[key], value, colour, dotClass: this.dotClass(colour, false) };
    },

    get numberError() {
        const error = this.cable.checkNumber(toInteger(this.number));

        return error === null ? '' : format(this.texts.errors?.[error.key], error.params);
    },

    get numberLines() {
        const cable = this.cable;
        const number = toInteger(this.number);

        if (cable.checkNumber(number) !== null) {
            return [];
        }

        const { tube, fibre } = cable.decode(number);

        return [this.lineOf('tube', tube, cable.colour(tube)), this.lineOf('fibre', fibre, cable.colour(fibre))];
    },

    get tubeFibreError() {
        const error = this.cable.checkTubeFibre(toInteger(this.tube), toInteger(this.fibre));

        return error === null ? '' : format(this.texts.errors?.[error.key], error.params);
    },

    get tubeFibreValid() {
        return this.tubeFibreError === '';
    },

    get encodedNumber() {
        return this.tubeFibreValid ? this.cable.encode(toInteger(this.tube), toInteger(this.fibre)) : '';
    },

    get tubeFibreLines() {
        if (!this.tubeFibreValid) {
            return [];
        }

        const cable = this.cable;
        const tube = toInteger(this.tube);
        const fibre = toInteger(this.fibre);

        return [this.lineOf('tube', tube, cable.colour(tube)), this.lineOf('fibre', fibre, cable.colour(fibre))];
    },

    choices(count, selected) {
        const cable = this.cable;
        const choices = [];

        for (let position = 1; position <= Math.min(count, PICKER_LIMIT); position++) {
            const colour = cable.colour(position);
            choices.push({ position, pressed: String(position === selected), dotClass: this.dotClass(colour, true), title: colour.label });
        }

        return choices;
    },

    get tubeChoices() {
        return this.choices(this.tubeCount, toInteger(this.tube));
    },

    get fibreChoices() {
        return this.choices(this.fibresPerTube, toInteger(this.fibre));
    },

    get legend() {
        return this.cable.legend().map((item) => ({ ...item, dotClass: this.dotClass(item.colour, true) }));
    },

    get rows() {
        return this.cable.tableRows().map((row) => ({
            ...row,
            tubeDotClass: this.dotClass(row.tubeColour, true),
            fibreDotClass: this.dotClass(row.fibreColour, true),
        }));
    },

    rowClass(row) {
        return [row.tubeStart ? 'is-tube-start' : '', row.number === this.highlighted ? 'is-highlighted' : ''].join(' ').trim();
    },

    dotClass(colour, small) {
        return [
            'fibre-code__dot',
            `fibre-code__dot--${colour.key}`,
            small ? 'fibre-code__dot--small' : '',
            colour.repeat ? 'fibre-code__dot--marked' : '',
            colour.dark ? 'fibre-code__dot--dark' : '',
        ].filter(Boolean).join(' ');
    },

    get copyText() {
        return { idle: this.texts.copy, done: this.texts.copied, failed: this.texts.copy_failed }[this.copyState];
    },

    async copy() {
        try {
            await navigator.clipboard.writeText(this.cable.toTsv());
            this.copyState = 'done';
            clearTimeout(this.copyTimer);
            this.copyTimer = setTimeout(() => { this.copyState = 'idle'; }, COPY_FEEDBACK_MS);
        } catch {
            this.copyState = 'failed';
        }
    },
});
