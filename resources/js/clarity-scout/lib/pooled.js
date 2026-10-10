// Run async `fn` over `items` with at most `limit` calls in flight, so a cold
// load's range prefetch fans out a couple at a time instead of firing one long
// serial chain (F2). Per-item failures are swallowed — prefetch is best-effort
// and one bad range must not sink the rest.
export async function pooled(items, fn, limit = 2) {
    const queue = [...items];
    const workers = Array.from({ length: Math.min(limit, queue.length) }, async () => {
        while (queue.length) {
            try {
                await fn(queue.shift());
            } catch { /* best-effort prefetch */ }
        }
    });
    await Promise.all(workers);
}
