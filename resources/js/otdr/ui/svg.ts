// SVG drawings of the screen, built from JS (Alpine templates cannot live inside an <svg>): the measurement diagram in
// the spirit of the FiberCable "OTDR settings" windows (OTDR, launch spools in blue, measured fiber in green dashes,
// direct loop-back as a red dot, arrows for the direction), its small choice icons and the trace plot.
// Colours that follow the theme are CSS classes (otdr.css); the fixed drawing colours are SVG attributes.
import type { AcquisitionConfig } from '../engine/profile';
import { clipSegment, PLOT_PADDING, type EventMarkers, type MarkerTone, type PlotGeometry, type PlotWindow } from './trace-model';

const SVG_NS = 'http://www.w3.org/2000/svg';

const SPOOL = '#5AA9E6';
const SPOOL_INNER = '#2F6FA8';
const FIBER = '#57C785';
const CONNECTOR = '#F2A104';
const LOOP_DOT = '#E4572E';

type Attributes = Record<string, string | number>;

function node(name: string, attributes: Attributes, parent: Element, text?: string): SVGElement {
    const element = document.createElementNS(SVG_NS, name);

    for (const [key, value] of Object.entries(attributes)) {
        element.setAttribute(key, String(value));
    }

    if (text !== undefined) {
        element.textContent = text;
    }

    parent.appendChild(element);

    return element;
}

const clear = (svg: Element) => svg.replaceChildren();

// ------------------------------------------------------------------ measurement diagram

export interface SchemaTexts {
    classic: string;
    loop: string;
    otdr: string;
    cable: string;
    fiberEnd: string;
    direction: string;
    fiberN: string;
    fiberNext: string;
    fiberPair: string;
    end: string;
}

/** Launch spool: concentric rings, like the FiberCable icon. */
function spool(parent: Element, x: number, y: number, r = 11): void {
    const g = node('g', {}, parent);
    node('circle', { cx: x, cy: y, r, fill: 'none', stroke: SPOOL, 'stroke-width': 2.2 }, g);
    node('circle', { cx: x, cy: y, r: r * 0.68, fill: 'none', stroke: SPOOL, 'stroke-width': 1.6 }, g);
    node('circle', { cx: x, cy: y, r: r * 0.36, fill: 'none', stroke: SPOOL_INNER, 'stroke-width': 1.4 }, g);
}

/** OTDR: box with a screen and a trace. */
function otdr(parent: Element, x: number, y: number, label: string): void {
    const g = node('g', {}, parent);
    node('rect', { x, y: y - 14, width: 34, height: 28, rx: 3, fill: '#1F3D4D', 'stroke-width': 1.2, class: 'otdr__svg-ink-stroke' }, g);
    node('rect', { x: x + 4, y: y - 10, width: 18, height: 14, rx: 1.5, fill: '#0B1E28', stroke: '#3E6A80' }, g);
    node('path', { d: `M${x + 5} ${y - 7} l4 3 l3 1 l2 4 l3 1 l2 1`, fill: 'none', stroke: FIBER, 'stroke-width': 1.2 }, g);
    node('circle', { cx: x + 28, cy: y - 6, r: 1.8, fill: CONNECTOR }, g);
    node('circle', { cx: x + 28, cy: y + 1, r: 1.8, fill: '#3E6A80' }, g);
    node('text', { x: x + 17, y: y + 24, 'font-size': 9, 'text-anchor': 'middle', class: 'otdr__svg-ink' }, g, label);
}

function connector(parent: Element, x: number, y: number, label?: string): void {
    const g = node('g', {}, parent);
    node('rect', { x: x - 3.5, y: y - 3.5, width: 7, height: 7, fill: CONNECTOR, rx: 1 }, g);

    if (label) {
        node('text', { x, y: y - 8, 'font-size': 10, 'font-weight': 700, 'text-anchor': 'middle', fill: CONNECTOR }, g, label);
    }
}

function line(parent: Element, x1: number, x2: number, y: number, fiber = false): void {
    node('line', { x1, x2, y1: y, y2: y, stroke: fiber ? FIBER : SPOOL, 'stroke-width': fiber ? 2.6 : 1.6, ...(fiber ? { 'stroke-dasharray': '6 4' } : {}) }, parent);
}

/** Chain of `count` spools from x0; returns the exit abscissa. */
function spoolChain(parent: Element, count: number, x0: number, y: number, step = 34): number {
    let x = x0;

    for (let i = 0; i < count; i++) {
        line(parent, x, x + 8, y);
        spool(parent, x + 20, y);
        x += step;
    }

    return x;
}

