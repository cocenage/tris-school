import './bootstrap';
import { controlMediaGallery } from './control-media-gallery';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('controlMediaGallery', controlMediaGallery);
});
