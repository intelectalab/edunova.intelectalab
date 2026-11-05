import type { Config } from 'tailwindcss';

/*
 * EDUN-220 · Tokens del diseño Stitch para el sitio y el Panel CMS: única fuente de colores,
 * tipografía y espaciado. Ningún color o tamaño se escribe fuera de aquí.
 *
 * El kit menciona diseno/stitch/SIT/tailwind.config.js, pero ese archivo no vino; los valores
 * se copiaron tal cual del <script id="tailwind-config"> de prototipo/sitio-web.html (Tailwind 3.4.17).
 *
 * «accion» es el token único del verde de los botones (D-SIT-12, abierta). Su valor vive en
 * la variable --accion de globals.css: mientras no se decida, es el esmeralda de la landing.
 */
const config: Config = {
  content: ['./src/**/*.{ts,tsx}'],
  darkMode: 'class',
  theme: {
    // Los colores reemplazan la paleta de Tailwind (no se extienden): así un color fuera de
    // los tokens ni siquiera existe como clase.
    colors: {
      transparent: 'transparent',
      current: 'currentColor',
      white: '#ffffff',
      black: '#000000',
      // Avisos ámbar del prototipo (amber-50 / 200 / 700 / 900 de Tailwind).
      aviso: {
        fondo: '#fffbeb',
        borde: '#fde68a',
        DEFAULT: '#b45309',
        texto: '#78350f',
      },
      accion: {
        DEFAULT: 'var(--accion)',
        hover: 'var(--accion-hover)',
        texto: 'var(--accion-texto)',
      },
      'on-primary-fixed': '#001c37',
      'tertiary-fixed-dim': '#72daa9',
      'on-error': '#ffffff',
      'on-primary': '#ffffff',
      'surface-variant': '#d3e4fe',
      'surface-container-highest': '#d3e4fe',
      'error-container': '#ffdad6',
      'on-secondary': '#ffffff',
      'on-background': '#0b1c30',
      'primary-fixed-dim': '#a8c9f6',
      'inverse-surface': '#213145',
      'surface-dim': '#cbdbf5',
      'inverse-on-surface': '#eaf1ff',
      'on-primary-container': '#98b8e4',
      'outline-variant': '#c3c6cf',
      'tertiary-container': '#005236',
      'on-surface-variant': '#43474e',
      'surface-container-high': '#dce9ff',
      'on-tertiary-fixed': '#002113',
      'secondary-container': '#cce2fe',
      'on-primary-fixed-variant': '#26486e',
      'inverse-primary': '#a8c9f6',
      'on-secondary-container': '#4f647d',
      tertiary: '#003924',
      outline: '#73777f',
      'surface-tint': '#406088',
      'on-tertiary': '#ffffff',
      'secondary-fixed': '#d0e4ff',
      background: '#f8f9ff',
      'surface-container-lowest': '#ffffff',
      'on-surface': '#0b1c30',
      'surface-container': '#e5eeff',
      secondary: '#4b6078',
      'surface-container-low': '#eff4ff',
      'on-tertiary-container': '#60c999',
      'on-tertiary-fixed-variant': '#005236',
      'on-secondary-fixed': '#041d32',
      primary: '#0a3257',
      'secondary-fixed-dim': '#b3c8e4',
      'on-error-container': '#93000a',
      'surface-bright': '#f8f9ff',
      surface: '#f8f9ff',
      'primary-fixed': '#d2e4ff',
      'tertiary-fixed': '#8ef7c4',
      'on-secondary-fixed-variant': '#33485f',
      error: '#ba1a1a',
      'primary-container': '#27496f',
    },
    extend: {
      borderRadius: {
        DEFAULT: '0.25rem',
        lg: '0.5rem',
        xl: '0.75rem',
        full: '9999px',
      },
      spacing: {
        gutter: '1.5rem',
        'space-lg': '1.5rem',
        'space-md': '1rem',
        'space-xs': '0.25rem',
        margin: '2rem',
        'space-xl': '2rem',
        'margin-mobile': '1rem',
        'space-2xl': '3rem',
        'gutter-mobile': '1rem',
        'space-sm': '0.5rem',
        // Menú lateral fijo del Panel CMS (TRASPASO §4) y filas de tabla de 52 px.
        'menu-cms': '18rem',
        'fila-tabla': '3.25rem',
      },
      fontFamily: {
        'title-md': ['var(--fuente-texto)'],
        'label-lg': ['var(--fuente-texto)'],
        'body-sm': ['var(--fuente-texto)'],
        'body-lg': ['var(--fuente-texto)'],
        'label-md': ['var(--fuente-texto)'],
        'headline-sm': ['var(--fuente-titulos)'],
        'label-sm': ['var(--fuente-texto)'],
        'title-lg': ['var(--fuente-titulos)'],
        'headline-md': ['var(--fuente-titulos)'],
        'display-lg': ['var(--fuente-titulos)'],
        'headline-lg': ['var(--fuente-titulos)'],
        'headline-lg-mobile': ['var(--fuente-titulos)'],
        'body-md': ['var(--fuente-texto)'],
      },
      fontSize: {
        'title-md': ['1rem', { lineHeight: '1.5rem', fontWeight: '600' }],
        'label-lg': ['0.875rem', { lineHeight: '1.25rem', letterSpacing: '0.01em', fontWeight: '600' }],
        'body-sm': ['0.8125rem', { lineHeight: '1.125rem', fontWeight: '400' }],
        'body-lg': ['1.125rem', { lineHeight: '1.75rem', fontWeight: '400' }],
        'label-md': ['0.75rem', { lineHeight: '1rem', letterSpacing: '0.02em', fontWeight: '600' }],
        'headline-sm': ['1.25rem', { lineHeight: '1.75rem', fontWeight: '500' }],
        'label-sm': ['0.6875rem', { lineHeight: '0.875rem', letterSpacing: '0.03em', fontWeight: '600' }],
        'title-lg': ['1.125rem', { lineHeight: '1.5rem', fontWeight: '500' }],
        'headline-md': ['1.5rem', { lineHeight: '2rem', fontWeight: '600' }],
        'display-lg': ['3.5rem', { lineHeight: '4rem', letterSpacing: '-0.02em', fontWeight: '700' }],
        'headline-lg': ['2rem', { lineHeight: '2.5rem', letterSpacing: '-0.01em', fontWeight: '600' }],
        'headline-lg-mobile': ['1.625rem', { lineHeight: '2.125rem', letterSpacing: '-0.01em', fontWeight: '600' }],
        'body-md': ['0.9375rem', { lineHeight: '1.375rem', fontWeight: '400' }],
        // Íconos Material Symbols (el prototipo usa 16, 18 y 20 px).
        'icono-sm': ['1rem', { lineHeight: '1' }],
        'icono-md': ['1.125rem', { lineHeight: '1' }],
        'icono-lg': ['1.25rem', { lineHeight: '1' }],
      },
    },
  },
  plugins: [],
};

export default config;