function arrow(parent: Element, x1: number, x2: number, y: number, color = FIBER): void {
    const direction = x2 > x1 ? 1 : -1;
    const g = node('g', {}, parent);
    node('line', { x1, x2, y1: y, y2: y, stroke: color, 'stroke-width': 1.6 }, g);
    node('path', { d: `M${x2} ${y} l${-7 * direction} -4 v8 z`, fill: color }, g);
}

/** Diagram of the current setting (fixed width, scaled by its box). */
export function drawAcquisitionDiagram(svg: SVGSVGElement, cfg: AcquisitionConfig, texts: SchemaTexts): void {
    clear(svg);
    const width = 560;

    if (!cfg.loop) {
        const y = 46;
        svg.setAttribute('viewBox', `0 0 ${width} 100`);
        svg.setAttribute('aria-label', texts.classic);
        otdr(svg, 6, y, texts.otdr);
        const xs = spoolChain(svg, cfg.startSpools, 44, y);
        const cableEnd = Math.max(xs + 150, 330);
        line(svg, xs, cableEnd, y, true);
        connector(svg, xs, y, 'O');
        connector(svg, cableEnd, y, 'E');
        node('text', { x: (xs + cableEnd) / 2, y: y + 20, 'font-size': 11, 'text-anchor': 'middle', fill: FIBER }, svg, texts.cable);
        const xe = spoolChain(svg, cfg.endSpools, cableEnd, y);

        if (cfg.endSpools === 0) {
            node('text', { x: cableEnd + 8, y: y + 4, 'font-size': 10, class: 'otdr__svg-ink' }, svg, texts.fiberEnd);
        } else {
            node('line', { x1: xe, x2: xe + 6, y1: y, y2: y, stroke: SPOOL, 'stroke-width': 1.6 }, svg);
        }

        arrow(svg, xs + 20, cableEnd - 20, y + 34);
        node('text', { x: (xs + cableEnd) / 2, y: y + 48, 'font-size': 10, 'text-anchor': 'middle', class: 'otdr__svg-ink' }, svg, texts.direction);

        return;
    }

    // Loop: fiber a (top) O→E, looped back at E, fiber b (bottom) E→O, receive spool on the O side.
    const yA = 34;
    const yB = 92;
    svg.setAttribute('viewBox', `0 0 ${width} 132`);
    svg.setAttribute('aria-label', texts.loop);
    otdr(svg, 6, yA, texts.otdr);
    const xs = spoolChain(svg, cfg.startSpools, 44, yA);
    const xE = Math.max(xs + 170, 360);
    line(svg, xs, xE, yA, true);
    line(svg, xs, xE, yB, true);
    connector(svg, xs, yA, 'O');
    connector(svg, xE, yA, 'E');
    connector(svg, xs, yB);
    connector(svg, xE, yB);
    node('text', { x: (xs + xE) / 2, y: yA - 8, 'font-size': 10.5, 'text-anchor': 'middle', fill: FIBER }, svg, texts.fiberN);
    node('text', { x: (xs + xE) / 2, y: yB + 18, 'font-size': 10.5, 'text-anchor': 'middle', fill: FIBER }, svg, cfg.sequence === 'n,n' ? texts.fiberNext : texts.fiberPair);
    const loopX = xE + 30;

    if (cfg.loopSpools === 0) {
        node('path', { d: `M${xE} ${yA} C ${loopX} ${yA}, ${loopX} ${yB}, ${xE} ${yB}`, fill: 'none', stroke: FIBER, 'stroke-width': 2.6, 'stroke-dasharray': '6 4' }, svg);
        node('circle', { cx: loopX - 7, cy: (yA + yB) / 2, r: 4.5, fill: LOOP_DOT }, svg);
    } else {
        node('path', { d: `M${xE} ${yA} H ${loopX} V ${yA + 8}`, fill: 'none', stroke: SPOOL, 'stroke-width': 1.6 }, svg);
        node('path', { d: `M${xE} ${yB} H ${loopX} V ${yB - 8}`, fill: 'none', stroke: SPOOL, 'stroke-width': 1.6 }, svg);
        const gap = (yB - yA - 16) / cfg.loopSpools;

        for (let i = 0; i < cfg.loopSpools; i++) {
            spool(svg, loopX, yA + 8 + gap * (i + 0.5), Math.min(11, gap / 2 - 1));
        }
    }

    spoolChain(svg, cfg.endSpools, 44, yB);

    if (cfg.endSpools === 0) {
        node('text', { x: xs - 6, y: yB + 4, 'font-size': 10, 'text-anchor': 'end', class: 'otdr__svg-ink' }, svg, texts.end);
    }

    const fromO = cfg.loopDirection !== 'EO';
    const fromE = cfg.loopDirection !== 'OE';

    if (fromO) {
        arrow(svg, xs + 30, xs + 90, yA + 14);
        arrow(svg, xE - 30, xE - 90, yB - 14);
    }

    if (fromE) {
        arrow(svg, xE - 30, xE - 90, yA + 14, CONNECTOR);
        arrow(svg, xs + 30, xs + 90, yB - 14, CONNECTOR);
    }
}

