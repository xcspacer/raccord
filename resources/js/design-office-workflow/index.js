import reviewCountdown from './countdown';
import photoMarkup from './photo-markup';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reviewCountdown', reviewCountdown);
    window.Alpine.data('photoMarkup', photoMarkup);
});
