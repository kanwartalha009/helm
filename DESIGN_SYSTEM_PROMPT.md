# Design system port — Helm aesthetic

Paste this entire file as a single prompt into the target project's AI assistant. It is self-contained: design tokens, primitives, form pattern, and layout rules all included. The assistant should treat this as authoritative — no improvisation, no "modern alternatives", no shadows, no gradients.

---

## Brief

I want this project's UI to adopt the same visual system and interaction patterns I'm pasting below. It's an internal-tool aesthetic in the spirit of Linear, Stripe Dashboard, and Vercel — restrained, monochrome, typographic. Implement the tokens and primitives below verbatim, then refactor existing screens to use them.

## Design philosophy (non-negotiable)

- **Single near-black accent.** No teal, no blue, no purple. Buttons, focus rings, charts — all `--accent` (`#0C0A09`) or grayscale.
- **Warm neutrals.** Backgrounds are `stone`-tinted whites and grays, never cool blues. Specifically: `#FAFAF9` page bg, `#FFFFFF` surfaces, `#F5F5F4` subtle surfaces.
- **No shadows. No gradients. No glassmorphism. No blur backdrops.** Borders only. Single-color fills only.
- **Generous whitespace.** Default padding on cards, drawers, page containers is `24px`. Field gaps are `16–24px`. Resist tightening it.
- **Sentence case everywhere.** Buttons: "Add brand", not "Add Brand" or "ADD BRAND". Page titles: "Sync health", not "Sync Health". Section headings same.
- **Tabular numerals for data.** Use `font-variant-numeric: tabular-nums` on any number a user will read — table cells, metrics, dates. Use the `.num` class.
- **Single radius scale**: 4px (`--radius-sm`), 6px (`--radius`), 8px (`--radius-lg`). Nothing rounder. No pill buttons.
- **Borders, not shadows, separate things.** `1px solid var(--border)` everywhere.
- **Transitions are 120ms ease** on hover/focus. No bounces, no springs.

## Tokens — paste this into `globals.css` or equivalent

```css
:root {
  /* Surfaces */
  --bg: #FAFAF9;
  --surface: #FFFFFF;
  --surface-subtle: #F5F5F4;

  /* Borders */
  --border: #E7E5E4;
  --border-strong: #D6D3D1;

  /* Text */
  --text: #0C0A09;
  --text-secondary: #57534E;
  --text-muted: #A8A29E;

  /* Accent (monochrome) */
  --accent: #0C0A09;
  --accent-hover: #1C1917;
  --accent-fg: #FAFAF9;

  /* Status — used sparingly */
  --warning: #B45309;
  --warning-bg: #FEF3C7;
  --warning-border: #FCD34D;
  --success: #15803D;
  --danger: #B91C1C;

  /* Radius */
  --radius-sm: 4px;
  --radius: 6px;
  --radius-lg: 8px;

  /* Typography */
  --font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
}

html, body {
  margin: 0; padding: 0;
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  font-size: 14px;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
  font-feature-settings: 'cv11', 'ss01';
}

h1, h2, h3, h4 { font-weight: 600; letter-spacing: -0.02em; color: var(--text); margin: 0; }
h1 { font-size: 52px; line-height: 1.05; letter-spacing: -0.03em; }
h2 { font-size: 32px; line-height: 1.15; }
h3 { font-size: 20px; line-height: 1.25; }

p { margin: 0; color: var(--text-secondary); }
.lede { font-size: 18px; line-height: 1.55; color: var(--text-secondary); }

.num { font-variant-numeric: tabular-nums; font-feature-settings: 'tnum'; }
.mono { font-family: var(--font-mono); }
.muted { color: var(--text-muted); }

/* Buttons */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 8px 14px; border-radius: var(--radius);
  font-size: 14px; font-weight: 500; font-family: inherit;
  border: 1px solid transparent;
  cursor: pointer; line-height: 1; white-space: nowrap;
  transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
}
.btn-primary   { background: var(--accent);  color: var(--accent-fg); border-color: var(--accent); }
.btn-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
.btn-secondary { background: var(--surface); color: var(--text); border-color: var(--border-strong); }
.btn-secondary:hover { background: var(--surface-subtle); }
.btn-ghost     { background: transparent; color: var(--text); border-color: transparent; }
.btn-ghost:hover { background: var(--surface-subtle); }
.btn-sm { padding: 6px 10px; font-size: 13px; }
.btn-lg { padding: 11px 18px; font-size: 15px; }

/* Form fields */
.field { display: flex; flex-direction: column; gap: 6px; }
.field-label { font-size: 13px; font-weight: 500; color: var(--text); }
.field-hint  { font-size: 12px; color: var(--text-secondary); }
.input {
  width: 100%; padding: 9px 12px;
  border-radius: var(--radius);
  border: 1px solid var(--border-strong);
  background: var(--surface);
  font-family: inherit; font-size: 14px; color: var(--text);
  transition: border-color 120ms ease;
}
.input:focus { outline: none; border-color: var(--accent); }
.input::placeholder { color: var(--text-muted); }

/* Card */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); }

/* Tag */
.tag {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 8px; font-size: 12px; font-weight: 500;
  border-radius: 4px;
  background: var(--surface-subtle); color: var(--text-secondary);
  border: 1px solid var(--border);
}
.tag-warning { background: var(--warning-bg); color: var(--warning); border-color: var(--warning-border); }
.tag-success { background: #DCFCE7; color: var(--success); border-color: #BBF7D0; }

.dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
.dot-warning { background: var(--warning); }
.dot-success { background: var(--success); }
.dot-muted   { background: var(--text-muted); }

/* Layout */
.container        { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
.container-narrow { max-width: 440px;  margin: 0 auto; padding: 0 24px; }
.divider          { height: 1px; background: var(--border); }
```

