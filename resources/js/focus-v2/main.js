import { createApp } from 'vue';
import FocusV2App from './FocusV2App.vue';

const root = document.getElementById('focus-v2-app');
if (root) {
    createApp(FocusV2App, {
        bootstrapUrl: root.dataset.bootstrapUrl,
        draftUrl: root.dataset.draftUrl,
        resetUrl: root.dataset.resetUrl,
        submitUrl: root.dataset.submitUrl,
        reviewStatusUrl: root.dataset.reviewStatusUrl || '',
        mediaStartUrl: root.dataset.mediaStartUrl,
        classicUrl: root.dataset.classicUrl,
    }).mount(root);
}
