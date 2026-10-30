// Bellcore / Telcordia SR-4731 version 2 reader (.sor).
// Only the standard blocks are decoded (GenParams, SupParams, FxdParams, KeyEvents, DataPts); vendor blocks
// (Viavi/JDSU/Acterna…) are listed but ignored.

import { message, type Message, OtdrError } from './messages';

const C_KM_PER_S = 299792.458;

export interface SorEvent {
    /** Event number (1…n) in the file. */
    num: number;
    /** Raw round-trip time, unit 100 ps. */
    time: number;
    /** Distance from the OTDR, km. */
    km: number;
    /** Slope of the section before the event, dB/km. */
    slope: number;
    /** Event loss, dB. */
    loss: number;
    /** Reflectance, dB (0 = non reflective). */
    reflectance: number;
    /** SR-4731 event code (e.g. "0F9999LS"): 1st char 0/1/2 = non reflective/reflective/saturated, 2nd char E = fiber end. */
    code: string;
    /** LSA markers: end of previous section, event start, event end, start of next section, peak (unit 100 ps). */
    marks: [number, number, number, number, number];
    comment: string;
    /** Original OTDR slope (complete analysis). */
    otdrSlope?: number;
    /** Origin of the kept slope: OTDR confirmed by the trace, LSA line, slope of the fiber (section too short). */
    slopeSource?: 'otdr' | 'lsa' | 'fiber';
}

export interface SorFile {
    name: string;
    blocks: { name: string; version: number; size: number; offset: number }[];
    cableId: string;
    fiberId: string;
    origin: string;
    termination: string;
    operator: string;
    comment: string;
    supplier: string;
    otdr: string;
    otdrSerial: string;
    module: string;
    moduleSerial: string;
    software: string;
    /** Measurement date (UTC). */
    date: Date;
    wavelengthNm: number;
    pulseNs: number;
    /** Group refractive index. */
    ior: number;
    backscatterDb: number;
    averagingTimeS: number;
    /** Acquisition range, km. */
    rangeKm: number;
    /** Sampling step, km. */
    sampleKm: number;
    events: SorEvent[];
    /** Link ORL, dB (0 = not given). */
    orl: number;
    /** Trace levels, dB (increasing value = attenuation). */
    data: Float32Array;
}

/** Unreadable .sor file; `detail` is the warning shown for the file. */
export class SorFormatError extends OtdrError {}

class Reader {
    private readonly view: DataView;
    private readonly bytes: Uint8Array;
    pos = 0;

    constructor(private readonly fileName: string, buf: ArrayBuffer | Uint8Array) {
        this.bytes = buf instanceof Uint8Array ? buf : new Uint8Array(buf);
        this.view = new DataView(this.bytes.buffer, this.bytes.byteOffset, this.bytes.byteLength);
    }

    /** Aligned copy of the bytes (vendor block reading). */
    get buffer(): ArrayBuffer {
        return this.bytes.slice().buffer;
    }

    get length(): number {
        return this.bytes.length;
    }

    u16(): number {
        const v = this.view.getUint16(this.pos, true);
        this.pos += 2;

        return v;
    }

    i16(): number {
        const v = this.view.getInt16(this.pos, true);
        this.pos += 2;

        return v;
    }

    u32(): number {
        const v = this.view.getUint32(this.pos, true);
        this.pos += 4;

        return v;
    }

    i32(): number {
        const v = this.view.getInt32(this.pos, true);
        this.pos += 4;

        return v;
    }

    str(): string {
        const end = this.bytes.indexOf(0, this.pos);

        if (end < 0) {
            throw new SorFormatError(message('file_unreadable_unterminated_string', { file: this.fileName }));
        }

        let s = '';

        for (let i = this.pos; i < end; i++) {
            s += String.fromCharCode(this.bytes[i]!);
        }

        this.pos = end + 1;

        return s;
    }

    fixed(n: number): string {
        let s = '';

        for (let i = 0; i < n; i++) {
            s += String.fromCharCode(this.bytes[this.pos + i]!);
        }

        this.pos += n;

        return s;
    }
}

