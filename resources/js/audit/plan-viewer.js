import { fetchWithTimeout } from '../components/http';

const MIN_ZOOM = 0.4;
const MAX_ZOOM = 2.5;
const ZOOM_STEP = 0.15;

const columnLetters = (index) => {
    let name = '';
    let number = index + 1;

    while (number > 0) {
        const rest = (number - 1) % 26;

        name = String.fromCharCode(65 + rest) + name;
        number = Math.floor((number - 1) / 26);
    }

    return name;
};

/**
 * Shows the sheets of a plan read by the server: column letters, row numbers, merged cells, colours, borders and
 * hidden rows and columns left out. Colours are set through the DOM (the page allows no inline style attributes).
 */
export default () => ({
    config: {},
    sheets: [],
    styles: [],
    current: 0,
    query: '',
    zoom: 0.85,
    message: '',

    async init() {
        this.config = JSON.parse(this.$el.dataset.config);
        this.zoom = window.innerWidth < 640 ? 0.55 : 0.85;
        this.message = this.t('loading');

        try {
            const response = await fetchWithTimeout(this.config.data, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const body = await response.json();

            if (!body.ok) {
                this.message = this.t('unreadable');

                return;
            }

            this.sheets = body.sheets;
            this.styles = body.styles;
            this.message = this.sheets.length === 0 ? this.t('empty') : '';
            this.render();
        } catch {
            this.message = this.t('unreadable');
        }
    },

    t(key) {
        return this.config.texts?.[key] ?? key;
    },

    get title() { return document.title.split(' — ')[0]; },
    get backUrl() { return this.config.back ?? '#'; },
    get downloadUrl() { return this.config.download ?? '#'; },
    get backText() { return `← ${this.t('back')}`; },
    get downloadText() { return `⬇ ${this.t('download')}`; },

    tabClass(index) {
        return index === this.current ? 'is-active' : '';
    },

    select(index) {
        this.current = index;
        this.render();
    },

    zoomIn() {
        this.zoom = Math.min(MAX_ZOOM, this.zoom + ZOOM_STEP);
        this.applyZoom();
    },

    zoomOut() {
        this.zoom = Math.max(MIN_ZOOM, this.zoom - ZOOM_STEP);
        this.applyZoom();
    },

    applyZoom() {
        const table = this.$refs.sheet.firstElementChild;

        if (table) {
            table.style.zoom = String(this.zoom);
        }
    },

    render() {
        const sheet = this.sheets[this.current];
        const host = this.$refs.sheet;

        host.replaceChildren();

        if (!sheet) {
            return;
        }

        const covered = new Set();
        const spans = new Map();

        sheet.merges.forEach(([firstRow, firstColumn, lastRow, lastColumn]) => {
            spans.set(`${firstRow}:${firstColumn}`, [lastRow - firstRow + 1, lastColumn - firstColumn + 1]);

            for (let row = firstRow; row <= lastRow; row++) {
                for (let column = firstColumn; column <= lastColumn; column++) {
                    if (row !== firstRow || column !== firstColumn) {
                        covered.add(`${row}:${column}`);
                    }
                }
            }
        });

        const table = document.createElement('table');
        const head = table.createTHead().insertRow();

        table.className = 'plan__table';
        head.appendChild(document.createElement('th')).className = 'plan__corner';

        sheet.columns.forEach((column, index) => {
            if (!column.hidden) {
                const cell = document.createElement('th');

                cell.className = 'plan__head';
                cell.textContent = columnLetters(index);
                cell.style.minWidth = `${column.width}px`;
                head.appendChild(cell);
            }
        });

        const body = table.createTBody();

        sheet.rows.forEach((row, rowIndex) => {
            if (row.hidden) {
                return;
            }

            const line = body.insertRow();
            const number = document.createElement('th');

            number.className = 'plan__row';
            number.textContent = String(rowIndex + 1);
            line.appendChild(number);

            if (row.height) {
                line.style.height = `${row.height}px`;
            }

            sheet.columns.forEach((column, columnIndex) => {
                const key = `${rowIndex + 1}:${columnIndex + 1}`;

                if (column.hidden || covered.has(key)) {
                    return;
                }

                const data = sheet.cells[rowIndex][columnIndex];
                const cell = line.insertCell();
                const span = spans.get(key);

                cell.textContent = data.v;
                cell.dataset.text = data.v.toLowerCase();
                this.paint(cell, this.styles[data.s]);

                if (span) {
                    cell.rowSpan = span[0];
                    cell.colSpan = span[1];
                }
            });
        });

        host.appendChild(table);
        this.applyZoom();
        this.highlight();
    },

    paint(cell, style) {
        if (style.bg) {
            cell.style.backgroundColor = style.bg;
        }

        if (style.color) {
            cell.style.color = style.color;
        }

        cell.style.fontWeight = style.bold ? '700' : '400';
        cell.style.fontStyle = style.italic ? 'italic' : 'normal';

        if (style.size) {
            cell.style.fontSize = `${style.size * 1.333}px`;
        }

        if (style.align) {
            cell.style.textAlign = style.align;
        }

        if (style.valign) {
            cell.style.verticalAlign = style.valign === 'center' ? 'middle' : style.valign;
        }

        cell.style.whiteSpace = style.wrap ? 'pre-wrap' : 'nowrap';
        style.borders.forEach((side) => {
            cell.style[`border${side.charAt(0).toUpperCase()}${side.slice(1)}`] = '1px solid #444';
        });
    },

    highlight() {
        const needle = this.query.trim().toLowerCase();

        this.$refs.sheet.querySelectorAll('td').forEach((cell) => {
            cell.classList.toggle('is-found', needle !== '' && (cell.dataset.text ?? '').includes(needle));
        });
    },
});
