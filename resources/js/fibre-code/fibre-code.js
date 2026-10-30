/**
 * Fibre-optic colour code, pure logic without any interface (reusable by the audit, PDF documents…):
 * fibre number ↔ tube and fibre in the tube, with their colours, for the usual codes or a custom order.
 *
 *   const cable = createCable({ code: 'ft', fibresPerTube: 6, capacity: 144, start: 1 }, dictionary);
 *   cable.decode(27)      → { tube: 5, fibre: 3 }
 *   cable.encode(5, 3)    → 27
 *   cable.colour(13)      → { index: 0, key: 'red', name: …, hex: '#d62828', dark: 1, repeat: 1, label: … }
 *   cable.describe(27)    → { number: 27, tube: { number: 5, colour: {…} }, fibre: { number: 3, colour: {…} } }
 *
 * Colour, mark and code names are screen texts: they come from the dictionary (the `fibre_code` translations), never
 * from this file. Every cable is independent.
 */

/**
 * Each colour: [hex, 1 when the colour is dark (its "marked" swatch is then drawn in a light tone)].
 *
 * @type {Readonly<Record<string, [string, 0|1]>>}
 */
export const PALETTE = Object.freeze({
    red: ['#d62828', 1],
    blue: ['#1f5fd1', 1],
    green: ['#1e9b3b', 1],
    yellow: ['#f5d400', 0],
    violet: ['#7b3fb9', 1],
    white: ['#ffffff', 0],
    orange: ['#f47c10', 0],
    grey: ['#8c9196', 0],
    brown: ['#7a4a22', 1],
    black: ['#141414', 1],
    turquoise: ['#20c1c4', 0],
    pink: ['#f59ac6', 0],
});

export const FT_ORDER = Object.freeze(['red', 'blue', 'green', 'yellow', 'violet', 'white', 'orange', 'grey', 'brown', 'black', 'turquoise', 'pink']);

export const INTERNATIONAL_ORDER = Object.freeze(['blue', 'orange', 'green', 'brown', 'grey', 'white', 'red', 'black', 'yellow', 'violet', 'pink', 'turquoise']);

/**
 * The colour codes: order of the 12 colours and mark added from the 13th position on (keys of the `marks` texts).
 *
 * @type {Readonly<Record<string, {order: readonly string[], mark: string}>>}
 */
export const CODES = Object.freeze({
    ft: { order: FT_ORDER, mark: 'dashed' },
    ld: { order: INTERNATIONAL_ORDER, mark: 'black_stripe' },
    zayo: { order: INTERNATIONAL_ORDER, mark: 'black_stripe' },
    tia: { order: INTERNATIONAL_ORDER, mark: 'black_stripe' },
    custom: { order: FT_ORDER, mark: 'dashed' },
});

export const CODE_KEYS = Object.freeze(Object.keys(CODES));

export const FIBRES_PER_TUBE = Object.freeze([1, 2, 4, 6, 8, 12, 24]);

export const CAPACITIES = Object.freeze([6, 12, 24, 36, 48, 72, 96, 144, 288, 432, 576, 720]);

/** Settings remembered on the device, shared by the integrated screen and the standalone app. */
export const STORAGE_KEY = 'fo-cfg';

/**
 * @param {unknown} order
 * @returns {order is string[]}
 */
export const isValidCustomOrder = (order) => Array.isArray(order) && order.length === 12 && order.every((key) => typeof key === 'string' && Object.hasOwn(PALETTE, key));

/**
 * @typedef {{index: number, key: string, name: string, hex: string, dark: 0|1, repeat: number, label: string}} Colour
 * @typedef {{key: string, params: Record<string, number>}} CheckError
 * @typedef {{colours?: Record<string, string>, marks?: Record<string, string>, codes?: Record<string, {name?: string, note?: string}>, columns?: Record<string, string>}} Dictionary
 * @typedef {{code?: string, fibresPerTube?: number|string, capacity?: number|string, start?: number|string, customOrder?: string[], customMark?: string|null}} CableConfig
 */

/**
 * A cable: colour code + fibres per tube + capacity + number of the first fibre.
 *
 * @param {CableConfig} [config]
 * @param {Dictionary} [dictionary]
 */
