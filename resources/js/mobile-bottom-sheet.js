export function mobileBottomSheet() {
    return {
        state: 'closed',
        offset: 0,
        drag: null,
        timer: null,
        frame: null,

        open() {
            if (this.$el.open) return;
            this.offset = 0;
            this.state = 'opening';
            this.$el.showModal();
            this.$dispatch('tris-sheet-state', { open: true });
            // Establish the off-screen initial style before transitioning.
            this.$nextTick(() => {
                this.$el.getBoundingClientRect();
                this.frame = requestAnimationFrame(() => {
                    if (this.state === 'opening') this.state = 'open';
                });
            });
        },
        close() {
            if (!this.$el.open || this.state === 'closing') return;
            this.drag = null;
            this.state = 'closing';
            cancelAnimationFrame(this.frame);
            this.timer = setTimeout(() => this.$el.close(),
                window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 280);
        },
        reset() {
            clearTimeout(this.timer);
            cancelAnimationFrame(this.frame);
            this.state = 'closed';
            this.offset = 0;
            this.drag = null;
            this.$dispatch('tris-sheet-state', { open: false });
        },
        startDrag(event) {
            if (this.state !== 'open' || !event.isPrimary || event.button !== 0) return;
            this.drag = { id: event.pointerId, y: event.clientY, time: performance.now() };
            this.state = 'dragging';
            event.currentTarget.setPointerCapture(event.pointerId);
        },
        moveDrag(event) {
            if (this.drag?.id !== event.pointerId) return;
            this.offset = Math.max(0, event.clientY - this.drag.y);
        },
        endDrag(event) {
            if (this.drag?.id !== event.pointerId) return;
            const distance = this.offset;
            const velocity = distance / Math.max(1, performance.now() - this.drag.time);
            this.drag = null;
            if (distance > 100 || (distance > 35 && velocity > 0.6)) {
                this.close();
            } else {
                this.state = 'open';
                this.offset = 0;
            }
        },
        cancelDrag() {
            if (!this.drag) return;
            this.drag = null;
            this.state = 'open';
            this.offset = 0;
        },
        destroy() {
            clearTimeout(this.timer);
            cancelAnimationFrame(this.frame);
        },
    };
}
