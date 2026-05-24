/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      // Mirror the CSS variables in globals.css so Tailwind utilities can use them.
      // The existing utility classes from globals.css remain the primary styling
      // surface — Tailwind here is for ad-hoc layout work only.
      colors: {
        bg: 'var(--bg)',
        surface: 'var(--surface)',
        'surface-subtle': 'var(--surface-subtle)',
        border: 'var(--border)',
        'border-strong': 'var(--border-strong)',
        text: 'var(--text)',
        'text-secondary': 'var(--text-secondary)',
        'text-muted': 'var(--text-muted)',
        accent: 'var(--accent)',
        'accent-hover': 'var(--accent-hover)',
        'accent-fg': 'var(--accent-fg)',
        warning: 'var(--warning)',
        'warning-bg': 'var(--warning-bg)',
        'warning-border': 'var(--warning-border)',
        success: 'var(--success)',
        danger: 'var(--danger)',
      },
      borderRadius: {
        DEFAULT: 'var(--radius)',
        sm: 'var(--radius-sm)',
        lg: 'var(--radius-lg)',
      },
      fontFamily: {
        sans: 'var(--font)',
        mono: 'var(--font-mono)',
      },
    },
  },
  plugins: [],
  // Disable Tailwind's preflight reset — globals.css already handles the base styles
  // and we want pixel-for-pixel parity with the HTML mockups.
  corePlugins: {
    preflight: false,
  },
};
