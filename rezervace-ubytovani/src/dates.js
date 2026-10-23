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

export function nightsBetween(dateFrom, dateTo) {
  const nights = [];
  for (let d = dateFrom; d < dateTo; d = addDays(d, 1)) nights.push(d);
  return nights;
}

export function isWeekendNight(isoDate) {
  const day = new Date(`${isoDate}T00:00:00Z`).getUTCDay();
  return day === 5 || day === 6;
}

export function todayInPrague() {
  return new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Prague' });
}
