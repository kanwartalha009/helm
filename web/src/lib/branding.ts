/**
 * White-label product name for the app shell. A build-time override
 * (VITE_APP_NAME) lets a reselling agency rebrand every surface — including the
 * pre-login pages, which can't read the authenticated workspace setting. Defaults
 * to 'Roasdriven' so the current deployment is unchanged.
 *
 * Authenticated surfaces prefer the LIVE workspace branding (user.agencyName from
 * /auth/me) and fall back to this; pre-login surfaces use this directly.
 */
export const APP_NAME =
  (import.meta.env.VITE_APP_NAME as string | undefined)?.trim() || 'Roasdriven';
