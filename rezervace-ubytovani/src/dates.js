// Pomocné funkce pro práci s daty ve formátu YYYY-MM-DD.
// Počítá se v UTC, aby výsledek nezávisel na časové zóně serveru.

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

export function isValidIsoDate(value) {
  if (typeof value !== 'string' || !ISO_DATE.test(value)) return false;
  const d = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === value;
}

export function addDays(isoDate, days) {
  const d = new Date(`${isoDate}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Noci pobytu: date_from je první noc, date_to (den odjezdu) se nepočítá. */
export function nightsBetween(dateFrom, dateTo) {
  const nights = [];
  for (let d = dateFrom; d < dateTo; d = addDays(d, 1)) nights.push(d);
  return nights;
}

/** Noc z pátku na sobotu a ze soboty na neděli je víkendová. */
export function isWeekendNight(isoDate) {
  const day = new Date(`${isoDate}T00:00:00Z`).getUTCDay();
  return day === 5 || day === 6;
}

export function todayInPrague() {
  // Locale sv-SE vrací datum přímo ve tvaru YYYY-MM-DD.
  return new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Prague' });
}
