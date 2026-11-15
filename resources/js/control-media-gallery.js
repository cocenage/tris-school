export function controlMediaGallery() {
    return {
        items: [],
        index: -1,
        failed: false,
        swipe: null,
        trigger: null,

        get current() {
            return this.items[this.index] ?? null;
        },

        open(trigger) {
            const buttons = [...this.$el.querySelectorAll('[data-control-media]')];
            this.items = buttons.map(button => ({ ...button.dataset }));
            this.index = buttons.indexOf(trigger);
            this.trigger = trigger;
            this.failed = false;
            this.$refs.viewer.showModal();
            this.$nextTick(() => this.$refs.viewer.querySelector('[autofocus]')?.focus());
        },

        close() {
            this.$refs.viewer.close();
        },

        reset() {
            this.index = -1;
            this.items = [];
            this.failed = false;
            this.cancelSwipe();
            this.trigger?.focus();
            this.trigger = null;
        },

        step(direction) {
            const next = this.index + direction;
            if (next < 0 || next >= this.items.length) return;

            this.failed = false;
            this.index = next;
        },

        handleKey(event) {
            if (event.target.tagName === 'VIDEO') return;
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;

            event.preventDefault();
            this.step(event.key === 'ArrowLeft' ? -1 : 1);
        },

        beginSwipe(event) {
            if (event.pointerType !== 'touch' || !event.isPrimary || this.current?.kind === 'video') return;

            this.swipe = { id: event.pointerId, x: event.clientX, y: event.clientY };
        },

        endSwipe(event) {
            const start = this.swipe;
            this.cancelSwipe();
            if (!start || event.pointerId !== start.id) return;

            const horizontal = event.clientX - start.x;
            const vertical = event.clientY - start.y;
            if (Math.abs(horizontal) >= 48 && Math.abs(horizontal) > Math.abs(vertical) * 1.5) {
                this.step(horizontal < 0 ? 1 : -1);
            }
        },

        cancelSwipe() {
            this.swipe = null;
        },
    };
}
