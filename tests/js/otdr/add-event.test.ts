// Event added by hand: the recomputed LSA loss must find the OTDR one again.
import { describe, expect, it } from 'vitest';
import { addEvent, AddEventError, analyze, readSor, tryAddEvent } from '../../../resources/js/otdr';
import { byNameHash, loadSorFolder, SETS } from './support';

describe.skipIf(!SETS.distribution)('event added by hand', () => {
    const files = loadSorFolder(SETS.distribution);
    const target = byNameHash(files, '864d41f613c1')[0]!;
    const name = target?.name ?? '';

    it('adding a splice again (touched at +3 m) finds the OTDR loss on 40 traces', () => {
        const diffs: number[] = [];
        let refused = 0;

        for (const f of files.slice(0, 200)) {
            const s = readSor(f.name, f.bytes);
            const splice = s.events.find((e, i) => i > 0 && e.code.charAt(1) !== 'E' && e.marks[0] !== 0 && Math.abs(e.loss) > 0.05);

            if (!splice) {
                continue;
            }

            const fresh = readSor(f.name, f.bytes);
            fresh.events = fresh.events.filter((e) => e.num !== splice.num);

            try {
                // Approximate position (like a finger on the screen): +3 m after the real start.
                const added = addEvent(fresh, splice.km + 0.003, 901);
                diffs.push(Math.abs(added.loss - splice.loss));
            } catch {
                refused++;
                continue;
            }

            if (diffs.length >= 40) {
                break;
            }
        }

        const sorted = [...diffs].sort((a, b) => a - b);
        expect(diffs.length).toBe(40);
        expect(refused).toBe(0);
        expect(sorted[Math.floor(sorted.length / 2)]!).toBeLessThanOrEqual(0.002);
        expect(sorted[Math.floor(sorted.length * 0.9)]!).toBeLessThan(0.01);
    });

    it('the added event enters the dossier, cuts the section and flags the fiber as corrected', () => {
        const base = analyze(files).fibers.find((f) => f.files[0] === name)!;
        const d = analyze(files, undefined, [{ file: name, event: 900, action: 'add', km: 0.7, kind: 'splice' }]);
        const f = d.fibers.find((x) => x.files[0] === name)!;
        expect(f.events.map((e) => e.num)).toEqual([1, 900, 2]);
        expect(f.events[1]!.kind).toBe('splice');
        expect(f.sections).toHaveLength(base.sections.length + 1);
        expect(f.sections[0]!.lengthM + f.sections[1]!.lengthM).toBeCloseTo(base.sections[0]!.lengthM, 0);
        // homogeneous fiber at 700 m: loss close to zero
        expect(Math.abs(f.events[1]!.value)).toBeLessThan(0.05);
        expect(f.edited).toBe(true);
    });

    it('position too close to an event: refused with a warning', () => {
        const s = readSor(name, target.bytes);
        const d = analyze(files, undefined, [{ file: name, event: 900, action: 'add', km: s.events[0]!.km + 0.002 }]);
        expect(d.warnings.some((w) => w.key.startsWith('added_event_refused_') && w.params.file === name && w.params.distance === Math.round((s.events[0]!.km + 0.002) * 1000))).toBe(true);
    });

    it('trace screen: the addition gets the next number, or the refusal reason', () => {
        const ok = tryAddEvent(target, [], 0.7, 'distribution');
        expect(ok.override).toEqual({ file: name, event: 900, action: 'add', km: 0.7, kind: 'splice' });
        const next = tryAddEvent(target, [ok.override!], 0.9, 'distribution');
        expect(next.override?.event).toBe(901);
        const s = readSor(name, target.bytes);
        const refused = tryAddEvent(target, [], s.events[0]!.km + 0.002, 'distribution');
        expect(refused.error?.key).toMatch(/^add_event_refused_too_close_/);
        expect(() => addEvent(readSor(name, target.bytes), -1, 900)).toThrow(AddEventError);
    });
});
