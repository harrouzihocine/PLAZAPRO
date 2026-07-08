import plugin from 'tailwindcss/plugin'
import primeui from 'tailwindcss-primeui'

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: ['selector', '[data-theme="dark"]'],
  content: ['./index.html', './src/**/*.{vue,js,ts}'],
  theme: {
    extend: {
      colors: {
        // Theme-aware app tokens (flip with [data-theme="dark"] automatically —
        // they resolve to PrimeVue semantic vars or scheme-scoped app vars).
        ground: 'var(--app-ground)', // page background
        card: 'var(--p-content-background)', // card / panel background
        ink: 'var(--p-text-color)', // main text
        mute: 'var(--p-text-muted-color)', // secondary text
        line: 'var(--p-content-border-color)', // hairline borders
        danger: 'var(--app-danger)',
        success: 'var(--app-success)',
        warning: 'var(--app-warning)',
        info: 'var(--app-info)',
        // Legacy aliases kept while older views migrate.
        bg: 'var(--app-ground)',
        border: 'var(--p-content-border-color)',
        'on-primary': 'var(--p-primary-contrast-color)',
        // My own chat bubble (brand navy — see tokens.css).
        'chat-own': 'var(--chat-own-bubble)',
        'chat-own-contrast': 'var(--chat-own-text)',
      },
      borderRadius: { token: 'var(--radius)' },
      fontFamily: {
        sans: [
          'Inter Variable',
          'Inter',
          'system-ui',
          '-apple-system',
          'Segoe UI',
          'Roboto',
          'sans-serif',
        ],
      },
      boxShadow: {
        card: '0 1px 2px rgb(24 20 8 / 0.04), 0 1px 3px rgb(24 20 8 / 0.06)',
        pop: '0 4px 6px -1px rgb(24 20 8 / 0.08), 0 10px 24px -6px rgb(24 20 8 / 0.12)',
      },
    },
  },
  plugins: [
    primeui,
    // `native:` — styles that apply only inside the Capacitor Android shell
    // (html.native, stamped by utils/nativeApp.js). Stacks with breakpoints:
    // `native:max-md:…` targets phones in the app.
    plugin(({ addVariant }) => addVariant('native', '.native &')),
  ],
}
