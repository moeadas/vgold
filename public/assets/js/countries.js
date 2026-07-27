// VGo — ISO country reference shared by the CRM lead forms.
// Packed as one string (iso2|name|dial|business region, comma separated) to keep
// the file small; parsed once at load into COUNTRIES.
//
// `region` is the CRM's business region, used to derive crm_leads.region from the
// selected country so the "By Region" report stops depending on hand-typed values.
const COUNTRY_PACKED = 'AF|Afghanistan|93|Asia Pacific,AL|Albania|355|Europe,DZ|Algeria|213|Africa,AD|Andorra|376|Europe,AO|Angola|244|Africa,AG|Antigua and Barbuda|1268|Latin America,AR|Argentina|54|Latin America,AM|Armenia|374|Europe,AU|Australia|61|Asia Pacific,AT|Austria|43|Europe,AZ|Azerbaijan|994|Europe,BS|Bahamas|1242|Latin America,BH|Bahrain|973|Middle East,BD|Bangladesh|880|Asia Pacific,BB|Barbados|1246|Latin America,BY|Belarus|375|Europe,BE|Belgium|32|Europe,BZ|Belize|501|Latin America,BJ|Benin|229|Africa,BM|Bermuda|1441|North America,BT|Bhutan|975|Asia Pacific,BO|Bolivia|591|Latin America,BA|Bosnia and Herzegovina|387|Europe,BW|Botswana|267|Africa,BR|Brazil|55|Latin America,BN|Brunei|673|Asia Pacific,BG|Bulgaria|359|Europe,BF|Burkina Faso|226|Africa,BI|Burundi|257|Africa,KH|Cambodia|855|Asia Pacific,CM|Cameroon|237|Africa,CA|Canada|1|North America,CV|Cape Verde|238|Africa,KY|Cayman Islands|1345|Latin America,CF|Central African Republic|236|Africa,TD|Chad|235|Africa,CL|Chile|56|Latin America,CN|China|86|Asia Pacific,CO|Colombia|57|Latin America,KM|Comoros|269|Africa,CG|Congo|242|Africa,CD|Congo (DRC)|243|Africa,CR|Costa Rica|506|Latin America,CI|Cote d\'Ivoire|225|Africa,HR|Croatia|385|Europe,CU|Cuba|53|Latin America,CY|Cyprus|357|Europe,CZ|Czechia|420|Europe,DK|Denmark|45|Europe,DJ|Djibouti|253|Africa,DM|Dominica|1767|Latin America,DO|Dominican Republic|1809|Latin America,EC|Ecuador|593|Latin America,EG|Egypt|20|Middle East,SV|El Salvador|503|Latin America,GQ|Equatorial Guinea|240|Africa,ER|Eritrea|291|Africa,EE|Estonia|372|Europe,SZ|Eswatini|268|Africa,ET|Ethiopia|251|Africa,FJ|Fiji|679|Asia Pacific,FI|Finland|358|Europe,FR|France|33|Europe,GA|Gabon|241|Africa,GM|Gambia|220|Africa,GE|Georgia|995|Europe,DE|Germany|49|Europe,GH|Ghana|233|Africa,GI|Gibraltar|350|Europe,GR|Greece|30|Europe,GD|Grenada|1473|Latin America,GT|Guatemala|502|Latin America,GN|Guinea|224|Africa,GW|Guinea-Bissau|245|Africa,GY|Guyana|592|Latin America,HT|Haiti|509|Latin America,HN|Honduras|504|Latin America,HK|Hong Kong|852|Asia Pacific,HU|Hungary|36|Europe,IS|Iceland|354|Europe,IN|India|91|Asia Pacific,ID|Indonesia|62|Asia Pacific,IR|Iran|98|Middle East,IQ|Iraq|964|Middle East,IE|Ireland|353|Europe,IL|Israel|972|Middle East,IT|Italy|39|Europe,JM|Jamaica|1876|Latin America,JP|Japan|81|Asia Pacific,JO|Jordan|962|Middle East,KZ|Kazakhstan|7|Asia Pacific,KE|Kenya|254|Africa,KW|Kuwait|965|Middle East,KG|Kyrgyzstan|996|Asia Pacific,LA|Laos|856|Asia Pacific,LV|Latvia|371|Europe,LB|Lebanon|961|Middle East,LS|Lesotho|266|Africa,LR|Liberia|231|Africa,LY|Libya|218|Middle East,LI|Liechtenstein|423|Europe,LT|Lithuania|370|Europe,LU|Luxembourg|352|Europe,MO|Macau|853|Asia Pacific,MG|Madagascar|261|Africa,MW|Malawi|265|Africa,MY|Malaysia|60|Asia Pacific,MV|Maldives|960|Asia Pacific,ML|Mali|223|Africa,MT|Malta|356|Europe,MR|Mauritania|222|Africa,MU|Mauritius|230|Africa,MX|Mexico|52|Latin America,MD|Moldova|373|Europe,MC|Monaco|377|Europe,MN|Mongolia|976|Asia Pacific,ME|Montenegro|382|Europe,MA|Morocco|212|Africa,MZ|Mozambique|258|Africa,MM|Myanmar|95|Asia Pacific,NA|Namibia|264|Africa,NP|Nepal|977|Asia Pacific,NL|Netherlands|31|Europe,NZ|New Zealand|64|Asia Pacific,NI|Nicaragua|505|Latin America,NE|Niger|227|Africa,NG|Nigeria|234|Africa,MK|North Macedonia|389|Europe,NO|Norway|47|Europe,OM|Oman|968|Middle East,PK|Pakistan|92|Asia Pacific,PS|Palestine|970|Middle East,PA|Panama|507|Latin America,PG|Papua New Guinea|675|Asia Pacific,PY|Paraguay|595|Latin America,PE|Peru|51|Latin America,PH|Philippines|63|Asia Pacific,PL|Poland|48|Europe,PT|Portugal|351|Europe,PR|Puerto Rico|1787|Latin America,QA|Qatar|974|Middle East,RO|Romania|40|Europe,RU|Russia|7|Europe,RW|Rwanda|250|Africa,SA|Saudi Arabia|966|Middle East,SN|Senegal|221|Africa,RS|Serbia|381|Europe,SC|Seychelles|248|Africa,SL|Sierra Leone|232|Africa,SG|Singapore|65|Asia Pacific,SK|Slovakia|421|Europe,SI|Slovenia|386|Europe,SO|Somalia|252|Africa,ZA|South Africa|27|Africa,KR|South Korea|82|Asia Pacific,SS|South Sudan|211|Africa,ES|Spain|34|Europe,LK|Sri Lanka|94|Asia Pacific,SD|Sudan|249|Africa,SR|Suriname|597|Latin America,SE|Sweden|46|Europe,CH|Switzerland|41|Europe,SY|Syria|963|Middle East,TW|Taiwan|886|Asia Pacific,TJ|Tajikistan|992|Asia Pacific,TZ|Tanzania|255|Africa,TH|Thailand|66|Asia Pacific,TG|Togo|228|Africa,TT|Trinidad and Tobago|1868|Latin America,TN|Tunisia|216|Africa,TR|Turkey|90|Europe,TM|Turkmenistan|993|Asia Pacific,UG|Uganda|256|Africa,UA|Ukraine|380|Europe,AE|United Arab Emirates|971|Middle East,GB|United Kingdom|44|Europe,US|United States|1|North America,UY|Uruguay|598|Latin America,UZ|Uzbekistan|998|Asia Pacific,VE|Venezuela|58|Latin America,VN|Vietnam|84|Asia Pacific,YE|Yemen|967|Middle East,ZM|Zambia|260|Africa,ZW|Zimbabwe|263|Africa';

