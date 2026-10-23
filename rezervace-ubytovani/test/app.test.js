import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildApp } from '../src/app.js';
import { JsonStore, extendAvailability, loadSeedFile } from '../src/store.js';
import { addDays, isWeekendNight } from '../src/dates.js';

const TODAY = '2026-11-02';
const seedA = loadSeedFile(fileURLToPath(new URL('../seeds/seed_a.json', import.meta.url)));

let app;
let store;
let tmp;
let dataFile;

beforeEach(async () => {
  tmp = mkdtempSync(join(tmpdir(), 'ubytovani-test-'));
  dataFile = join(tmp, 'data', 'db.json');
  ({ store } = await JsonStore.open(dataFile, { seed: seedA, today: TODAY }));
  app = await buildApp({ store, today: () => TODAY });
});

afterEach(async () => {
  await app.close();
  rmSync(tmp, { recursive: true, force: true });
});

const readFile = () => JSON.parse(readFileSync(dataFile, 'utf8'));
const roomOf = (data, hotelId, roomId) =>
  data.hotels.find((h) => h.hotel_id === hotelId).available_rooms.find((r) => r.room_id === roomId);

const search = (body) => app.inject({ method: 'POST', url: '/search-hotels', payload: body });
const reserve = (body) => app.inject({ method: 'POST', url: '/make-reservation', payload: body });

const stay = { date_from: addDays(TODAY, 3), date_to: addDays(TODAY, 6) };
const reservationBody = {
  hotel_id: 'HOTEL-PRG-01',
  room_id: 'R101',
  ...stay,
  people: 2,
  guest_name: 'Jan Novák',
  guest_email: 'jan.novak@example.cz',
};

describe('seed a JSON soubor', () => {
  it('chybějící soubor vytvoří ze seedu: dostupnost na 120 dní, víkendové noci dražší', () => {
    const data = readFile();
    expect(data.service).toBe('Rezervace ubytování API');
    expect(data.reservations).toEqual({});
    const r101 = roomOf(data, 'HOTEL-PRG-01', 'R101');
    expect(Object.keys(r101).sort()).toEqual(['availability', 'capacity_persons', 'room_id', 'room_type']);
    expect(r101.availability).toHaveLength(120);
    expect(r101.availability[0]).toEqual({ date: TODAY, price_czk: 2100, price_eur: 85 });
    const fri = addDays(TODAY, 4);
    expect(isWeekendNight(fri)).toBe(true);
    expect(r101.availability.find((n) => n.date === fri).price_czk).toBe(2350);
  });

  it('opakované doplnění dostupnosti nic nezdvojí ani nepřepíše', () => {
    const data = structuredClone(store.data);
    expect(extendAvailability(data, seedA, TODAY)).toBe(0);
    expect(extendAvailability(data, seedA, addDays(TODAY, 1))).toBe(4);
  });

  it('rezervace přežije restart a rezervované noci se do dostupnosti nevrátí', async () => {
    const { reservation_id } = (await reserve(reservationBody)).json();
    const { store: reopened, created } = await JsonStore.open(dataFile, { seed: seedA, today: TODAY });
    expect(created).toBe(false);
    expect(reopened.data.reservations[reservation_id].guest_name).toBe('Jan Novák');
    expect(roomOf(reopened.data, 'HOTEL-PRG-01', 'R101').availability).toHaveLength(117);
  });

  it('zapisuje atomicky – po zápisu nezůstane .tmp soubor', async () => {
    expect((await reserve(reservationBody)).statusCode).toBe(201);
    expect(existsSync(`${dataFile}.tmp`)).toBe(false);
    expect(Object.keys(readFile().reservations)).toHaveLength(1);
  });

  it('poškozený soubor odmítne načíst a nepřepíše ho', async () => {
    writeFileSync(dataFile, '{"hotels": [');
    await expect(JsonStore.open(dataFile, { seed: seedA, today: TODAY })).rejects.toThrow(/není platný JSON/);
    expect(readFileSync(dataFile, 'utf8')).toBe('{"hotels": [');
  });

  it('při selhání zápisu na disk zůstanou data v paměti beze změny', async () => {
    const original = store.file;
    store.file = join(tmp, 'neexistuje', 'db.json');
    const failed = await reserve(reservationBody);
    expect(failed.statusCode).toBe(500);
    expect(failed.json().error.code).toBe('INTERNAL_ERROR');
    expect(store.data.reservations).toEqual({});

    store.file = original;
    expect((await reserve(reservationBody)).statusCode).toBe(201);
  });
});

describe('GET /', () => {
  it('vrací popis služby a lokality', async () => {
    const res = await app.inject({ method: 'GET', url: '/' });
    expect(res.statusCode).toBe(200);
    expect(res.headers['content-type']).toContain('application/json');
    const body = res.json();
    expect(body.service).toBe('Rezervace ubytování API');
    expect(body.localities).toEqual(['Brno', 'Praha', 'Český Krumlov']);
  });
});

