# 07 — Frontend

React 18 SPA. Vite 5 build. TypeScript strict. Tailwind 3 with shadcn/ui patterns (copy-paste, not a package).

## Contents

- [Folder structure](#folder-structure)
- [Routing](#routing)
- [State](#state)
- [Design tokens](./design-tokens.md) — colors, type, spacing, the locked aesthetic

## Folder structure

```
src/
├── main.tsx                               # app entry, QueryClient setup, router
├── App.tsx                                # top-level routes
│
├── routes/
│   ├── (auth)/
│   │   ├── LoginPage.tsx
│   │   └── AcceptInvitePage.tsx           # Phase 1.5
│   ├── (app)/
│   │   ├── AppLayout.tsx                  # sidebar + topbar shell
│   │   ├── DashboardPage.tsx              # the main grid (Phase 1)
│   │   ├── brand/
│   │   │   ├── BrandDetailPage.tsx        # drill-in trends, sync log
│   │   │   ├── BrandAdsPage.tsx           # Phase 2 ad performance
│   │   │   └── BrandAuditPage.tsx         # Phase 2 audit cards
│   │   ├── ConnectionsPage.tsx            # per-brand platform connection cards
│   │   ├── OnboardingWizard.tsx           # add-brand 3-step flow
│   │   ├── tickets/                       # Phase 3
│   │   │   ├── TicketsListPage.tsx
│   │   │   └── TicketDetailPage.tsx
│   │   ├── team/                          # Phase 1.5
│   │   │   ├── TeamPage.tsx
│   │   │   └── InviteUserPage.tsx
│   │   └── settings/SettingsPage.tsx
│   └── NotFoundPage.tsx
│
├── components/
│   ├── ui/                                # shadcn-style primitives, copy-pasted
│   ├── dashboard/
│   │   ├── BrandsTable.tsx                # TanStack Table, virtualized
│   │   ├── MetricCell.tsx                 # value + delta% + arrow
│   │   ├── DateRangeFilter.tsx
│   │   ├── CurrencyToggle.tsx
│   │   ├── ReturnsToggle.tsx
│   │   ├── SyncHealthBadge.tsx
│   │   └── BrandRowExpansion.tsx
│   ├── charts/
│   │   ├── RevenueTrendChart.tsx
│   │   └── SpendVsRevenueChart.tsx
│   ├── connection/
│   │   ├── PlatformCard.tsx               # Connect / Connected state
│   │   └── AccountPickerDialog.tsx        # Meta/Google/TikTok dropdown
│   └── shell/
│       ├── Sidebar.tsx
│       ├── Topbar.tsx
│       └── UserMenu.tsx
│
├── lib/
│   ├── api.ts                             # axios instance, auth interceptor
│   ├── queryClient.ts                     # TanStack Query config
│   ├── auth.ts                            # login/logout, session storage
│   ├── permissions.ts                     # role and brand-access helpers
│   ├── formatters.ts                      # currency, %, delta arrows
│   ├── dates.ts                           # tz-aware date range presets
│   └── platforms.ts                       # platform list, icons, labels
│
├── hooks/
│   ├── useDashboardData.ts
│   ├── useBrand.ts
│   ├── useConnections.ts
│   └── useAuth.ts
│
├── stores/
│   ├── filtersStore.ts                    # Zustand: date range, currency, returns
│   └── uiStore.ts                         # Zustand: sidebar open, modals
│
├── types/
│   ├── api.ts                             # response types matching API resources
│   ├── domain.ts                          # Brand, DailyMetric, Connection
│   └── index.ts
│
└── styles/
    └── globals.css                        # Tailwind directives, CSS vars

public/
└── platform-logos/                        # shopify, meta, google, tiktok SVGs

vite.config.ts
tailwind.config.js
tsconfig.json
package.json
```

## Routing

`react-router-dom@6` with nested routes. Auth-required routes wrap in `<RequireAuth>`. Brand-scoped routes wrap in `<RequireBrandAccess>` which calls `permissions.accessBrand(user, brandId)` before rendering.

## State

| Concern | Tool |
|---------|------|
| Server data | TanStack Query. Stale-while-revalidate, refetchOnWindowFocus disabled, retry once. |
| Filter UI state | Zustand (`filtersStore`). Persisted to `localStorage` so reloads keep the user's last view. |
| Modal / drawer / sidebar UI state | Zustand (`uiStore`). Not persisted. |
| Form state | React Hook Form + Zod resolver. Never `useState` for fields. |

## Design language

Locked. See [design-tokens.md](./design-tokens.md) for the actual values.

- Linear / Stripe / Vercel restraint.
- No gradients, no shadows, no glassmorphism.
- Single accent color (currently near-black). Warm neutrals.
- Generous whitespace. Sentence case **everywhere** — labels, buttons, headings, errors.
- Tabular numerals on every metric cell.
