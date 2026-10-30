import type { Message } from '../engine/messages';

/** Translations of the `otdr` group (lang/{locale}/otdr.php), handed to the screen as JSON. */
export interface TextTree {
    [key: string]: string | TextTree;
}

export type Params = Record<string, string | number>;

const INTL_LOCALES: Record<string, string> = { en_GB: 'en-GB', fr: 'fr-FR', es: 'es-ES', uk: 'uk-UA', pt: 'pt-PT' };

/** BCP 47 locale used for numbers and dates of an application locale (`en_GB`, `fr`…). */
export const intlLocale = (appLocale: string): string => INTL_LOCALES[appLocale] ?? (appLocale.replace('_', '-') || 'en-GB');

/**
 * Numeric parameters of the library messages are rounded but not formatted. By default they are written with up to three
 * decimals and no digit grouping (counts, distances, wavelengths); these messages need another presentation.
 */
const MESSAGE_NUMBER_FORMATS: Record<string, Intl.NumberFormatOptions> = {
    failed_repair_status_over_limit: { minimumFractionDigits: 3, maximumFractionDigits: 3 },
    failed_repair_status_value_mismatch: { minimumFractionDigits: 3, maximumFractionDigits: 3 },
    criterion_detail_length_spread: { maximumFractionDigits: 1 },
    criterion_detail_total_length: { maximumFractionDigits: 0 },
};

const DEFAULT_MESSAGE_FORMAT: Intl.NumberFormatOptions = { maximumFractionDigits: 3, useGrouping: false };

/** Replaces Laravel `:name` placeholders (longest names first, so `:count` never eats `:count_total`). */
export function replaceParams(text: string, params: Params = {}): string {
    return Object.keys(params)
        .sort((a, b) => b.length - a.length)
        .reduce((out, name) => out.split(`:${name}`).join(String(params[name])), text);
}

/** Screen texts and locale-aware number/date formatting. */
export class Translator {
    readonly locale: string;
    private readonly tree: TextTree;
    private readonly formats = new Map<string, Intl.NumberFormat>();

    constructor(tree: TextTree, locale: string) {
        this.tree = tree;
        this.locale = locale;
    }

    /** Text of a dotted key of the `otdr` group (the key itself when missing). */
    t(key: string, params: Params = {}): string {
        let node: string | TextTree | undefined = this.tree;

        for (const part of key.split('.')) {
            node = typeof node === 'object' ? node[part] : undefined;
        }

        return typeof node === 'string' ? replaceParams(node, params) : key;
    }

    /** Library message, its numbers written for the locale. */
    message(m: Message): string {
        const options = MESSAGE_NUMBER_FORMATS[m.key] ?? DEFAULT_MESSAGE_FORMAT;
        const params: Params = {};

        for (const [name, value] of Object.entries(m.params)) {
            params[name] = typeof value === 'number' ? this.format(value, options) : value;
        }

        return this.t(`messages.${m.key}`, params);
    }

    /** Number with exactly `digits` decimals (grouped like the locale does). */
    fixed(value: number, digits: number): string {
        return this.format(value, { minimumFractionDigits: digits, maximumFractionDigits: digits });
    }

    /** Fixed decimals, empty for a missing value. */
    optionalFixed(value: number | null | undefined, digits: number): string {
        return value === null || value === undefined ? '' : this.fixed(value, digits);
    }

    /** Indicator value: integers as is, other numbers with two decimals. */
    indicator(value: number | string): string {
        if (typeof value === 'string') {
            return value;
        }

        return Number.isInteger(value) ? String(value) : this.fixed(value, 2);
    }

    /** Value shown in a number input (decimal separator of the locale). */
    inputValue(value: number | null): string {
        if (value === null || !Number.isFinite(value)) {
            return '';
        }

        return String(value).replace('.', this.decimalSeparator());
    }

    dateTime(date: Date): string {
        return date.toLocaleString(this.locale);
    }

    format(value: number, options: Intl.NumberFormatOptions): string {
        const key = JSON.stringify(options);
        let formatter = this.formats.get(key);

        if (!formatter) {
            formatter = new Intl.NumberFormat(this.locale, options);
            this.formats.set(key, formatter);
        }

        return formatter.format(value);
    }

    private decimalSeparator(): string {
        return this.format(1.5, { minimumFractionDigits: 1 }).replace(/\d/g, '') || '.';
    }
}

/** Number typed by the user: comma or dot as decimal separator, undefined when empty or invalid. */
export function parseDecimal(text: string): number | undefined {
    const trimmed = text.trim().replace(',', '.');

    if (trimmed === '') {
        return undefined;
    }

    const value = Number(trimmed);

    return Number.isFinite(value) ? value : undefined;
}