// ------------------------------------------------------------------ choice icons

export type IconKind = 'spools' | 'loop' | 'direction' | 'measure';

function size(svg: SVGSVGElement, width: number, height: number): void {
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('width', String(width));
    svg.setAttribute('height', String(height));
}

/** n spools (0 = direct connection). */
function spoolsIcon(svg: SVGSVGElement, n: number): void {
    size(svg, 96, 30);
    node('line', { x1: 2, x2: n === 0 ? 70 : 10, y1: 15, y2: 15, stroke: n === 0 ? FIBER : SPOOL, 'stroke-width': 2, ...(n === 0 ? { 'stroke-dasharray': '5 3' } : {}) }, svg);

    for (let i = 0; i < n; i++) {
        spool(svg, 20 + i * 25, 15, 9);
    }

    if (n > 0) {
        node('line', { x1: 20 + (n - 1) * 25 + 10, x2: 94, y1: 15, y2: 15, stroke: FIBER, 'stroke-width': 2, 'stroke-dasharray': '5 3' }, svg);
    } else {
        node('rect', { x: 70, y: 11, width: 8, height: 8, fill: CONNECTOR }, svg);
    }
}

/** Loop-back (0 = red dot, otherwise spools side by side). */
function loopIcon(svg: SVGSVGElement, n: number): void {
    size(svg, 70, 36);
    node('line', { x1: 2, x2: 40, y1: 8, y2: 8, stroke: FIBER, 'stroke-width': 2, 'stroke-dasharray': '5 3' }, svg);
    node('line', { x1: 2, x2: 40, y1: 28, y2: 28, stroke: FIBER, 'stroke-width': 2, 'stroke-dasharray': '5 3' }, svg);

    if (n === 0) {
        node('path', { d: 'M40 8 C 58 8, 58 28, 40 28', fill: 'none', stroke: FIBER, 'stroke-width': 2 }, svg);
        node('circle', { cx: 53, cy: 18, r: 4, fill: LOOP_DOT }, svg);

        return;
    }

    node('path', { d: 'M40 8 H46 V12', fill: 'none', stroke: SPOOL, 'stroke-width': 1.5 }, svg);
    node('path', { d: `M40 28 H${46 + (n - 1) * 9} V24`, fill: 'none', stroke: SPOOL, 'stroke-width': 1.5 }, svg);

    for (let i = 0; i < n; i++) {
        spool(svg, 46 + i * 9, 18, n === 1 ? 7 : 5);
    }
}

/** Direction of a loop measurement. */
function directionIcon(svg: SVGSVGElement, direction: AcquisitionConfig['loopDirection']): void {
    size(svg, 90, 30);
    node('line', { x1: 14, x2: 76, y1: 15, y2: 15, stroke: FIBER, 'stroke-width': 2, 'stroke-dasharray': '5 3' }, svg);
    node('text', { x: 6, y: 19, 'font-size': 11, 'font-weight': 700, fill: CONNECTOR, 'text-anchor': 'middle' }, svg, 'O');
    node('text', { x: 84, y: 19, 'font-size': 11, 'font-weight': 700, fill: CONNECTOR, 'text-anchor': 'middle' }, svg, 'E');

    if (direction !== 'EO') {
        node('path', { d: 'M58 6 l8 4 l-8 4 z', fill: FIBER }, svg);
    }

    if (direction !== 'OE') {
        node('path', { d: 'M32 16 l-8 4 l8 4 z', fill: CONNECTOR }, svg);
    }
}

/** Classic or loop measurement. */
function measureIcon(svg: SVGSVGElement, loop: boolean): void {
    size(svg, 90, 34);
    node('rect', { x: 1, y: 9, width: 14, height: 14, rx: 2, fill: '#1F3D4D', class: 'otdr__svg-ink-stroke' }, svg);

    if (loop) {
        node('line', { x1: 15, x2: 70, y1: 10, y2: 10, stroke: FIBER, 'stroke-width': 2, 'stroke-dasharray': '5 3' }, svg);
        node('line', { x1: 15, x2: 70, y1: 26, y2: 26, stroke: FIBER, 'stroke-width': 2, 'stroke-dasharray': '5 3' }, svg);
        node('path', { d: 'M70 10 C 86 10, 86 26, 70 26', fill: 'none', stroke: FIBER, 'stroke-width': 2 }, svg);
    } else {
        node('line', { x1: 15, x2: 86, y1: 16, y2: 16, stroke: FIBER, 'stroke-width': 2, 'stroke-dasharray': '5 3' }, svg);
    }
}

