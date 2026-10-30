/**
 * Screen messages emitted by the library. The library never builds sentences shown to the user: it emits a key (English
 * snake_case, translated in `lang/{locale}/otdr.php` under `messages`) and its parameters (Laravel `:name` placeholders).
 * Numeric parameters are raw numbers already rounded to their display precision; the UI formats them for the locale.
 */
export interface Message {
    key: string;
    params: Record<string, string | number>;
}

export const message = (key: string, params: Record<string, string | number> = {}): Message => ({ key, params });

/** Error whose cause is a translatable message. */
export class OtdrError extends Error {
    readonly detail: Message;

    constructor(detail: Message) {
        super(detail.key);
        this.name = 'OtdrError';
        this.detail = detail;
    }
}
