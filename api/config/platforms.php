<?php

declare(strict_types=1);

use App\Platforms\Google\GoogleAdsAdapter;
use App\Platforms\Meta\MetaAdapter;
use App\Platforms\Shopify\ShopifyAdapter;
use App\Platforms\TikTok\TikTokAdapter;

/**
 * Platform registry. Adapters self-register here and are resolved at runtime
 * via PlatformRegistry::for($key). Adding Pinterest = adding one line.
 */
return [
    'shopify' => ShopifyAdapter::class,
    'meta'    => MetaAdapter::class,
    'google'  => GoogleAdsAdapter::class,
    'tiktok'  => TikTokAdapter::class,
];