export function drawIcon(svg: SVGSVGElement, kind: IconKind, value: string | number | boolean): void {
    clear(svg);

    if (kind === 'spools') {
        spoolsIcon(svg, Number(value));
    } else if (kind === 'loop') {
        loopIcon(svg, Number(value));
    } else if (kind === 'direction') {
        directionIcon(svg, value as AcquisitionConfig['loopDirection']);
    } else {
        measureIcon(svg, value === true);
    }
}

// ------------------------------------------------------------------ trace plot

export interface PlotEvent {
    km: number;
    num: number;
    label: string;
    tone: MarkerTone;
    selected: boolean;
    ignored: boolean;
}

export interface TraceDrawing {
    width: number;
    height: number;
    window: PlotWindow;
    geometry: PlotGeometry;
    ticks: { km: number; label: string }[];
    levelTop: string;
    levelBottom: string;
    markers: EventMarkers | null;
    events: PlotEvent[];
}

/** Draws the trace; `onEvent` is called when an event marker is touched. */
export function drawTrace(svg: SVGSVGElement, d: TraceDrawing, onEvent: (event: PlotEvent, e: MouseEvent) => void): void {
    clear(svg);
    const { width: w, height: h, geometry: g } = d;
    const [x0, x1] = d.window;
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    svg.setAttribute('width', String(w));
    svg.setAttribute('height', String(h));

    for (const tick of d.ticks) {
        const x = g.x(tick.km);
        node('line', { x1: x, x2: x, y1: PLOT_PADDING.top, y2: h - PLOT_PADDING.bottom, class: 'otdr__plot-grid' }, svg);
        node('text', { x, y: h - 6, 'font-size': 11, 'text-anchor': 'middle', class: 'otdr__plot-label' }, svg, tick.label);
    }

    node('text', { x: 4, y: PLOT_PADDING.top + 10, 'font-size': 11, class: 'otdr__plot-label' }, svg, d.levelTop);
    node('text', { x: 4, y: h - PLOT_PADDING.bottom, 'font-size': 11, class: 'otdr__plot-label' }, svg, d.levelBottom);
    node('path', { d: g.path, fill: 'none', stroke: '#7FD0E8', 'stroke-width': 1.2 }, svg);

    if (d.markers) {
        const group = node('g', { 'pointer-events': 'none' }, svg);

        d.markers.positions.forEach((km, k) => {
            if (km < x0 || km > x1) {
                return;
            }

            const x = g.x(km);
            node('line', { x1: x, x2: x, y1: PLOT_PADDING.top + 18, y2: h - PLOT_PADDING.bottom, 'stroke-width': 1, 'stroke-dasharray': '3 3', class: 'otdr__plot-marker' }, group);
            node('text', { x: x + 3, y: h - PLOT_PADDING.bottom - 4, 'font-size': 10, class: 'otdr__plot-marker-label' }, group, String(k + 1));
        });

        for (const segment of [d.markers.before, d.markers.after]) {
            const [[ka, da], [kb, db]] = clipSegment(segment, d.window);
            node('line', { x1: g.x(ka), x2: g.x(kb), y1: g.y(da), y2: g.y(db), stroke: CONNECTOR, 'stroke-width': 1.6 }, group);
        }
    }

    for (const event of d.events) {
        if (event.km < x0 || event.km > x1) {
            continue;
        }

        const x = g.x(event.km);
        const group = node('g', { class: `otdr__plot-event otdr__plot-event--${event.tone}${event.selected ? ' is-selected' : ''}` }, svg);
        node('line', {
            x1: x, x2: x, y1: PLOT_PADDING.top, y2: h - PLOT_PADDING.bottom, 'stroke-width': event.selected ? 3 : 2,
            ...(event.ignored ? { 'stroke-dasharray': '4 4' } : {}), ...(d.markers && event.selected ? { opacity: 0.35 } : {}),
            class: 'otdr__plot-event-line',
        }, group);
        node('rect', { x: x - 13, y: PLOT_PADDING.top, width: 26, height: 16, rx: 3, class: 'otdr__plot-event-tag' }, group);
        node('text', { x, y: PLOT_PADDING.top + 12, 'font-size': 11, 'text-anchor': 'middle', 'font-weight': 700, class: 'otdr__plot-event-label' }, group, event.label);
        group.addEventListener('click', (e) => onEvent(event, e as MouseEvent));
    }
}
