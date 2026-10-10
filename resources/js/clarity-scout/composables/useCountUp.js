import { ref, watch, onBeforeUnmount } from 'vue';

const prefersReducedMotion = () =>
    typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Tween a displayed integer toward a reactive target with a fixed-duration,
 * ease-out requestAnimationFrame loop. The first non-null target seeds the
 * display without animating, and reduced-motion preferences snap instead of
 * spinning.
 *
 * @param {() => (number | null | undefined)} getTarget
 * @param {{ duration?: number }} options
 * @returns {{ display: import('vue').Ref<number> }}
 */
export function useCountUp(getTarget, { duration = 600 } = {}) {
    const display = ref(0);
    let frame = null;
    let seeded = false;

    function cancel() {
        if (frame !== null) {
            cancelAnimationFrame(frame);
            frame = null;
        }
    }

    function animateTo(target) {
        cancel();

        const from = display.value;
        const delta = target - from;
        if (delta === 0) {
            return;
        }

        if (prefersReducedMotion()) {
            display.value = target;
            return;
        }

        const start = performance.now();
        const step = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            display.value = Math.round(from + delta * eased);
            if (progress < 1) {
                frame = requestAnimationFrame(step);
            } else {
                display.value = target;
                frame = null;
            }
        };
        frame = requestAnimationFrame(step);
    }

    watch(
        getTarget,
        (target) => {
            // A null/undefined target (e.g. a range not yet loaded) isn't "zero" —
            // hold the last value rather than rolling the tile down to 0 and back.
            if (target == null) {
                return;
            }
            if (!seeded) {
                seeded = true;
                display.value = target;
                return;
            }
            animateTo(target);
        },
        { immediate: true }
    );

    onBeforeUnmount(cancel);

    return { display };
}
