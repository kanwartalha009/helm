import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { toast } from '@/stores/toastStore';
import type { Brand } from '@/types/domain';

export interface CreateBrandInput {
  name: string;
  slug?: string;
  timezone: string;
  base_currency: string;
  group_tag?: string | null;
}

/**
 * POST /api/brands — create a brand row. Returns the new Brand so the caller
 * can navigate to /brands/{slug} or open the next step of the wizard.
 */
export function useCreateBrand() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: CreateBrandInput) => {
      const { data } = await api.post<Brand>('/brands', input);
      return data;
    },
    onSuccess: (brand) => {
      qc.invalidateQueries({ queryKey: ['brands'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Brand created', `${brand.name} is ready. Now connect Shopify to start syncing.`);
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors;
      const first = errors ? Object.values(errors)[0] : null;
      const msg = Array.isArray(first) ? first[0] : (err?.response?.data?.message ?? err.message);
      toast.error("Couldn't create brand", msg);
    },
  });
}

export interface ShopifyInstallUrl {
  url: string;
}

export interface ShopifyTokenInput {
  shopDomain: string;
  accessToken: string;
  apiKey?: string;
  apiSecret?: string;
}

export interface ShopifyTokenResponse {
  connection: {
    id: number;
    brandId: number;
    platform: string;
    externalId: string;
    displayName: string | null;
    status: string;
    lastError: string | null;
  };
  shop: {
    name: string | null;
    domain: string;
    currency: string | null;
    timezone: string | null;
  } | null;
  validation: {
    ok: boolean;
    error: string | null;
  };
}

/**
 * POST /api/brands/{slug}/connections/shopify/token
 *
 * Manual Shopify connect — the intern pastes the Admin API access token
 * generated from the store's Develop apps page. Server validates the token
 * against Shopify before persisting; on success returns the resolved shop
 * info so the UI can confirm the right store was connected.
 */
export function useConnectShopifyToken() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: {
      brandSlug: string;
      shopDomain: string;
      accessToken: string;
      apiKey?: string;
      apiSecret?: string;
    }): Promise<ShopifyTokenResponse> => {
      const { data } = await api.post<ShopifyTokenResponse>(
        `/brands/${input.brandSlug}/connections/shopify/token`,
        {
          shop_domain: input.shopDomain,
          access_token: input.accessToken,
          api_key: input.apiKey || undefined,
          api_secret: input.apiSecret || undefined,
        }
      );
      return data;
    },
    onSuccess: (data, vars) => {
      qc.invalidateQueries({ queryKey: ['brand', vars.brandSlug] });
      qc.invalidateQueries({ queryKey: ['brand', vars.brandSlug, 'metrics'] });
      qc.invalidateQueries({ queryKey: ['brands'] });   // BrandsPage list view
      qc.invalidateQueries({ queryKey: ['dashboard'] });

      if (data.validation.ok && data.shop) {
        toast.success(
          'Shopify connected',
          `${data.shop.name ?? data.shop.domain} · ${data.shop.currency} · ${data.shop.timezone}. Run a sync to pull historical orders.`
        );
      } else {
        // Credentials persisted, but Shopify rejected them. Tell the user
        // exactly what Shopify said so they can fix the token. Surfaced as
        // an error variant so the toast sticks for 7s (info disappears too fast).
        toast.error(
          'Saved — but Shopify rejected the token',
          data.validation.error ?? 'Unknown error from Shopify. Check the token and try Sync now.'
        );
      }
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors;
      const first = errors ? Object.values(errors)[0] : null;
      const msg = Array.isArray(first) ? first[0] : (err?.response?.data?.message ?? err.message);
      toast.error("Couldn't save Shopify token", msg);
    },
  });
}

export interface UpdateBrandInput {
  name?: string;
  timezone?: string;
  base_currency?: string;
  group_tag?: string | null;
  status?: 'active' | 'paused' | 'archived';
}

/**
 * DELETE /api/brands/{slug} — hard-delete. Cascades to platform_connections,
 * daily_metrics, and sync_logs via FK constraints. Server writes an audit
 * row first so we keep the "this brand existed" trail even after the rows
 * are gone.
 */
