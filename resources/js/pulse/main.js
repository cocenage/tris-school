import { createApp } from 'vue';
import Pulse from './Pulse.vue';

let mounted;
let root;

export function unmountPulse() {
    mounted?.unmount();
    mounted = undefined;
    root = undefined;
}

export function mountPulse() {
    const next = document.querySelector('[data-tris-pulse]');
    if (!next || next === root) return;
    unmountPulse();
    root = next;
    mounted = createApp(Pulse, { endpoint: next.dataset.endpoint });
    mounted.mount(next);
}
