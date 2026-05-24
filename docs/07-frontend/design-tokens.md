# Design tokens

Source of truth for the look. Mirrored in `web/styles.css` (current design preview) and will be ported into `tailwind.config.js` + `src/styles/globals.css` when we scaffold the React app.

## Aesthetic rules

- Linear / Stripe / Vercel restraint.
- **No gradients.** Anywhere.
- **No shadows.** Use a 1px border instead.
- **No glassmorphism, no blur, no frosted-glass.**
- **Single accent color** — currently near-black. Pick one and don't pick a second.
- **Warm neutrals** — stone palette, not cool gray.
- **Sentence case** — buttons, labels, headings, error messages, empty states. All sentence case.
- **Generous whitespace** — content breathes. Default to more padding than feels right, dial back if it's wasteful.

## Color tokens

| Token | Value | Use |
|-------|-------|-----|
| `--bg` | `#FAFAF9` | Page background (stone-50) |
| `--surface` | `#FFFFFF` | Cards, table rows, inputs |
| `--surface-subtle` | `#F5F5F4` | Hover states, secondary backgrounds (stone-100) |
| `--border` | `#E7E5E4` | Default border (stone-200) |
| `--border-strong` | `#D6D3D1` | Input borders, button outlines (stone-300) |
| `--text` | `#0C0A09` | Primary text (stone-950) |
| `--text-secondary` | `#57534E` | Body, secondary labels (stone-600) |
| `--text-muted` | `#A8A29E` | Captions, table column heads (stone-400) |
| `--accent` | `#0C0A09` | Primary buttons, active states (monochrome) |
| `--accent-hover` | `#1C1917` | Hover for primary actions |
| `--accent-fg` | `#FAFAF9` | Text on accent backgrounds |
| `--warning` | `#B45309` | Sync warnings, amber dots |
| `--warning-bg` | `#FEF3C7` | Warning badge background |
| `--success` | `#15803D` | Positive deltas (used sparingly) |
| `--danger` | `#B91C1C` | Negative deltas, destructive actions |

## Typography

- **Font:** Inter (400, 450, 500, 600).
- **Mono:** JetBrains Mono — for raw IDs and code blocks only.
- **Body size:** 14px. Dense data UI.
- **Marketing body:** 15–16px.
- **Tabular nums** on every metric. `font-feature-settings: 'tnum'`.

| Style | Size | Weight | Letter-spacing |
|-------|------|--------|----------------|
| h1 (landing only) | 52px | 600 | -0.03em |
| h2 | 32px | 600 | -0.02em |
| h3 | 20px | 600 | -0.02em |
| Body | 14px | 400 | 0 |
| Small | 13px | 400 | 0 |
| Caption / column head | 12px | 500 | 0.05em uppercase |

## Spacing

8-point grid. Common steps: 4, 8, 12, 16, 20, 24, 32, 48, 64, 96.

## Radii

| Token | Value | Use |
|-------|-------|-----|
| `--radius-sm` | 4px | Tags, dots, brand avatars |
| `--radius` | 6px | Buttons, inputs, chips |
| `--radius-lg` | 8px | Cards, modals, preview frames |

## Component primitives

Defined in `web/styles.css`. When we move to React, these become Tailwind components under `src/components/ui/`.

- `.btn` with variants `.btn-primary`, `.btn-secondary`, `.btn-ghost`, sizes `.btn-sm`, `.btn-lg`.
- `.input` for all text fields. Border-only focus state (no ring shadow).
- `.card` for grouped content. 1px border, 8px radius.
- `.tag` for badges. Variants: default, `.tag-warning`, `.tag-success`.
- `.chip` for filter chips with `.active` state.
- `.data-table` with `.num` class for right-aligned tabular cells.
- `.metric-delta` for the small +/- delta beneath each metric. Variants: `.up`, `.down`, `.flat`.

## What's locked vs. open

**Locked:**
- The aesthetic rules above.
- Warm-neutral palette (stone, not gray or zinc).
- Inter as the type face.
- 1px borders only, never shadows.

**Open for design iteration:**
- The exact accent color. Currently near-black; could move to a single brand hue (refined teal, indigo, slate-blue) without changing any rule above.
- The wordmark mark. Currently a small square dot; could become a custom SVG (anchor, wheel, compass) without changing anything else.
- Density tuning on the dashboard table. Current 13.5px body / 12px padding can tighten or relax based on review.