describe('POST /search-hotels', () => {
  it('najde hotel a spočítá celkovou cenu i ceny po nocích', async () => {
    const res = await search({ city: 'Praha', ...stay, people: 2 });
    expect(res.statusCode).toBe(200);
    const [hotel] = res.json().hotels;
    expect(hotel.hotel_id).toBe('HOTEL-PRG-01');
    const r101 = hotel.available_rooms.find((r) => r.room_id === 'R101');
    expect(r101.availability.map((n) => n.price_czk)).toEqual([2100, 2350, 2350]);
    expect(r101.total_price_czk).toBe(6800);
    expect(r101.total_price_eur).toBe(275);
  });

  it('respektuje kapacitu pokoje', async () => {
    const res = await search({ city: 'Praha', ...stay, people: 3 });
    const rooms = res.json().hotels[0].available_rooms.map((r) => r.room_id);
    expect(rooms).toEqual(['R205']);

    const none = await search({ city: 'Brno', ...stay, people: 5 });
    expect(none.statusCode).toBe(200);
    expect(none.json().hotels).toEqual([]);
  });

  it('město hledá bez ohledu na velikost písmen a diakritiku', async () => {
    const res = await search({ city: 'cesky krumlov', ...stay, people: 1 });
    expect(res.json().hotels[0].hotel_id).toBe('HOTEL-KRU-01');
  });

  it('cenový filtr se aplikuje na celkovou cenu ve zvolené měně', async () => {
    const czk = await search({ city: 'Praha', ...stay, people: 1, price_max: 7000 });
    expect(czk.json().hotels[0].available_rooms.map((r) => r.room_id)).toEqual(['R101']);
    const eur = await search({ city: 'Praha', ...stay, people: 1, price_min: 300, currency: 'EUR' });
    expect(eur.json().hotels[0].available_rooms.map((r) => r.room_id)).toEqual(['R205']);
  });

  it('pokoj s obsazenou nocí uprostřed pobytu se nenabízí', async () => {
    const mid = { ...reservationBody, date_from: addDays(TODAY, 4), date_to: addDays(TODAY, 5) };
    expect((await reserve(mid)).statusCode).toBe(201);
    const res = await search({ city: 'Praha', ...stay, people: 2 });
    expect(res.json().hotels[0].available_rooms.map((r) => r.room_id)).toEqual(['R205']);
  });

  it('pobyt mimo vygenerovanou dostupnost vrátí prázdný seznam', async () => {
    const res = await search({ city: 'Praha', date_from: addDays(TODAY, 119), date_to: addDays(TODAY, 121), people: 1 });
    expect(res.statusCode).toBe(200);
    expect(res.json().hotels).toEqual([]);
  });

  it.each([
    [{ ...stay, people: 2 }, 'VALIDATION_ERROR', /city/],
    [{ city: 'Praha', ...stay, people: 0 }, 'VALIDATION_ERROR', /people/],
    [{ city: 'Praha', date_from: '2026-02-30', date_to: '2026-03-02', people: 1 }, 'VALIDATION_ERROR', /date_from/],
    [{ city: 'Praha', date_from: stay.date_to, date_to: stay.date_from, people: 1 }, 'INVALID_DATE_RANGE', /date_to/],
    [{ city: 'Praha', date_from: stay.date_from, date_to: stay.date_from, people: 1 }, 'INVALID_DATE_RANGE', /date_to/],
    [{ city: 'Praha', date_from: addDays(TODAY, -1), date_to: TODAY, people: 1 }, 'DATE_IN_PAST', /minulosti/],
    [{ city: 'Praha', ...stay, people: 1, currency: 'USD' }, 'VALIDATION_ERROR', /CZK, EUR/],
    [{ city: 'Praha', ...stay, people: 1, price_min: 5000, price_max: 100 }, 'INVALID_PRICE_RANGE', /price_min/],
  ])('špatný vstup → 400 (%#)', async (body, code, message) => {
    const res = await search(body);
    expect(res.statusCode).toBe(400);
    expect(res.json().error.code).toBe(code);
    expect(res.json().error.message).toMatch(message);
  });

  it('neznámé město → 404', async () => {
    const res = await search({ city: 'Ostrava', ...stay, people: 1 });
    expect(res.statusCode).toBe(404);
    expect(res.json().error.code).toBe('CITY_NOT_FOUND');
  });

  it('neplatný JSON → 400', async () => {
    const res = await app.inject({
      method: 'POST',
      url: '/search-hotels',
      headers: { 'content-type': 'application/json' },
      payload: '{"city": ',
    });
    expect(res.statusCode).toBe(400);
    expect(res.json().error.code).toBe('INVALID_JSON');
  });
});

