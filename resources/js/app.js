import './bootstrap';
import { controlMediaGallery } from './control-media-gallery';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('controlMediaGallery', controlMediaGallery);
});

// Mount the Vue island only on pages which own a Pulse root.
let pulseModule;
let pulseGeneration = 0;
async function syncPulse() {
    const current = ++pulseGeneration;
    if (!document.querySelector('[data-tris-pulse]')) return;
    pulseModule ??= import('./pulse/main.js');
    const pulse = await pulseModule;
    if (current === pulseGeneration) pulse.mountPulse();
}
function disposePulse() {
    pulseGeneration++;
    pulseModule?.then(pulse => pulse.unmountPulse());
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncPulse, { once: true });
else syncPulse();
document.addEventListener('livewire:navigating', disposePulse);
document.addEventListener('livewire:navigated', syncPulse);
