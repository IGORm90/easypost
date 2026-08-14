import { defineConfig, mergeConfig } from 'vitest/config';
import viteConfig from './vite.config.ts';

export default mergeConfig(
    viteConfig,
    defineConfig({
        test: {
            environment: 'happy-dom',
            globals: true,
            include: ['resources/js/**/*.spec.ts'],
            setupFiles: ['resources/js/test-setup.ts'],
            // Pinned to a non-zero, non-UTC offset that is not any of
            // the zones tests assert against (Europe/Moscow,
            // America/New_York, Australia/Sydney, UTC) — Task 25 review,
            // Finding 3 (round 2): a wall-clock assertion that only
            // fails when the runner's OWN timezone happens to differ
            // from the zone under test passes for the wrong reason on a
            // machine/CI image configured to that same zone. Pinning
            // `TZ` makes every such assertion mean the same thing on
            // every machine, for every future time-touching test, not
            // just this task's.
            env: { TZ: 'America/Chicago' },
        },
    }),
);
