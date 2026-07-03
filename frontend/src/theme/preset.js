import { definePreset } from '@primevue/themes'
import Aura from '@primevue/themes/aura'

// PLAZA PRO brand preset — gold identity on warm neutrals. Light = white cards
// on warm paper; dark = gold on warm near-black (keeps the historic gold/black
// night theme). Dark mode is driven by the same `[data-theme="dark"]` attribute
// useTheme() has always toggled.
const gold = {
  50: '#fbf8ef',
  100: '#f6efd8',
  200: '#eddcae',
  300: '#e2c67d',
  400: '#d8b254',
  500: '#c9a227',
  600: '#a9851d',
  700: '#87681a',
  800: '#6f541c',
  900: '#5f471c',
  950: '#37270c',
}

export default definePreset(Aura, {
  primitive: { gold },
  semantic: {
    primary: {
      50: '{gold.50}',
      100: '{gold.100}',
      200: '{gold.200}',
      300: '{gold.300}',
      400: '{gold.400}',
      500: '{gold.500}',
      600: '{gold.600}',
      700: '{gold.700}',
      800: '{gold.800}',
      900: '{gold.900}',
      950: '{gold.950}',
    },
    focusRing: { width: '2px', style: 'solid', color: '{primary.color}', offset: '2px' },
    colorScheme: {
      light: {
        // Gold is light — dark text on gold passes contrast, white text does not.
        primary: {
          color: '{gold.500}',
          contrastColor: '#231a05',
          hoverColor: '{gold.600}',
          activeColor: '{gold.700}',
        },
        highlight: {
          background: '{gold.100}',
          focusBackground: '{gold.200}',
          color: '{gold.800}',
          focusColor: '{gold.900}',
        },
        surface: {
          0: '#ffffff',
          50: '{stone.50}',
          100: '{stone.100}',
          200: '{stone.200}',
          300: '{stone.300}',
          400: '{stone.400}',
          500: '{stone.500}',
          600: '{stone.600}',
          700: '{stone.700}',
          800: '{stone.800}',
          900: '{stone.900}',
          950: '{stone.950}',
        },
      },
      dark: {
        primary: {
          color: '{gold.400}',
          contrastColor: '{gold.950}',
          hoverColor: '{gold.300}',
          activeColor: '{gold.200}',
        },
        highlight: {
          background: 'color-mix(in srgb, {gold.400}, transparent 84%)',
          focusBackground: 'color-mix(in srgb, {gold.400}, transparent 76%)',
          color: 'rgba(255,255,255,.87)',
          focusColor: 'rgba(255,255,255,.87)',
        },
        // Warm near-black ramp (stone-tinted) instead of Aura's cool zinc.
        surface: {
          0: '#ffffff',
          50: '#f7f6f4',
          100: '#e8e7e3',
          200: '#d1cfc9',
          300: '#aaa8a0',
          400: '#7b7970',
          500: '#57554d',
          600: '#413f38',
          700: '#31302a',
          800: '#211f1b',
          900: '#161511',
          950: '#0d0c0a',
        },
      },
    },
  },
})