describe('POST /make-reservation', () => {
  it('vytvoří rezervaci a noci odebere z dostupnosti', async () => {
    const res = await reserve(reservationBody);
    expect(res.statusCode).toBe(201);
    const r = res.json();
    expect(r.status).toBe('confirmed');
    expect(r.reservation_id).toMatch(/^RES-/);
    expect(r.total_price_czk).toBe(6800);
    expect(r.total_price_eur).toBe(275);
    expect(r.guest).toEqual({ name: 'Jan Novák', email: 'jan.novak@example.cz' });
    expect(r.nights).toBe(3);

    const detail = await app.inject({ method: 'GET', url: `/reservations/${r.reservation_id}` });
    expect(detail.statusCode).toBe(200);
    expect(detail.json()).toEqual(r);

    const r101 = roomOf(readFile(), 'HOTEL-PRG-01', 'R101');
    expect(r101.availability).toHaveLength(117);
    expect(r101.availability.some((n) => n.date >= stay.date_from && n.date < stay.date_to)).toBe(false);
  });

  it('dvojitá rezervace stejného pokoje → 409', async () => {
    expect((await reserve(reservationBody)).statusCode).toBe(201);
    const before = readFileSync(dataFile, 'utf8');
    const second = await reserve({ ...reservationBody, guest_name: 'Petra Svobodová', guest_email: 'petra@example.cz' });
    expect(second.statusCode).toBe(409);
    expect(second.json().error.code).toBe('ROOM_NOT_AVAILABLE');
    expect(readFileSync(dataFile, 'utf8')).toBe(before);
  });

  it('částečně překrývající se termín → 409, navazující termín projde', async () => {
    expect((await reserve(reservationBody)).statusCode).toBe(201);
    const overlap = await reserve({ ...reservationBody, date_from: addDays(TODAY, 5), date_to: addDays(TODAY, 8) });
    expect(overlap.statusCode).toBe(409);
    const adjacent = await reserve({ ...reservationBody, date_from: stay.date_to, date_to: addDays(stay.date_to, 2) });
    expect(adjacent.statusCode).toBe(201);
  });

  it('souběžné požadavky na stejný pokoj: projde právě jeden', async () => {
    const results = await Promise.all(Array.from({ length: 20 }, () => reserve(reservationBody)));
    const codes = results.map((r) => r.statusCode);
    expect(codes.filter((c) => c === 201)).toHaveLength(1);
    expect(codes.filter((c) => c === 409)).toHaveLength(19);
    expect(Object.keys(readFile().reservations)).toHaveLength(1);
  });

  it('souběžné překrývající se termíny: žádná noc není rezervovaná dvakrát', async () => {
    const bodies = Array.from({ length: 10 }, (_, i) => ({
      ...reservationBody,
      date_from: addDays(TODAY, 10 + i),
      date_to: addDays(TODAY, 12 + i),
    }));
    const results = await Promise.all(bodies.map(reserve));
    expect(results.filter((r) => r.statusCode === 201).length).toBeGreaterThanOrEqual(5);
    expect(results.every((r) => [201, 409].includes(r.statusCode))).toBe(true);

    const nights = Object.values(readFile().reservations).flatMap((r) => r.price_per_night.map((n) => n.date));
    expect(new Set(nights).size).toBe(nights.length);
  });

  it('překročení kapacity → 409', async () => {
    const res = await reserve({ ...reservationBody, people: 3 });
    expect(res.statusCode).toBe(409);
    expect(res.json().error.code).toBe('CAPACITY_EXCEEDED');
  });

  it('neexistující hotel nebo pokoj → 404', async () => {
    expect((await reserve({ ...reservationBody, hotel_id: 'HOTEL-XXX' })).json().error.code).toBe('HOTEL_NOT_FOUND');
    const res = await reserve({ ...reservationBody, room_id: 'R999' });
    expect(res.statusCode).toBe(404);
    expect(res.json().error.code).toBe('ROOM_NOT_FOUND');
  });

  it('chybějící a neplatná pole → 400', async () => {
    const { guest_email, ...noEmail } = reservationBody;
    const missing = await reserve(noEmail);
    expect(missing.statusCode).toBe(400);
    expect(missing.json().error.message).toContain("Chybí povinné pole 'guest_email'");

    const badEmail = await reserve({ ...reservationBody, guest_email: 'není-email' });
    expect(badEmail.statusCode).toBe(400);
    expect(badEmail.json().error.message).toContain('e-mailová adresa');
  });

  it('neexistující rezervace → 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/reservations/RES-NEEXISTUJE' });
    expect(res.statusCode).toBe(404);
    expect(res.json().error.code).toBe('RESERVATION_NOT_FOUND');
  });
});

describe('ostatní', () => {
  it('neznámý endpoint → 404 v jednotném formátu', async () => {
    const res = await app.inject({ method: 'GET', url: '/nic' });
    expect(res.statusCode).toBe(404);
    expect(res.json().error.code).toBe('NOT_FOUND');
  });

  it('dokumentace na /docs', async () => {
    const res = await app.inject({ method: 'GET', url: '/docs/json' });
    expect(res.statusCode).toBe(200);
    expect(Object.keys(res.json().paths)).toContain('/search-hotels');
  });

  it('CORS hlavičky', async () => {
    const res = await app.inject({ method: 'GET', url: '/', headers: { origin: 'http://example.com' } });
    expect(res.headers['access-control-allow-origin']).toBe('http://example.com');
  });
});
