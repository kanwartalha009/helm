<?php

declare(strict_types=1);

namespace App\Support;

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
     * Handles are case-insensitive in practice — the same store served both
     * `/products/NARNIA-PINK` and `/products/narnia-pink` on the SAME day. Lowercasing is what
     * stops one product's sessions being split across two rows.
     */
    private static function clean(string $handle): ?string
    {
        $handle = mb_strtolower(trim(rawurldecode($handle)));
        // Shopify occasionally serves a trailing ')' from a malformed marketing link
        // (observed: "/products/isabella-ribbon-aqua)"). Strip characters a handle can't hold.
        $handle = trim($handle, ").,'\"");

        if ($handle === '') {
            return null;
        }

        return mb_substr($handle, 0, 191);
    }
}
