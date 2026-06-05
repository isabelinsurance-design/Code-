/** @type {import('tailwindcss').Config} */
// Paleta lino cálido — match con todoisabel.html / dashboard.js
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        lino: {
          50: '#FDFCF9',
          100: '#FAF7F2',
          200: '#F2EDE3',
          300: '#E8DECF',
          400: '#D4C3A4',
          500: '#B89B6E',
          600: '#8B6F47',
          700: '#6B5436',
          800: '#4A3A26',
        },
        ink: {
          1: '#1F1A12',
          2: '#3A322A',
          3: '#6B5F50',
        },
        amber: '#C77E2A',
        red: '#B33A2E',
      },
      fontFamily: {
        sans: ['"DM Sans"', 'system-ui', '-apple-system', 'Inter', 'sans-serif'],
        serif: ['Fraunces', 'Georgia', 'Cambria', 'serif'],
        mono: ['"DM Mono"', 'ui-monospace', 'monospace'],
      },
    },
  },
  plugins: [],
};
