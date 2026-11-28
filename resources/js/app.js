import './bootstrap';
import { controlMediaGallery } from './control-media-gallery';
import { mobileBottomSheet } from './mobile-bottom-sheet';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('controlMediaGallery', controlMediaGallery);
    window.Alpine.data('mobileBottomSheet', mobileBottomSheet);
});
