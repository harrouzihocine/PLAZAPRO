/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts}'],
  theme: {
    extend: {
      colors: {
        bg: 'var(--color-bg)',
        surface: 'var(--color-surface)',
        primary: 'var(--color-primary)',
        ink: 'var(--color-text)',
        'on-primary': 'var(--color-on-primary)',
        border: 'var(--color-border)',
        danger: 'var(--color-danger)',
      },
      borderRadius: { token: 'var(--radius)' },
    },
  },
  plugins: [],
}
