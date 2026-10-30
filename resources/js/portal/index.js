import lightbox from './lightbox';
import { initPortalLiveStatus } from './live-status';

initPortalLiveStatus();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('portalLightbox', lightbox);
});