const COUNTRIES = COUNTRY_PACKED.split(',').map(s => {
  const [iso2, name, dial, region] = s.split('|');
  return { iso2, name, dial, region };
});

const COUNTRY_BY_NAME = {};
const COUNTRY_BY_ISO = {};
COUNTRIES.forEach(c => { COUNTRY_BY_NAME[c.name.toLowerCase()] = c; COUNTRY_BY_ISO[c.iso2] = c; });

// Common alternate spellings users have typed into the free-text field.
const COUNTRY_ALIASES = {
  'usa': 'US', 'u.s.a.': 'US', 'u.s.': 'US', 'united states of america': 'US', 'america': 'US',
  'uk': 'GB', 'u.k.': 'GB', 'great britain': 'GB', 'england': 'GB', 'scotland': 'GB', 'wales': 'GB',
  'northern ireland': 'GB', 'uae': 'AE', 'u.a.e.': 'AE', 'emirates': 'AE', 'ksa': 'SA',
  'holland': 'NL', 'the netherlands': 'NL', 'south korea': 'KR', 'korea': 'KR',
  'republic of ireland': 'IE', 'czech republic': 'CZ', 'ivory coast': 'CI', 'swaziland': 'SZ',
  'burma': 'MM', 'macedonia': 'MK', 'russia federation': 'RU', 'russian federation': 'RU',
  'hong kong sar': 'HK', 'vatican': 'IT', 'palestinian territories': 'PS',
};