export function useDeleteBrand() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (slug: string) => {
      await api.delete(`/brands/${slug}`);
    },
    onSuccess: (_d, slug) => {
      // Drop the per-brand caches and refetch the brand list + dashboard.
      qc.removeQueries({ queryKey: ['brand', slug] });
      qc.invalidateQueries({ queryKey: ['brands'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Brand deleted', 'Brand and all its data have been removed.');
    },
    onError: (err: any) => {
      toast.error("Couldn't delete brand", err?.response?.data?.message ?? err.message);
    },
  });
}

/**
 * DELETE /api/connections/{id} — removes a platform connection. For Shopify,
 * the next install on the same shop goes through a fresh OAuth handshake.
 */
export function useDisconnectConnection() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { connectionId: number; brandSlug: string }) => {
      await api.delete(`/connections/${input.connectionId}`);
    },
    onSuccess: (_d, vars) => {
      qc.invalidateQueries({ queryKey: ['brand', vars.brandSlug] });
      qc.invalidateQueries({ queryKey: ['brand', vars.brandSlug, 'metrics'] });
      qc.invalidateQueries({ queryKey: ['brands'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Disconnected', 'Re-install on Shopify when you’re ready.');
    },
    onError: (err: any) => {
      toast.error("Couldn't disconnect", err?.response?.data?.message ?? err.message);
    },
  });
}

/* ---- Meta ad-account connection (Phase 2) --------------------------- */

export interface MetaAdAccount {
  external_id: string;
  name: string;
  currency: string;
}

/**
 * GET /api/brands/{slug}/connections/meta/available — every ad account the
 * agency System User token can see under the Business Manager. Only fetched
 * when `enabled` (the picker is open) so we don't hit Meta on every page view.
 */
export function useMetaAvailableAccounts(brandSlug: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['meta-available', brandSlug],
    enabled: !!brandSlug && enabled,
    staleTime: 5 * 60_000,
    queryFn: async (): Promise<MetaAdAccount[]> => {
      const { data } = await api.get<{ accounts: MetaAdAccount[] }>(
        `/brands/${brandSlug}/connections/meta/available`
      );
      return data.accounts ?? [];
    },
  });
}

/**
 * POST /api/brands/{slug}/connections/meta/attach — saves the selected ad
 * accounts onto the brand's single Meta connection. Their daily spend is
 * blended at sync time (see InsightsFetcher).
 */
export function useAttachMetaAccounts() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { brandSlug: string; accountIds: string[] }) => {
      const { data } = await api.post(
        `/brands/${input.brandSlug}/connections/meta/attach`,
        { account_ids: input.accountIds }
      );
      return data;
    },
    onSuccess: (_d, vars) => {
      qc.invalidateQueries({ queryKey: ['brand', vars.brandSlug] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Meta accounts saved', 'Run a sync to pull spend for the selected accounts.');
    },
    onError: (err: any) => {
      toast.error("Couldn't save accounts", err?.response?.data?.message ?? err.message);
    },
  });
}

/* ---- Google ad-account connection (Phase 2) ------------------------ */

/**
 * GET /api/brands/{slug}/connections/google/available — every customer account
 * under the agency MCC the org token can see. Only fetched when the picker is
 * open so we don't hit Google on every page view.
 */
export function useGoogleAvailableAccounts(brandSlug: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['google-available', brandSlug],
    enabled: !!brandSlug && enabled,
    staleTime: 5 * 60_000,
    queryFn: async (): Promise<MetaAdAccount[]> => {
      const { data } = await api.get<{ accounts: MetaAdAccount[] }>(
        `/brands/${brandSlug}/connections/google/available`
      );
      return data.accounts ?? [];
    },
  });
}

/**
 * POST /api/brands/{slug}/connections/google/attach — saves the selected
 * customer IDs onto the brand's single Google connection. Spend is blended at
 * sync time (see Google ReportsFetcher).
 */
