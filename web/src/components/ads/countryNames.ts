// ISO-2 country code → display name, for the Ads region map + table. Meta's
// `country` breakdown returns ISO-2 codes; this gives them readable labels.
// Falls back to the raw code for anything not listed.
const NAMES: Record<string, string> = {
  US: 'United States', GB: 'United Kingdom', DE: 'Germany', FR: 'France', ES: 'Spain', IT: 'Italy',
  NL: 'Netherlands', BE: 'Belgium', PT: 'Portugal', CH: 'Switzerland', AT: 'Austria', IE: 'Ireland',
  SE: 'Sweden', NO: 'Norway', DK: 'Denmark', FI: 'Finland', PL: 'Poland', CZ: 'Czechia', SK: 'Slovakia',
  GR: 'Greece', RO: 'Romania', HU: 'Hungary', BG: 'Bulgaria', HR: 'Croatia', SI: 'Slovenia',
  EE: 'Estonia', LV: 'Latvia', LT: 'Lithuania', LU: 'Luxembourg', IS: 'Iceland',
  CA: 'Canada', MX: 'Mexico', BR: 'Brazil', AR: 'Argentina', CL: 'Chile', CO: 'Colombia', PE: 'Peru',
  UY: 'Uruguay', EC: 'Ecuador', CR: 'Costa Rica', PA: 'Panama', DO: 'Dominican Rep.', PR: 'Puerto Rico',
  AU: 'Australia', NZ: 'New Zealand', JP: 'Japan', KR: 'South Korea', CN: 'China', TW: 'Taiwan',
  HK: 'Hong Kong', SG: 'Singapore', MY: 'Malaysia', TH: 'Thailand', VN: 'Vietnam', PH: 'Philippines',
  ID: 'Indonesia', IN: 'India', PK: 'Pakistan', BD: 'Bangladesh',
  AE: 'UAE', SA: 'Saudi Arabia', QA: 'Qatar', KW: 'Kuwait', IL: 'Israel', TR: 'Turkey',
  ZA: 'South Africa', EG: 'Egypt', MA: 'Morocco', NG: 'Nigeria', KE: 'Kenya',
  RU: 'Russia', UA: 'Ukraine',
};

export function countryName(code: string): string {
  if (!code) return 'Unknown';
  return NAMES[code.toUpperCase()] ?? code;
}