/** Resolve free-text country input to a country record (or null). */
function countryLookup(value) {
  const v = String(value || '').trim().toLowerCase();
  if (!v) return null;
  if (COUNTRY_BY_NAME[v]) return COUNTRY_BY_NAME[v];
  if (COUNTRY_ALIASES[v]) return COUNTRY_BY_ISO[COUNTRY_ALIASES[v]];
  const up = v.toUpperCase();
  if (up.length === 2 && COUNTRY_BY_ISO[up]) return COUNTRY_BY_ISO[up];
  return null;
}

/** Flag emoji from an ISO-3166 alpha-2 code (regional indicator symbols). */
function countryFlag(iso2) {
  if (!iso2 || iso2.length !== 2) return '\u{1F3F3}';
  return String.fromCodePoint(...[...iso2.toUpperCase()].map(c => 0x1F1E6 + c.charCodeAt(0) - 65));
}

/** Business region for a country name — mirrors CRMController::regionForCountry(). */
function regionForCountry(name) {
  const c = countryLookup(name);
  return c ? c.region : '';
}

/** <option> list for a country <select>, marking `selected` when it matches. */
function countryOptions(selected) {
  const cur = countryLookup(selected);
  const isoSel = cur ? cur.iso2 : '';
  return '<option value="">— Select country —</option>' +
    COUNTRIES.map(c => `<option value="${c.name}" data-iso="${c.iso2}" data-dial="${c.dial}"${c.iso2 === isoSel ? ' selected' : ''}>${countryFlag(c.iso2)} ${c.name}</option>`).join('');
}

// Several countries share a dial code (+1 NANP, +7 RU/KZ). When two candidates
// tie on prefix length, prefer the dominant one rather than whichever sorted first.
const DIAL_TIEBREAK = { '1': 'US', '7': 'RU', '39': 'IT', '44': 'GB' };

/** Best-guess country for a phone number already in E.164 (longest dial match). */
function countryForPhone(phone) {
  const digits = String(phone || '').replace(/[^0-9]/g, '');
  if (!digits) return null;
  let best = null;
  COUNTRIES.forEach(c => {
    if (!digits.startsWith(c.dial)) return;
    if (!best || c.dial.length > best.dial.length) { best = c; return; }
    if (c.dial.length === best.dial.length && DIAL_TIEBREAK[c.dial] === c.iso2) best = c;
  });
  if (best && DIAL_TIEBREAK[best.dial] && best.iso2 !== DIAL_TIEBREAK[best.dial]) {
    // Only override when no longer, more specific prefix matched (e.g. +1868).
    const exact = COUNTRIES.find(c => c.dial === best.dial && c.iso2 === DIAL_TIEBREAK[best.dial]);
    if (exact) best = exact;
  }
  return best;
}

window.COUNTRIES = COUNTRIES;
window.countryLookup = countryLookup;
window.countryFlag = countryFlag;
window.countryOptions = countryOptions;
window.regionForCountry = regionForCountry;
window.countryForPhone = countryForPhone;