Add Inter and JetBrains Mono via Google Fonts or local hosting.

## Primitive components (build these in order)

Each is a thin wrapper. Behavior matters more than decoration.

1. **Button** — `variant: 'primary' | 'secondary' | 'ghost'`, `size: 'sm' | 'md' | 'lg'`, `loading` prop swaps content for a spinner and disables the button. No icon library lock-in — accept `leading` and `trailing` ReactNode props.

2. **Input** — Always with a `label` prop above. Optional `hint` below in `--text-secondary`. Optional `trailing` slot in the label row (e.g. "Forgot password?" link). Width is always 100% — let the form-grid handle widths.

3. **Drawer** — Right-anchored slide-in, NOT a modal. Use this for create/edit flows that are denser than a modal but lighter than a full route. Two sizes: `sm` (~480px) and `lg` (50vw). Body scroll locks when open. Close on Esc and backdrop click. Header with title + close-X. Footer slot for actions. Use `inert` attribute on the closed state, not `aria-hidden`.

4. **Modal** — Only for destructive confirmations and one-question prompts. If the form has more than 3 fields, use a Drawer instead.

5. **Banner** — In-page callout for important context. Variants: `info` (border-only), `warning`, `danger`. Icon on the left, text fills.

6. **EmptyState** — Card-bordered, centered, generous vertical padding (64px+). Icon, title, one-sentence description, primary CTA. Show this when a route's primary entity collection is empty — not a spinner, not a "No data" string.

7. **PageHeader** — Title (h1 in small contexts, h2 in deep ones), one-line lede in `--text-secondary`, action slot on the right. Stays at the top of every route.

8. **Tabs / Segmented** — Tabs for switching panels in a page. Segmented for binary/ternary toggles (Compact / Wide, Gross / Net). Both are border-bottom-driven, no rounded pills.

9. **Dropdown / Popover** — Click-triggered, border + small radius, no shadow. Anchored with a tiny offset.

10. **Toaster** — Single accent for success, `--danger` for errors. Bottom-right. Auto-dismiss 4s for success, manual-only for errors.

11. **Stepper** — For wizards. Numbered circles connected by a line. Current step is `--accent`, completed is filled with checkmark.

12. **Avatar** — Initials in a square (4px radius), `--surface-subtle` background, `--text` text. Resist circular avatars — they break the rectangular grid.

## Form pattern (this is the most important part)

Every form on every screen uses the same skeleton. Don't invent variants.

```tsx
<form
  className="form-grid"
  onSubmit={(e) => { e.preventDefault(); handleSubmit(); }}
>
  <Input
    label="Brand name"
    name="name"
    value={name}
    onChange={(e) => setName(e.target.value)}
    hint="Shows in the dashboard sidebar."
  />
  <Input
    label="Domain"
    name="domain"
    value={domain}
    onChange={(e) => setDomain(e.target.value)}
    placeholder="acme.com"
  />
  {/* … */}

  <div className="form-actions">
    <Button variant="ghost" onClick={onCancel}>Cancel</Button>
    <Button variant="primary" type="submit" loading={isSaving}>
      Save changes
    </Button>
  </div>
</form>
```