export function useAttachGoogleAccounts() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { brandSlug: string; accountIds: string[] }) => {
      const { data } = await api.post(
        `/brands/${input.brandSlug}/connections/google/attach`,
        { account_ids: input.accountIds }
      );
      return data;
    },
    onSuccess: (_d, vars) => {
      qc.invalidateQueries({ queryKey: ['brand', vars.brandSlug] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Google accounts saved', 'Run a sync to pull spend for the selected accounts.');
    },
    onError: (err: any) => {
      toast.error("Couldn't save accounts", err?.response?.data?.message ?? err.message);
    },
  });
}

/* ---- TikTok ad-account connection (Phase 2) ----------------------- */

/**
 * GET /api/brands/{slug}/connections/tiktok/available — every advertiser under
 * the agency Business Center the BC token can see. Fetched only when the picker
 * is open.
 */
export function useTikTokAvailableAccounts(brandSlug: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['tiktok-available', brandSlug],
    enabled: !!brandSlug && enabled,
    staleTime: 5 * 60_000,
    queryFn: async (): Promise<MetaAdAccount[]> => {
      const { data } = await api.get<{ accounts: MetaAdAccount[] }>(
        `/brands/${brandSlug}/connections/tiktok/available`
      );
      return data.accounts ?? [];
    },
  });
}

/**
 * POST /api/brands/{slug}/connections/tiktok/attach — saves the selected
 * advertiser IDs onto the brand's single TikTok connection. Spend is blended at
 * sync time (see TikTok ReportsFetcher).
 */
export function useAttachTikTokAccounts() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { brandSlug: string; accountIds: string[] }) => {
      const { data } = await api.post(
        `/brands/${input.brandSlug}/connections/tiktok/attach`,
        { account_ids: input.accountIds }
      );
      return data;
    },
    onSuccess: (_d, vars) => {
      qc.invalidateQueries({ queryKey: ['brand', vars.brandSlug] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('TikTok accounts saved', 'Run a sync to pull spend for the selected advertisers.');
    },
    onError: (err: any) => {
      toast.error("Couldn't save accounts", err?.response?.data?.message ?? err.message);
    },
  });
}

/**
 * PATCH /api/brands/{slug} — partial update. Server validates each field
 * with `sometimes`, so we only send what changed.
 */
export function useUpdateBrand() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: { slug: string; patch: UpdateBrandInput }) => {
      const { data } = await api.patch<Brand>(`/brands/${input.slug}`, input.patch);
      return data;
    },
    onSuccess: (brand, vars) => {
      // Invalidate both the list cache and the per-brand detail cache so the
      // header on the detail page reflects the change without a full reload.
      qc.invalidateQueries({ queryKey: ['brands'] });
      qc.invalidateQueries({ queryKey: ['brand', vars.slug] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Brand updated', `${brand.name} settings saved.`);
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors;
      const first = errors ? Object.values(errors)[0] : null;
      const msg = Array.isArray(first) ? first[0] : (err?.response?.data?.message ?? err.message);
      toast.error("Couldn't update brand", msg);
    },
  });
}

export interface ShopifyInstallStatus {
  installed: boolean;
  status: 'active' | 'errored' | 'paused' | null;
  shop: string | null;
  lastError: string | null;
  lastSyncAt: string | null;
}

/**
 * GET /api/brands/{slug}/connections/shopify/status
 *
 * Lightweight poll used by the OAuth install flow. After the install URL
 * opens in a sibling tab, we can't observe its completion directly — so we
 * poll this every couple of seconds until status flips to 'active' or the
 * operator gives up.
 */
export async function getShopifyInstallStatus(brandSlug: string): Promise<ShopifyInstallStatus> {
  const { data } = await api.get<ShopifyInstallStatus>(
    `/brands/${brandSlug}/connections/shopify/status`
  );
  return data;
}

export interface ShopifyPreviewOrder {
  id: string;
  name: string;
  createdAt: string;
  currentTotalPriceSet: { shopMoney: { amount: string; currencyCode: string } };
  customer: { id: string; email: string | null } | null;
  lineItems: { edges: { node: { title: string; quantity: number } }[] };
}

export interface ShopifyPreviewResponse {
  shop: {
    name: string;
    myshopifyDomain: string;
    currencyCode: string;
    ianaTimezone: string;
  } | null;
  orders: ShopifyPreviewOrder[];
  count: number;
}