export function createCable(config = {}, dictionary = {}) {
    const settings = { fibresPerTube: 6, capacity: 144, start: 1 };
    let codeKey = 'ft';
    let customOrder = [...FT_ORDER];
    /** @type {string|null} Mark typed for the custom code; null keeps the code's own mark. */
    let customMark = null;

    const colourName = (key) => dictionary.colours?.[key] ?? key;
    const markText = (key) => dictionary.marks?.[key] ?? key;
    const order = () => (codeKey === 'custom' ? customOrder : CODES[codeKey].order);
    const mark = () => (codeKey === 'custom' && customMark !== null ? customMark : markText(CODES[codeKey].mark));

    function setCode(key) {
        codeKey = Object.hasOwn(CODES, key) ? key : 'ft';
    }

    /**
     * Colour of a position (tube or fibre, 1-based): beyond 12 the same colour comes back with the code's mark.
     *
     * @param {number} position
     * @returns {Colour}
     */
    function colour(position) {
        const index = (position - 1) % 12;
        const repeat = Math.floor((position - 1) / 12);
        const key = order()[index];
        const [hex, dark] = PALETTE[key];
        const name = colourName(key);
        let marking = mark();

        if (repeat === 1 && key === 'black' && marking === markText('black_stripe')) {
            marking = markText('yellow_tracer');
        }

        return { index, key, name, hex, dark, repeat, label: name + (repeat ? ` ${marking}${repeat > 1 ? ` ×${repeat}` : ''}` : '') };
    }

    const tubeCount = () => Math.ceil(settings.capacity / settings.fibresPerTube);
    const maxFibre = () => settings.capacity + settings.start - 1;

    /**
     * @param {number} number
     * @returns {{tube: number, fibre: number}}
     */
    function decode(number) {
        const offset = number - settings.start;

        return { tube: Math.floor(offset / settings.fibresPerTube) + 1, fibre: (offset % settings.fibresPerTube) + 1 };
    }

    /**
     * @param {number} tube
     * @param {number} fibre
     */
    function encode(tube, fibre) {
        return (tube - 1) * settings.fibresPerTube + (fibre - 1) + settings.start;
    }

    /**
     * @param {number} number
     */
    function describe(number) {
        const { tube, fibre } = decode(number);

        return { number, tube: { number: tube, colour: colour(tube) }, fibre: { number: fibre, colour: colour(fibre) } };
    }

    /**
     * @param {CableConfig} options
     */
    function configure(options) {
        if (options.code !== undefined) {
            setCode(options.code);
        }

        if (options.fibresPerTube !== undefined) {
            settings.fibresPerTube = +options.fibresPerTube;
        }

        if (options.capacity !== undefined) {
            settings.capacity = +options.capacity;
        }

        if (options.start !== undefined) {
            settings.start = Number.isFinite(+options.start) ? +options.start : 0;
        }

        if (isValidCustomOrder(options.customOrder)) {
            customOrder = [...options.customOrder];
        }

        if (options.customMark !== undefined) {
            customMark = options.customMark === null ? null : String(options.customMark).trim() || markText('fallback');
        }

        return api;
    }

    /**
     * The fibre number typed in the first converter, checked against the cable.
     *
     * @param {number} number
     * @returns {CheckError|null}
     */
    function checkNumber(number) {
        if (Number.isNaN(number) || number < settings.start || number > maxFibre()) {
            return { key: 'number', params: { start: settings.start, max: maxFibre() } };
        }

        return null;
    }

    /**
     * The tube and fibre typed in the second converter, checked against the cable.
     *
     * @param {number} tube
     * @param {number} fibre
     * @returns {CheckError|null}
     */
    function checkTubeFibre(tube, fibre) {
        if (Number.isNaN(tube) || tube < 1 || tube > tubeCount()) {
            return { key: 'tube', params: { count: tubeCount() } };
        }

        if (Number.isNaN(fibre) || fibre < 1 || fibre > settings.fibresPerTube) {
            return { key: 'fibre', params: { count: settings.fibresPerTube } };
        }

        if (encode(tube, fibre) > maxFibre()) {
            return { key: 'capacity', params: { capacity: settings.capacity } };
        }

        return null;
    }

    /**
     * Every fibre of the cable, in order.
     *
     * @returns {{number: number, tube: number, tubeColour: Colour, fibre: number, fibreColour: Colour, tubeStart: boolean}[]}
     */
    function tableRows() {
        const rows = [];

        for (let offset = 0; offset < settings.capacity; offset++) {
            const number = offset + settings.start;
            const { tube, fibre } = decode(number);
            rows.push({ number, tube, tubeColour: colour(tube), fibre, fibreColour: colour(fibre), tubeStart: fibre === 1 });
        }

        return rows;
    }

    /**
     * The cable table as tab-separated text (pasted into a spreadsheet).
     */
    function toTsv() {
        const columns = dictionary.columns ?? {};
        let text = `${[columns.number, columns.tube, columns.tube_colour, columns.fibre, columns.fibre_colour].join('\t')}\n`;

        for (const row of tableRows()) {
            text += `${row.number}\t${row.tube}\t${row.tubeColour.label}\t${row.fibre}\t${row.fibreColour.label}\n`;
        }

        return text;
    }

    /**
     * The 12 colours of the current code, in order.
     */
    function legend() {
        return order().map((key, index) => ({ position: index + 1, key, name: colourName(key), colour: colour(index + 1) }));
    }

    const api = {
        settings,
        setCode,
        colour,
        tubeCount,
        maxFibre,
        decode,
        encode,
        describe,
        configure,
        checkNumber,
        checkTubeFibre,
        tableRows,
        toTsv,
        legend,
        code: () => codeKey,
        codeName: () => dictionary.codes?.[codeKey]?.name ?? codeKey,
        codeNote: () => dictionary.codes?.[codeKey]?.note ?? '',
        order: () => [...order()],
    };

    configure(config);

    return api;
}

/**
 * Settings remembered on this device ({fpt, cap, start, code, custom, mark}), or null.
 *
 * @returns {{fpt?: unknown, cap?: unknown, start?: unknown, code?: unknown, custom?: unknown, mark?: unknown}|null}
 */
export function readStoredSettings() {
    try {
        const stored = JSON.parse(globalThis.localStorage?.getItem(STORAGE_KEY) ?? 'null');

        return stored !== null && typeof stored === 'object' ? stored : null;
    } catch {
        return null;
    }
}

/**
 * @param {{fpt: number, cap: number, start: number, code: string, custom: string[], mark: string}} values
 */
export function storeSettings(values) {
    try {
        globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(values));
    } catch {
        // Private browsing or storage full: the settings are simply not remembered.
    }
}