export function readSor(name: string, buf: ArrayBuffer | Uint8Array): SorFile {
    const r = new Reader(name, buf);

    if (r.length < 16 || r.fixed(4) !== 'Map\0') {
        throw new SorFormatError(message('file_unreadable_unknown_format', { file: name }));
    }

    r.u16(); // Map version
    const mapSize = r.u32();
    const nBlocks = r.u16();
    const blocks: SorFile['blocks'] = [];
    let offset = mapSize;

    for (let i = 0; i < nBlocks - 1; i++) {
        const blockName = r.str();
        const version = r.u16();
        const size = r.u32();
        blocks.push({ name: blockName, version, size, offset });
        offset += size;
    }

    const at = (blockName: string): number => {
        const b = blocks.find((x) => x.name === blockName);

        if (!b) {
            throw new SorFormatError(message('file_unreadable_block_missing', { file: name, block: blockName }));
        }

        return b.offset + blockName.length + 1;
    };

    // GenParams
    r.pos = at('GenParams') + 2; // language code
    const cableId = r.str();
    const fiberId = r.str();
    r.pos += 4; // fiber type, nominal wavelength
    const origin = r.str();
    const termination = r.str();
    r.str(); // cable code
    r.pos += 2 + 8; // data flag, user offsets
    const operator = r.str();
    const comment = r.str();

    // SupParams
    r.pos = at('SupParams');
    const supplier = r.str();
    const otdr = r.str();
    const otdrSerial = r.str();
    const moduleName = r.str();
    const moduleSerial = r.str();
    const software = r.str();

    // FxdParams
    r.pos = at('FxdParams');
    const date = new Date(r.u32() * 1000);
    r.pos += 2; // units
    const wavelengthNm = r.u16() / 10;
    r.pos += 8; // acquisition offsets
    const nPulses = r.u16();
    const pulses: number[] = [];

    for (let i = 0; i < nPulses; i++) {
        pulses.push(r.u16());
    }

    const spacing: number[] = [];

    for (let i = 0; i < nPulses; i++) {
        spacing.push(r.u32());
    }

    for (let i = 0; i < nPulses; i++) {
        r.u32(); // number of points
    }

    const ior = r.i32() / 100000;
    const backscatterDb = -r.u16() / 10;
    r.u32(); // number of averages
    const averagingTimeS = r.u16();
    const rangeRaw = r.u32();
    const kmPerTime = (1e-10 * C_KM_PER_S) / ior;
    // step: acquisition time of 10 000 points, unit 100 ps
    const sampleKm = ((spacing[0] ?? 0) / 10000) * kmPerTime;

    // KeyEvents
    r.pos = at('KeyEvents');
    const nEvents = r.u16();
    const events: SorEvent[] = [];

    for (let i = 0; i < nEvents; i++) {
        const num = r.u16();
        const time = r.u32();
        const slope = r.i16() / 1000;
        const loss = r.i16() / 1000;
        const reflectance = r.i32() / 1000;
        const code = r.fixed(8);
        const marks: SorEvent['marks'] = [r.u32(), r.u32(), r.u32(), r.u32(), r.u32()];
        const eventComment = r.str();
        events.push({ num, time, km: time * kmPerTime, slope, loss, reflectance, code, marks, comment: eventComment });
    }

    r.pos += 4 + 4 + 4; // total loss, fiber start and length
    let orl = r.u16() / 1000;
    // Viavi OTDRs leave the standard ORL at 0 and store it in their WaveMTSParams block (float32 at +410).
    const wave = blocks.find((b) => b.name === 'WaveMTSParams');

    if (orl === 0 && wave && wave.size >= 414) {
        const v = new DataView(r.buffer).getFloat32(wave.offset + 410, true);

        if (Number.isFinite(v) && v > 0 && v < 100) {
            orl = v;
        }
    }

    // DataPts
    r.pos = at('DataPts');
    r.u32(); // total number of points
    const nTraces = r.u16();

    if (nTraces < 1) {
        throw new SorFormatError(message('file_unreadable_no_trace', { file: name }));
    }

    const n = r.u32();
    const scale = r.u16();
    const factor = scale / 1e6;
    const data = new Float32Array(n);

    for (let i = 0; i < n; i++) {
        data[i] = r.u16() * factor;
    }

    return {
        name, blocks, cableId, fiberId, origin, termination, operator, comment,
        supplier, otdr, otdrSerial, module: moduleName, moduleSerial, software,
        date, wavelengthNm, pulseNs: pulses[0] ?? 0, ior, backscatterDb, averagingTimeS,
        rangeKm: rangeRaw * kmPerTime,
        sampleKm, events, orl, data,
    };
}

/** Warning shown for a file that cannot be read (format error, or truncated file). */
export function unreadableFileMessage(name: string, error: unknown): Message {
    if (error instanceof OtdrError) {
        return error.detail;
    }

    return message('file_unreadable', { file: name, error: error instanceof Error ? error.message : String(error) });
}

/** Trace point index of a raw time (100 ps). */
export function timeToIndex(sor: SorFile, time: number): number {
    const kmPerTime = (1e-10 * C_KM_PER_S) / sor.ior;

    return Math.round((time * kmPerTime) / sor.sampleKm);
}

export function isEndEvent(e: SorEvent): boolean {
    return e.code.charAt(1) === 'E';
}
