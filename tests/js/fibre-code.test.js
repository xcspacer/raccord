import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { CAPACITIES, CODE_KEYS, FIBRES_PER_TUBE, createCable } from '../../resources/js/fibre-code/fibre-code';

/** French screen texts, as in lang/fr/fibre_code.php: labels must then match the original tool word for word. */
const french = {
    colours: { red: 'Rouge', blue: 'Bleu', green: 'Vert', yellow: 'Jaune', violet: 'Violet', white: 'Blanc', orange: 'Orange', grey: 'Gris', brown: 'Marron', black: 'Noir', turquoise: 'Turquoise', pink: 'Rose' },
    marks: { dashed: 'tireté', black_stripe: 'liseré noir', yellow_tracer: 'traceur jaune', fallback: 'repère' },
    columns: { number: 'N° FO', tube: 'Tube', tube_colour: 'Couleur tube', fibre: 'Fibre', fibre_colour: 'Couleur fibre' },
};

const cableOf = (config) => createCable(config, french);

/**
 * The original tool's library, when its path is given (FIBRE_CODE_LEGACY): it is a UMD file, run here as CommonJS.
 */
const loadLegacy = (path) => {
    const module = { exports: {} };
    new Function('module', 'exports', readFileSync(path, 'utf8'))(module, module.exports);

    return module.exports;
};

const legacyPath = process.env.FIBRE_CODE_LEGACY;

describe.skipIf(!legacyPath)('identical to the original tool', () => {
    it('gives the same results for every configuration', () => {
        const legacy = loadLegacy(legacyPath);
        let checked = 0;

        for (const code of CODE_KEYS) {
            for (const fibresPerTube of FIBRES_PER_TUBE) {
                for (const capacity of CAPACITIES) {
                    for (const start of [0, 1, 5]) {
                        const original = legacy.createCable({ code, fpt: fibresPerTube, cap: capacity, start });
                        const cable = cableOf({ code, fibresPerTube, capacity, start });
                        const colourOf = (position) => {
                            const { index: i, name, hex, dark, repeat: rep, label } = cable.colour(position);

                            return { i, name, hex, dark, rep, label };
                        };

                        expect(cable.tubeCount()).toBe(original.nTubes());
                        expect(cable.maxFibre()).toBe(original.maxFo());

                        for (let number = start; number <= original.maxFo(); number++) {
                            const { t, f } = original.decode(number);
                            expect(cable.decode(number)).toEqual({ tube: t, fibre: f });
                            expect(cable.encode(t, f)).toBe(original.encode(t, f));
                            expect(colourOf(t)).toEqual(original.col(t));
                            expect(colourOf(f)).toEqual(original.col(f));
                            checked++;
                        }
                    }
                }
            }
        }

        expect(checked).toBeGreaterThan(100000);
    });

    it('builds the same cable table', () => {
        const original = loadLegacy(legacyPath).createCable({ code: 'zayo', fpt: 12, cap: 288, start: 1 });
        const cable = cableOf({ code: 'zayo', fibresPerTube: 12, capacity: 288, start: 1 });
        let expected = 'N° FO\tTube\tCouleur tube\tFibre\tCouleur fibre\n';

        for (let number = 1; number <= 288; number++) {
            const { t, f } = original.decode(number);
            expected += `${number}\t${t}\t${original.col(t).label}\t${f}\t${original.col(f).label}\n`;
        }

        expect(cable.toTsv()).toBe(expected);
    });
});

describe('fibre colour code', () => {
    it('converts the usual Orange / FT cable of 144 fibres in 24 tubes of 6', () => {
        const cable = cableOf({ code: 'ft', fibresPerTube: 6, capacity: 144, start: 1 });

        expect(cable.decode(27)).toEqual({ tube: 5, fibre: 3 });
        expect(cable.colour(5).name).toBe('Violet');
        expect(cable.colour(3).name).toBe('Vert');
        expect(cable.encode(5, 3)).toBe(27);
        expect(cable.encode(13, 1)).toBe(73);
        expect(cable.colour(13).label).toBe('Rouge tireté');
        expect(cable.decode(78)).toEqual({ tube: 13, fibre: 6 });
        expect(cable.colour(24).label).toBe('Rose tireté');
        expect(cable.decode(144)).toEqual({ tube: 24, fibre: 6 });
        expect(cable.colour(27).label).toBe('Vert tireté ×2');
        expect(cable.colour(30).label).toBe('Blanc tireté ×2');
        expect(cable.describe(27).tube.colour.name).toBe('Violet');
    });

    it('gives black a yellow tracer at its 13th-to-24th position on international codes, then ×2 beyond 24', () => {
        const cable = cableOf({ code: 'tia' });

        expect(cable.colour(1).name).toBe('Bleu');
        expect(cable.colour(8).name).toBe('Noir');
        expect(cable.colour(13).label).toBe('Bleu liseré noir');
        expect(cable.colour(20).label).toBe('Noir traceur jaune');
        expect(cable.colour(25).label).toBe('Bleu liseré noir ×2');
        expect(cable.colour(32).label).toBe('Noir liseré noir ×2');
    });

    it('keeps every cable independent', () => {
        const first = cableOf({ code: 'ft', fibresPerTube: 6, capacity: 144 });
        const second = cableOf({ code: 'zayo', fibresPerTube: 12, capacity: 288 });
        const custom = cableOf({ code: 'custom', customOrder: ['pink', 'red', 'blue', 'green', 'yellow', 'violet', 'white', 'orange', 'grey', 'brown', 'black', 'turquoise'], customMark: '' });

        expect(first.colour(1).name).toBe('Rouge');
        expect(second.colour(1).name).toBe('Bleu');
        expect(custom.colour(1).name).toBe('Rose');
        expect(custom.colour(13).label).toBe('Rose repère');
        expect(cableOf({ code: 'custom' }).colour(1).name).toBe('Rouge');
        expect(cableOf({ code: 'custom', customOrder: ['constructor', ...custom.order().slice(1)] }).colour(1).name).toBe('Rouge');
        expect(first.settings.fibresPerTube).toBe(6);
        expect(second.settings.fibresPerTube).toBe(12);
    });

    it('checks the typed numbers against the cable', () => {
        const cable = cableOf({ code: 'ft', fibresPerTube: 6, capacity: 144, start: 1 });

        expect(cable.checkNumber(0)).toEqual({ key: 'number', params: { start: 1, max: 144 } });
        expect(cable.checkNumber(Number.NaN)).toEqual({ key: 'number', params: { start: 1, max: 144 } });
        expect(cable.checkNumber(144)).toBeNull();
        expect(cable.checkTubeFibre(25, 1)).toEqual({ key: 'tube', params: { count: 24 } });
        expect(cable.checkTubeFibre(1, 7)).toEqual({ key: 'fibre', params: { count: 6 } });
        expect(cableOf({ fibresPerTube: 8, capacity: 12 }).checkTubeFibre(2, 5)).toEqual({ key: 'capacity', params: { capacity: 12 } });
        expect(cable.checkTubeFibre(5, 3)).toBeNull();
    });

    it('falls back to a first fibre numbered 0 when the start is not a number', () => {
        expect(cableOf({ start: 'abc' }).settings.start).toBe(0);
    });
});
