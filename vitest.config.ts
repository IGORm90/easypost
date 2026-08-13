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
        },
    }),
);
