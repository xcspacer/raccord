// Minimal SR-4731 version 2 writer for synthetic test traces (invented data only).
const C_KM_PER_S = 299792.458;

export interface SyntheticEvent {
    km: number;
    loss: number;
    reflectance?: number;
    /** SR-4731 code; 2nd character "E" = fiber end. */
    code: string;
    slope?: number;
}

export interface SyntheticSor {
    wavelengthNm?: number;
    ior?: number;
    pulseNs?: number;
    sampleKm?: number;
    lengthKm?: number;
    events: SyntheticEvent[];
    /** Level of the trace at a distance (dB). */
    level?: (km: number) => number;
    origin?: string;
    termination?: string;
    operator?: string;
    date?: Date;
}

class Writer {
    private readonly bytes: number[] = [];

    get length(): number {
        return this.bytes.length;
    }

    u16(v: number) {
        this.bytes.push(v & 0xff, (v >> 8) & 0xff);
    }

    i16(v: number) {
        this.u16(v < 0 ? v + 0x10000 : v);
    }

    u32(v: number) {
        this.bytes.push(v & 0xff, (v >>> 8) & 0xff, (v >>> 16) & 0xff, (v >>> 24) & 0xff);
    }

    i32(v: number) {
        this.u32(v < 0 ? v + 0x100000000 : v);
    }

    str(s: string) {
        for (const c of s) {
            this.bytes.push(c.charCodeAt(0) & 0xff);
        }

        this.bytes.push(0);
    }

    fixed(s: string) {
        for (const c of s) {
            this.bytes.push(c.charCodeAt(0) & 0xff);
        }
    }

    out(): number[] {
        return this.bytes;
    }
}

/** Default trace: backscatter 0.33 dB/km after a reflective launch connector, steps at the events, noise after the end. */
export function buildSor(s: SyntheticSor): Uint8Array {
    const ior = s.ior ?? 1.468;
    const pulseNs = s.pulseNs ?? 30;
    const sampleKm = s.sampleKm ?? 0.000638;
    const lengthKm = s.lengthKm ?? 6;
    const kmPerTime = (1e-10 * C_KM_PER_S) / ior;
    const spacing = Math.round((sampleKm * 10000) / kmPerTime);
    const end = s.events.find((e) => e.code.charAt(1) === 'E');
    let seed = 11;
    const rnd = () => {
        seed = (seed * 16807) % 2147483647;

        return seed / 2147483647 - 0.5;
    };
    const level = s.level ?? ((km: number) => {
        let v = 20 + 0.33 * km;

        for (const e of s.events) {
            if (km >= e.km && e.code.charAt(1) !== 'E') {
                v += e.loss;
            }
        }

        return end && km > end.km + 0.002 ? 60 + 3 * rnd() : v + 0.004 * rnd();
    });
    const n = Math.round(lengthKm / sampleKm);

    const gen = new Writer();
    gen.str('GenParams');
    gen.fixed('EN');
    gen.str('CABLE');
    gen.str('FIBER');
    gen.u16(652);
    gen.u16(s.wavelengthNm ?? 1310);
    gen.str(s.origin ?? 'ORIGIN');
    gen.str(s.termination ?? 'EXTREMITY');
    gen.str('');
    gen.fixed('BC');
    gen.i32(0);
    gen.i32(0);
    gen.str(s.operator ?? 'OPERATOR');
    gen.str('');

    const sup = new Writer();
    sup.str('SupParams');
    sup.str('SUPPLIER');
    sup.str('OTDR-X');
    sup.str('12345');
    sup.str('MOD-1');
    sup.str('678');
    sup.str('1.0');
    sup.str('');

    const fxd = new Writer();
    fxd.str('FxdParams');
    fxd.u32(Math.floor((s.date ?? new Date(Date.UTC(2026, 0, 2, 3, 4, 5))).getTime() / 1000));
    fxd.fixed('mt');
    fxd.u16(Math.round((s.wavelengthNm ?? 1310) * 10));
    fxd.i32(0);
    fxd.i32(0);
    fxd.u16(1);
    fxd.u16(pulseNs);
    fxd.u32(spacing);
    fxd.u32(n);
    fxd.i32(Math.round(ior * 100000));
    fxd.u16(790);
    fxd.u32(1000);
    fxd.u16(30);
    fxd.u32(Math.round(lengthKm / kmPerTime));

    const key = new Writer();
    key.str('KeyEvents');
    key.u16(s.events.length);
    s.events.forEach((e, i) => {
        key.u16(i + 1);
        key.u32(Math.round(e.km / kmPerTime));
        key.i16(Math.round((e.slope ?? 0.33) * 1000));
        key.i16(Math.round(e.loss * 1000));
        key.i32(Math.round((e.reflectance ?? 0) * 1000));
        key.fixed(e.code.padEnd(8, ' ').slice(0, 8));

        for (let m = 0; m < 5; m++) {
            key.u32(0);
        }

        key.str('');
    });
    key.i32(0);
    key.i32(0);
    key.i32(0);
    key.u16(32000);

    const pts = new Writer();
    pts.str('DataPts');
    pts.u32(n);
    pts.u16(1);
    pts.u32(n);
    pts.u16(1000);

    for (let i = 0; i < n; i++) {
        pts.u16(Math.max(0, Math.min(65535, Math.round(level(i * sampleKm) * 1000))));
    }

    const blocks: [string, Writer][] = [['GenParams', gen], ['SupParams', sup], ['FxdParams', fxd], ['KeyEvents', key], ['DataPts', pts]];
    const mapBody = (size: number) => {
        const w = new Writer();
        w.fixed('Map\0');
        w.u16(200);
        w.u32(size);
        w.u16(blocks.length + 1);

        for (const [name, b] of blocks) {
            w.str(name);
            w.u16(200);
            w.u32(b.length);
        }

        return w;
    };
    const size = mapBody(0).length;
    const head = mapBody(size);
    const all = [...head.out(), ...blocks.flatMap(([, b]) => b.out())];

    return new Uint8Array(all);
}

/** A distribution trace: launch connector, one splice, fiber end. */
export const distributionTrace = (wavelengthNm = 1310, splice = 0.12) => buildSor({
    wavelengthNm,
    origin: 'SRO-BPI-1234567',
    termination: 'PBO001',
    events: [
        { km: 0.5, loss: 0.25, reflectance: -60, code: '1F9999LS' },
        { km: 2.2, loss: splice, code: '0F9999LS' },
        { km: 4.1, loss: 0, reflectance: -40, code: '1E9999LS' },
    ],
});
