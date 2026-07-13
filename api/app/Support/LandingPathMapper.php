<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Resolves a Shopify landing path to the thing a merchant actually thinks about:
 * a product, a collection, or "somewhere else on the store".
 *
 * This is the ONE place the product-handle regex lives. `AdProductFetcher::productHandle()`
 * delegates here, so Meta ad landing URLs and Shopify session landing paths can never drift
 * into disagreeing about what "/es/products/jay" means — which would silently split one
 * product's numbers across two rows.
 *
 * Shopify landing paths in the wild (all observed on a real store, 2026-07-12):
 *
 *     /products/jay                                  → product  jay
 *     /es/products/jay          (locale prefix)      → product  jay
 *     /fr-fr/products/jay       (region locale)      → product  jay
 *     /collections/best-sellers/products/lucrecia    → product  lucrecia   ← the PRODUCT wins
 *     /products/jay?variant=42  (query string)       → product  jay
 *     /collections/new-in                            → collection new-in
 *     /es/collections/woman                          → collection woman
 *     /                                              → other    store-wide
 *     /pages/returns, /search, /cart, /blogs/...     → other    store-wide
 *     /checkouts/cn/hWNEHT1fJ.../en-gb               → other    store-wide  (unique per session)
 *
 * A collection-nested product path resolves to the PRODUCT, not the collection: the visitor
 * landed on a product page. Counting it as a collection view would inflate collections and
 * starve the product — the opposite of what Inventory Intelligence is for.
 */
final class LandingPathMapper
{
    public const TYPE_PRODUCT    = 'product';
    public const TYPE_COLLECTION = 'collection';
    public const TYPE_OTHER      = 'other';

    /** The single bucket every unmapped path folds into. Never dropped — totals must reconcile. */
    public const OTHER_KEY = 'store-wide';

    /**
     * An optional leading locale segment: /es, /fr-fr, /pt-br. Two letters, optionally
     * hyphen + two more. Deliberately NOT \w+ — that would swallow /products itself.
     */
    private const LOCALE = '(?:/[a-z]{2}(?:-[a-z]{2})?)?';

    /**
     * @return array{type: string, key: string}
     */
    public static function resolve(string $path): array
    {
        $handle = self::productHandle($path);
        if ($handle !== null) {
            return ['type' => self::TYPE_PRODUCT, 'key' => $handle];
        }

        $collection = self::collectionHandle($path);
        if ($collection !== null) {
            return ['type' => self::TYPE_COLLECTION, 'key' => $collection];
        }

        return ['type' => self::TYPE_OTHER, 'key' => self::OTHER_KEY];
    }

    /**
     * Shopify product handle from a landing path or full URL, ignoring any locale prefix.
     * Matches `/products/<handle>` ANYWHERE in the path, so collection-nested product URLs
     * (`/collections/x/products/y`) resolve to the product.
     */
    public static function productHandle(string $path): ?string
    {
        if (preg_match('~/products/([^/?#]+)~i', $path, $m) !== 1) {
            return null;
        }

        return self::clean($m[1]);
    }

    /**
     * Collection handle — only when the path is NOT a product page. `productHandle()` is
     * checked first by `resolve()`, but this guards the case where it's called directly.
     */
    public static function collectionHandle(string $path): ?string
    {
        if (preg_match('~/products/~i', $path) === 1) {
            return null;
        }

        if (preg_match('~' . self::LOCALE . '/collections/([^/?#]+)~i', $path, $m) !== 1) {
            return null;
        }

        return self::clean($m[1]);
    }

    /**
     * Canonicalise a handle to the form Shopify actually stores: lowercase ASCII.
     *
     * ══ THE ACCENT TRAP (2026-07-13) ══
     * `rawurldecode` turns `/products/polo-piqu%C3%A9-stripes` into `polo-piqué-stripes`. In PHP
     * that is a DIFFERENT string from `polo-pique-stripes`. In MySQL it is the SAME one —
     * `utf8mb4_unicode_ci` collation is accent-insensitive. So the aggregator built two buckets,
     * the insert carried two rows, and MySQL rejected the batch:
     *
     *     Duplicate entry '76-2025-08-18-product-polo-pique-stripes-color-1-direct'
     *
     * Folding to ASCII fixes it at the source AND is the more correct answer anyway: Shopify
     * handles are always lowercase ASCII slugs, so `polo-piqué-stripes` and `polo-pique-stripes`
     * are the same product reached by two URL spellings. Their sessions should SUM, not collide.
     *
     * This also aligns session handles with `ad_product_daily.product_key` and the catalog, which
     * are ASCII — a mismatch there would have silently split a product's spend from its sessions.
     */
    private static function clean(string $handle): ?string
    {
        // Str::ascii transliterates é→e, ñ→n, ü→u … using a proper table (not locale-dependent
        // iconv, which can emit "?" or "'e" and would invent a different handle again).
        $handle = Str::ascii(rawurldecode($handle));
        $handle = mb_strtolower(trim($handle));

        // Shopify occasionally serves a trailing ')' from a malformed marketing link
        // (observed: "/products/isabella-ribbon-aqua)"). Strip characters a handle can't hold.
        $handle = trim($handle, ").,'\"");

        if ($handle === '') {
            return null;
        }

        return mb_substr($handle, 0, 191);
    }
}