With this CSS:

```css
.form-grid    { display: flex; flex-direction: column; gap: 16px; }
.form-row     { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
```

Rules:

- **Labels are always above the input**, never inline-left. Faster to scan, easier to localize.
- **Hint text under the input**, in `--text-secondary`. One sentence max.
- **Error text replaces the hint** when the field is invalid. Use `--danger`. Don't add red borders unless the field has been touched and submit was attempted — error styling shouldn't appear while the user is mid-typing.
- **Primary action on the right.** Cancel/secondary on the left. Always in this order, no exceptions, this matches the user's reading flow and where their mouse already is from filling fields.
- **One primary action per form.** If you feel you need two, you have two forms.
- **Loading state replaces button content with a spinner** — don't add a separate "saving…" text node. The button width must stay stable: reserve min-width on primary CTAs.
- **Submit on Enter.** Plain `<form onSubmit>` handles this — don't rebind to a button click. If you have a textarea, accept Cmd/Ctrl+Enter as an additional submit.
- **Disable the submit button while saving**, not while the form is "invalid". Inline validation should warn, not block.

## Layout structure

- **App shell** — fixed left sidebar (224px), main column fills the rest. No top bar except for search. Sidebar has two sections (e.g. "Operate" and "Manage") in `--text-muted` uppercased small-caps headings, each with link items below. Active link is `--accent` text on `--surface-subtle` background, no left bar accent (that's a Linear thing — Helm uses surface fill instead).
- **Page padding** — 32px top, 24px sides. Never less.
- **Section spacing** — 32px between major sections in a page. 16px between header and first content.
- **Tables** — flat. Single `1px solid var(--border)` around the whole table-card. Header row in `--text-secondary` uppercase 11px tracking-wider. Row hover: `--surface-subtle`. No alternating zebra stripes.

## Drawer pattern (do not skip)

Use Drawer instead of Modal whenever:
- The form has more than 3 fields
- The flow has wizard-like steps (use the Stepper component inside)
- The user needs to reference what they navigated from while filling it out

Width: `sm` for ≤4 fields and confirmations. `lg` (50vw) for multi-step wizards. Don't introduce a `md`.

Drawer header has the title only — no description, no breadcrumb. If the user needs more context, the body's first element is a `Banner` with that context.

Drawer footer is sticky-bottom and contains exactly two buttons: secondary (Cancel) on the left, primary on the right. If you need a third action (e.g. "Test connection"), put it inline within the body next to the field it relates to, not in the footer.

## What I do NOT want from the AI

- Don't introduce a color palette beyond the tokens above. No "I think a soft teal would warm this up."
- Don't reach for shadcn/ui or Material or anything else. We're building thin wrappers around HTML elements + the CSS above. Headless behavior libraries (Radix, Floating UI for popovers, Tanstack Table for tables) are fine because they don't ship visuals.
- Don't add icons reflexively. Icons go inside buttons only when they help disambiguate (Filter, Sort, Export). Lucide is the icon library; 16px stroke 1.5.
- Don't auto-capitalize button labels.
- Don't put `border-radius: 9999px` on anything.
- Don't add hover lift effects or scale transforms.
- Don't write CSS-in-JS — use the class system above. Inline `style` is acceptable only for one-off layout (a flex gap, a width) and never for color/border/font.

## Acceptance — when this port is done

I should be able to point at any screen and see:
- Single accent color, warm neutrals, no shadows
- Sentence case throughout
- Tables that read like a Linear board
- Forms that all look like siblings of each other
- Drawers for creation flows, modals only for destructive confirmations
- Empty states wherever data would otherwise be `null`

Refactor existing screens to match. Start with the most-used three, push back with a list of any screens that genuinely don't fit (and propose why), don't silently change scope.

---

If the assistant asks clarifying questions, the answers are:
- **"Should I keep the existing dark mode?"** — Build for light first, dark later. Don't fork the tokens yet.
- **"Should I use shadcn/ui as a starting point?"** — No, see "What I do NOT want" above.
- **"How should I handle the existing color palette?"** — Map it onto the new tokens above. Anything outside `--accent`, neutrals, and the four status colors should be deleted.
