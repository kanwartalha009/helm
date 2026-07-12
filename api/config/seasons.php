<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Seasonal creative windows + keywords (master plan §6.1) — GO-3.1
|--------------------------------------------------------------------------
| The flagship detector: an ad still spending money on Christmas copy in February.
|
| THE TRIGGER IS A RULE, NEVER AN LLM (D-016, master plan §6.1). A recommendation fires
| on: (keyword match) AND (today > season end + grace). An LLM may later enrich the
| PROSE of an explanation, but it can never be the reason an alert exists. A model that
| can invent a reason to spend a client's attention is a model that will eventually
| invent one.
|
| Windows are MM-DD and recur yearly. `ends` before `starts` means the season wraps the
| new year (Christmas: Nov 15 → Jan 6 — the ES/IT Three Kings/Epiphany gift moment, which
| is why Christmas creative legitimately runs into early January in those markets).
|
| Keywords are matched case- and accent-insensitively against the ad's name and body
| text. They are deliberately SPECIFIC: "sale" alone would match every ad ever written,
| and a detector that fires on everything is a detector nobody reads. Where a term is
| ambiguous across seasons (e.g. "sale"), it is omitted — a missed stale ad costs less
| than a false alarm that teaches the operator to ignore the badge.
|
| Languages: EN, ES, FR, IT, DE, NL (Bosco's EU markets).
*/

return [

    // Days after a season ends before its creative counts as stale. A campaign winding
    // down over a few days is normal; two weeks later is money burning on a dead hook.
    'grace_days' => (int) env('HELM_SEASON_GRACE_DAYS', 7),

    // An ad counts as LIVE if it spent in this window. No spend = nothing to fix.
    'live_window_days' => 7,

    'seasons' => [

        'christmas' => [
            'label' => 'Christmas',
            'starts' => '11-15',
            'ends'   => '01-06',   // through Three Kings / Epiphany (ES, IT)
            'keywords' => [
                'en' => ['christmas', 'xmas', 'santa', 'advent', 'stocking stuffer', 'holiday gift'],
                'es' => ['navidad', 'navideño', 'navideña', 'papá noel', 'reyes magos', 'nochebuena'],
                'fr' => ['noël', 'noel', 'père noël', 'sapin de noël'],
                'it' => ['natale', 'natalizio', 'natalizia', 'babbo natale', 'epifania', 'befana'],
                'de' => ['weihnachten', 'weihnachts', 'weihnachtlich', 'nikolaus', 'advent'],
                'nl' => ['kerst', 'kerstmis', 'kerstcadeau', 'sinterklaas'],
            ],
        ],

        'black_friday' => [
            'label' => 'Black Friday / Cyber Monday',
            'starts' => '11-01',
            'ends'   => '12-05',
            'keywords' => [
                'en' => ['black friday', 'cyber monday', 'bfcm', 'black week'],
                'es' => ['black friday', 'viernes negro', 'cyber monday'],
                'fr' => ['black friday', 'vendredi noir', 'cyber monday'],
                'it' => ['black friday', 'venerdì nero', 'cyber monday'],
                'de' => ['black friday', 'schwarzer freitag', 'cyber monday'],
                'nl' => ['black friday', 'cyber monday'],
            ],
        ],

        'winter_sale' => [
            'label' => 'Winter sales (soldes / rebajas / saldi)',
            'starts' => '01-01',
            'ends'   => '02-28',
            'keywords' => [
                'en' => ['winter sale', 'january sale'],
                'es' => ['rebajas de invierno', 'rebajas enero', 'rebajas'],
                'fr' => ['soldes d\'hiver', 'soldes hiver', 'soldes'],
                'it' => ['saldi invernali', 'saldi di gennaio', 'saldi'],
                'de' => ['winterschlussverkauf', 'winter sale'],
                'nl' => ['winteruitverkoop', 'winter sale'],
            ],
        ],

        'valentines' => [
            'label' => "Valentine's Day",
            'starts' => '01-20',
            'ends'   => '02-14',
            'keywords' => [
                'en' => ["valentine", "valentine's", 'valentines day'],
                'es' => ['san valentín', 'san valentin', 'día de los enamorados'],
                'fr' => ['saint-valentin', 'saint valentin'],
                'it' => ['san valentino'],
                'de' => ['valentinstag'],
                'nl' => ['valentijn', 'valentijnsdag'],
            ],
        ],

        'mothers_day' => [
            'label' => "Mother's Day",
            // Dates differ per market (FR is late May, ES early May, UK is March) — the
            // window is deliberately wide enough to cover the EU spread. Per-market
            // precision arrives with the GO-4 market calendar.
            'starts' => '03-01',
            'ends'   => '06-05',
            'keywords' => [
                'en' => ["mother's day", 'mothers day', 'gift for mum', 'gift for mom'],
                'es' => ['día de la madre', 'dia de la madre'],
                'fr' => ['fête des mères', 'fete des meres'],
                'it' => ['festa della mamma'],
                'de' => ['muttertag'],
                'nl' => ['moederdag'],
            ],
        ],

        'fathers_day' => [
            'label' => "Father's Day",
            'starts' => '03-01',
            'ends'   => '06-25',
            'keywords' => [
                'en' => ["father's day", 'fathers day', 'gift for dad'],
                'es' => ['día del padre', 'dia del padre'],
                'fr' => ['fête des pères', 'fete des peres'],
                'it' => ['festa del papà', 'festa del papa'],
                'de' => ['vatertag'],
                'nl' => ['vaderdag'],
            ],
        ],

        'summer_sale' => [
            'label' => 'Summer sales (soldes d\'été / rebajas / saldi)',
            'starts' => '06-15',
            'ends'   => '08-31',
            'keywords' => [
                'en' => ['summer sale'],
                'es' => ['rebajas de verano', 'rebajas verano'],
                'fr' => ['soldes d\'été', 'soldes ete', 'soldes d ete'],
                'it' => ['saldi estivi', 'saldi estate'],
                'de' => ['sommerschlussverkauf', 'summer sale'],
                'nl' => ['zomeruitverkoop', 'zomer sale'],
            ],
        ],

        'back_to_school' => [
            'label' => 'Back to school',
            'starts' => '08-01',
            'ends'   => '09-30',
            'keywords' => [
                'en' => ['back to school'],
                'es' => ['vuelta al cole', 'regreso a clases'],
                'fr' => ['rentrée scolaire', 'rentree scolaire'],
                'it' => ['ritorno a scuola'],
                'de' => ['schulanfang', 'back to school'],
                'nl' => ['terug naar school'],
            ],
        ],

        'halloween' => [
            'label' => 'Halloween',
            'starts' => '10-01',
            'ends'   => '11-01',
            'keywords' => [
                'en' => ['halloween', 'trick or treat'],
                'es' => ['halloween', 'noche de brujas'],
                'fr' => ['halloween'],
                'it' => ['halloween'],
                'de' => ['halloween'],
                'nl' => ['halloween'],
            ],
        ],

        'new_year' => [
            'label' => 'New Year',
            'starts' => '12-20',
            'ends'   => '01-15',
            'keywords' => [
                'en' => ['new year', 'nye', 'new year resolution'],
                'es' => ['año nuevo', 'ano nuevo', 'nochevieja'],
                'fr' => ['nouvel an', 'bonne année'],
                'it' => ['capodanno', 'anno nuovo'],
                'de' => ['silvester', 'neujahr'],
                'nl' => ['nieuwjaar', 'oud en nieuw'],
            ],
        ],
    ],

];