/**
 * POST /api/brands/{slug}/connections/shopify/preview
 *
 * Live probe — fetches the 5 most recent orders directly from Shopify and
 * returns the raw payload. Used to prove a fresh install works before
 * running a full historical sync. The connection's status auto-flips to
 * active/errored based on the result, so the brand detail page refreshes
 * itself after.
 */
export function useShopifyPreview() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (brandSlug: string): Promise<ShopifyPreviewResponse> => {
      const { data } = await api.post<ShopifyPreviewResponse>(
        `/brands/${brandSlug}/connections/shopify/preview`
      );
      return data;
    },
    onSuccess: (_d, brandSlug) => {
      qc.invalidateQueries({ queryKey: ['brand', brandSlug] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.message ?? err.message;
      toast.error("Test connection failed", msg);
    },
  });
}

/**
 * POST /api/brands/{brand}/connections/shopify/auth-url with shop domain
 * → returns the Shopify install URL to open in a new tab.
 */
export function useShopifyInstallUrl() {
  return useMutation({
    mutationFn: async (input: {
      brandSlug: string;
      shopDomain: string;
      clientId?: string;
      clientSecret?: string;
    }) => {
      const { data } = await api.post<ShopifyInstallUrl>(
        `/brands/${input.brandSlug}/connections/shopify/auth-url`,
        {
          shop_domain: input.shopDomain,
          // Backend persists these on the brand if provided; subsequent
          // installs for the same brand can omit them.
          client_id: input.clientId || undefined,
          client_secret: input.clientSecret || undefined,
        }
      );
      return data;
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors;
      const first = errors ? Object.values(errors)[0] : null;
      const msg = Array.isArray(first) ? first[0] : (err?.response?.data?.message ?? err.message);
      toast.error("Couldn't start Shopify install", msg);
    },
  });
}

/**
 * POST /api/brands/{slug}/sync — fire-and-forget queue dispatch. Server-side
 * rate limit is 5/min/user per spec §04.
 */
interface SyncTriggerResponse {
  dispatched: number;
  mode?: string;
  from?: string;
  to?: string;
  platforms?: string[];
}

export function useTriggerSync() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (brandSlug: string) => {
      const { data } = await api.post<SyncTriggerResponse>(`/brands/${brandSlug}/sync`);
      return data;
    },
    onSuccess: (data, brandSlug) => {
      qc.invalidateQueries({ queryKey: ['brand', brandSlug] });
      qc.invalidateQueries({ queryKey: ['brand', brandSlug, 'metrics'] });
      qc.invalidateQueries({ queryKey: ['sync-status'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });

      const hasShopify = data.platforms?.includes('shopify');
      toast.success(
        'Sync queued',
        hasShopify
          ? `Pulling all historical Shopify orders in the background. Refreshing the brand page every few seconds — new data appears as each day completes.`
          : 'Sync running in the background.'
      );

      // Poll for new daily_metrics rows for ~3 minutes. As soon as a sync
      // worker writes a row, the brand metrics endpoint reflects it; this
      // keeps the UI live without the operator having to refresh manually.
      const deadline = Date.now() + 180_000;
      const tick = setInterval(() => {
        if (Date.now() > deadline) {
          clearInterval(tick);
          return;
        }
        qc.invalidateQueries({ queryKey: ['brand', brandSlug, 'metrics'] });
        qc.invalidateQueries({ queryKey: ['sync-status'] });
      }, 4000);
    },
    onError: (err: any) => {
      // 409 = controller's idempotency guard tripped — brand already has
      // queued/running work. Surface as an info toast (this isn't an
      // error from the operator's perspective; the sync just didn't
      // double-queue) and bring them to Sync health to see what's running.
      if (err?.response?.status === 409 && err.response?.data?.reason === 'already_in_progress') {
        qc.invalidateQueries({ queryKey: ['sync-status'] });
        toast.info(
          'Sync already in progress',
          err.response.data.message ??
            'A sync is already running for this brand. Wait for it to finish before queueing another.'
        );
        return;
      }
      const msg = err?.response?.data?.message ?? err.message;
      toast.error("Couldn't queue sync", msg);
    },
  });
}
