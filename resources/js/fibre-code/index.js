import converter from './converter';

/**
 * Registers the « Code Fibre » screen with Alpine (integrated page and standalone app).
 */
document.addEventListener('alpine:init', () => window.Alpine.data('fibreCode', converter));
