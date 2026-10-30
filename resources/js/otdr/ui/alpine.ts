/** The few Alpine features the screen uses (Alpine itself comes with Livewire, in its CSP-safe build). */
export interface AlpineGlobal {
    data(name: string, factory: () => object): void;
    raw<T>(value: T): T;
}

export interface AlpineMagics {
    $root: HTMLElement;
    $refs: Record<string, HTMLElement | undefined>;
    $nextTick(callback?: () => void): Promise<void>;
}

declare global {
    interface Window {
        Alpine: AlpineGlobal;
    }
}

/** Types `this` inside an Alpine component definition (its own members plus the magics). */
export const component = <T extends object>(definition: T & ThisType<T & AlpineMagics>): T => definition;

/** The plain object behind an Alpine reactive proxy (what the library is given). */
export const raw = <T>(value: T): T => (typeof window !== 'undefined' && window.Alpine?.raw ? window.Alpine.raw(value) : value);
