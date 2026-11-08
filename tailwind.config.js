/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: ['./resources/**/*.{vue,ts,blade.php}'],
    theme: {
        extend: {
            colors: {
                bg: 'var(--bg)',
                surface: 'var(--surface)',
                'surface-2': 'var(--surface-2)',
                border: 'var(--border)',
                'border-strong': 'var(--border-strong)',
                text: 'var(--text)',
                muted: 'var(--muted)',
                overlay: 'var(--overlay)',
                nav: { DEFAULT: 'var(--nav)', active: 'var(--nav-active)', border: 'var(--nav-border)', text: 'var(--nav-text)', muted: 'var(--nav-muted)' },
                'on-nav': 'var(--on-nav)',
                primary: { DEFAULT: 'var(--primary)', hover: 'var(--primary-hover)', soft: 'var(--primary-soft)' },
                'on-primary': 'var(--on-primary)',
                accent: { DEFAULT: 'var(--accent)', soft: 'var(--accent-soft)' },
                'on-accent': 'var(--on-accent)',
                success: { DEFAULT: 'var(--success)', soft: 'var(--success-soft)', border: 'var(--success-border)' },
                warning: { DEFAULT: 'var(--warning)', soft: 'var(--warning-soft)', border: 'var(--warning-border)' },
                danger: { DEFAULT: 'var(--danger)', soft: 'var(--danger-soft)', border: 'var(--danger-border)' },
                'on-danger': 'var(--on-danger)',
            },
            fontFamily: {
                sans: ['IBM Plex Sans', 'system-ui', 'sans-serif'],
                mono: ['IBM Plex Mono', 'ui-monospace', 'monospace'],
            },
            // color.md §3.5: `rounded-xl` is the Cobalt control radius (6px) —
            // buttons, fields, segments — and `rounded-lg` the 8px card radius.
            borderRadius: { xl: '0.375rem', lg: '0.5rem', '2xl': '0.75rem' },
        },
    },
    plugins: [],
};
