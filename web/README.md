# Helm — web (React)

The Helm frontend. Vite + React 18 + TypeScript + Tailwind CSS 3.

The HTML mockups in `../design-reference/` are the visual source of truth that this app was ported from. After porting, the React app at `web/` is the source of truth; mockups stay as a read-only reference.

## Run

```bash
npm install
npm run dev      # http://localhost:5173
```

Build:

```bash
npm run build    # tsc --noEmit && vite build → dist/
npm run preview  # serve dist/ for a smoke-test
```

Type-check only:

```bash
npm run typecheck
# or against just the app code (excludes vite.config.ts):
npx tsc --noEmit -p tsconfig.app.json
```

## Backend wiring

In development, Vite proxies `/api/*` to `http://localhost:8000` (Laravel). Until the API exists, every page reads from `src/lib/mockApi.ts`, which returns hard-coded data shaped like the eventual `/api/*` responses (see `src/types/domain.ts`).

When the Laravel API is live, replace each `mockApi.X()` call inside `src/hooks/useDashboardData.ts` and `src/hooks/useAuth.ts` with the corresponding `api.get('/...')` call from `src/lib/api.ts`. No component changes needed — same shapes.

## Folder structure (matches spec §10)

```
src/
├── main.tsx                 entry, mounts <App>
├── App.tsx                  router, all routes registered here
│
├── routes/                  one component per URL
│   ├── auth/                LoginPage, AcceptInvitePage, ForgotPasswordPage, ResetPasswordPage, MfaSetupPage, MfaVerifyPage
│   ├── LandingPage.tsx      marketing
│   ├── DashboardPage.tsx    Phase 1 main view
│   ├── SyncHealthPage.tsx
│   ├── BrandsPage.tsx
│   ├── BrandDetailPage.tsx       4 tabs: overview / connections / sync log / settings
│   ├── BrandAdsPage.tsx          Phase 2
│   ├── BrandProductsPage.tsx     Phase 2
│   ├── BrandAuditPage.tsx        Phase 2
│   ├── AddBrandStep1Page.tsx     3-step wizard
│   ├── AddBrandStep2Page.tsx
│   ├── AddBrandStep3Page.tsx
│   ├── SettingsPage.tsx          6 tabs incl. #mfa anchor
│   ├── ProfilePage.tsx
│   ├── TeamPage.tsx              Phase 1.5
│   ├── InviteUserPage.tsx        Phase 1.5
│   ├── UserDetailPage.tsx        Phase 1.5
│   ├── AuditLogPage.tsx          Phase 1.5
│   ├── TicketsPage.tsx           Phase 3
│   ├── TicketNewPage.tsx         Phase 3
│   ├── TicketDetailPage.tsx      Phase 3
│   ├── NotFoundPage.tsx          catch-all
│   └── SitemapPage.tsx           navigation aid for design review
│
├── components/
│   ├── ui/                  primitives: Button, Input, Card, Modal, Popover, Tabs, Chip, Segmented, Dropdown, Tag, Dot, Avatar, EmptyState, Stepper, PageHeader, Breadcrumb, Banner, Wordmark
│   ├── shell/               AppLayout, AuthLayout, Sidebar, Topbar, SearchPalette
│   └── dashboard/           BrandsTable, MetricCell
│
├── hooks/                   TanStack Query wrappers around mockApi
├── stores/                  Zustand: filtersStore (persisted), uiStore (transient)
├── lib/                     api (axios), queryClient, formatters, dates, platforms, cn, mockApi
├── types/                   domain types — single source for API shapes
└── styles/globals.css       design tokens + every CSS class the components use
```

## Design fidelity

Components use the existing CSS class names from `globals.css` (`.btn-primary`, `.card`, `.data-table`, etc.) instead of Tailwind utilities. Tailwind is available for ad-hoc layout work but the visual system stays in `globals.css`. This keeps the rendered output pixel-identical to the HTML mockups.

Tailwind's `corePlugins.preflight` is **disabled** in `tailwind.config.js` so the existing base styles are not reset.

## Stack lock (per spec §3)

Do not introduce a library that isn't already in `package.json` without a written change-request. The list is intentionally minimal.
