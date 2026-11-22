import { createApp } from 'vue';
import FocusV2App from './FocusV2App.vue';

const root = document.getElementById('focus-v2-app');
if (root) {
    createApp(FocusV2App, {
        bootstrapUrl: root.dataset.bootstrapUrl,
        draftUrl: root.dataset.draftUrl,
        classicUrl: root.dataset.classicUrl,
    }).mount(root);
}
