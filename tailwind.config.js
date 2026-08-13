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
                text: 'var(--text)',
                muted: 'var(--muted)',
                primary: { DEFAULT: 'var(--primary)', hover: 'var(--primary-hover)', soft: 'var(--primary-soft)' },
                'on-primary': 'var(--on-primary)',
                accent: { DEFAULT: 'var(--accent)', soft: 'var(--accent-soft)' },
                'on-accent': 'var(--on-accent)',
                success: { DEFAULT: 'var(--success)', soft: 'var(--success-soft)' },
                warning: { DEFAULT: 'var(--warning)', soft: 'var(--warning-soft)' },
                danger: { DEFAULT: 'var(--danger)', soft: 'var(--danger-soft)' },
            },
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
            borderRadius: { xl: '0.75rem' },
        },
    },
    plugins: [],
};
