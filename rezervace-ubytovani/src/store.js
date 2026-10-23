import { existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { open, rename } from 'node:fs/promises';
import { dirname } from 'node:path';
import { addDays, isValidIsoDate, isWeekendNight } from './dates.js';

export const SEED_DAYS = 120;
const SERVICE = 'Rezervace ubytování API';
const VERSION = '1.0';

/** Klíč pro porovnání měst bez ohledu na velikost písmen a diakritiku ("ceske budejovice" = "České Budějovice"). */
export function locationKey(city) {
  return String(city).normalize('NFD').replace(/\p{Diacritic}/gu, '').trim().toLowerCase().replace(/\s+/g, ' ');
}

export function loadSeedFile(path) {
  const seed = JSON.parse(readFileSync(path, 'utf8'));
  if (!Array.isArray(seed.hotels)) throw new Error(`Seed ${path} neobsahuje pole "hotels".`);
  return seed;
}

const round2 = (n) => Math.round(n * 100) / 100;

/** Cena za noc: víkendové noci (pá, so) jsou lehce dražší. */
function nightPrice(pricing, date) {
  if (isWeekendNight(date)) {
    return {
      price_czk: pricing.weekend_price_czk ?? Math.round((pricing.price_czk * 1.12) / 10) * 10,
      price_eur: pricing.weekend_price_eur ?? round2(pricing.price_eur * 1.12),
    };
  }
  return { price_czk: pricing.price_czk, price_eur: pricing.price_eur };
}

/**
 * Doplní do dat noci na SEED_DAYS dní od `today` podle ceníků v seedu.
 * Noci, které už existují nebo jsou zarezervované, nepřidává (rezervované noci
 * v dostupnosti chybí záměrně). Vrací počet přidaných nocí.
 */
export function extendAvailability(data, seed, today) {
  const reserved = new Set();
  for (const r of Object.values(data.reservations)) {
    for (const n of r.price_per_night) reserved.add(`${r.hotel_id}|${r.room_id}|${n.date}`);
  }

  let added = 0;
  for (const seedHotel of seed.hotels) {
    const hotel = data.hotels.find((h) => h.hotel_id === seedHotel.hotel_id);
    if (!hotel) continue;
    for (const seedRoom of seedHotel.available_rooms ?? []) {
      const room = hotel.available_rooms.find((r) => r.room_id === seedRoom.room_id);
      if (!room) continue;
      const existing = new Set(room.availability.map((n) => n.date));
      const candidates = (seedRoom.availability ?? [])
        .filter((a) => isValidIsoDate(a.date) && a.date >= today)
        .map((a) => ({ date: a.date, price_czk: a.price_czk, price_eur: a.price_eur }));
      if (seedRoom.pricing) {
        for (let i = 0; i < SEED_DAYS; i++) {
          const date = addDays(today, i);
          candidates.push({ date, ...nightPrice(seedRoom.pricing, date) });
        }
      }
      for (const night of candidates) {
        const key = `${hotel.hotel_id}|${room.room_id}|${night.date}`;
        if (existing.has(night.date) || reserved.has(key)) continue;
        existing.add(night.date);
        room.availability.push(night);
        added++;
      }
      room.availability.sort((a, b) => a.date.localeCompare(b.date));
    }
  }
  return added;
}

/** Prázdná data se strukturou hotelů ze seedu (bez nocí). */
export function dataFromSeed(seed, today) {
  const data = {
    service: SERVICE,
    version: VERSION,
    hotels: seed.hotels.map((h) => ({
      hotel_id: h.hotel_id,
      name: h.name,
      location: h.location,
      available_rooms: (h.available_rooms ?? []).map((r) => ({
        room_id: r.room_id,
        room_type: r.room_type,
        capacity_persons: r.capacity_persons,
        availability: [],
      })),
    })),
    reservations: {},
  };
  extendAvailability(data, seed, today);
  return data;
}

/**
 * Data služby v jednom JSON souboru, držená v paměti.
 *
 * - Čtení jde přímo z paměti (`store.data`).
 * - Zápisy jdou přes `update()`: jedna serializovaná fronta (promise chain), takže se dvě
 *   změny nikdy neprolnou. Mutace pracuje nad kopií dat – když vyhodí výjimku (např. 409)
 *   nebo selže zápis na disk, aktuální data zůstanou beze změny.
 * - Soubor se zapisuje atomicky: <soubor>.tmp → fsync → rename.
 *
 * Jeden soubor smí používat jen jeden proces (každý poskytovatel má vlastní DATA_FILE).
 */
export class JsonStore {
  #queue = Promise.resolve();

  constructor(file, data) {
    this.file = file;
    this.data = data;
  }

  /**
   * Načte soubor; pokud neexistuje, vytvoří ho ze seedu. U existujícího souboru jen
   * posune okno dostupnosti (doplní chybějící budoucí noci), rezervace nepřepisuje.
   */
  static async open(file, { seed, today }) {
    mkdirSync(dirname(file), { recursive: true });
    rmSync(`${file}.tmp`, { force: true }); // pozůstatek po případném pádu při zápisu

    if (!existsSync(file)) {
      const store = new JsonStore(file, dataFromSeed(seed, today));
      await store.update(() => {});
      return { store, created: true, nightsAdded: 0 };
    }

    let data;
    try {
      data = JSON.parse(readFileSync(file, 'utf8'));
    } catch (err) {
      throw new Error(`Datový soubor ${file} není platný JSON (${err.message}). Opravte ho nebo smažte.`);
    }
    if (!Array.isArray(data.hotels) || typeof data.reservations !== 'object' || data.reservations === null) {
      throw new Error(`Datový soubor ${file} nemá očekávanou strukturu {hotels: [], reservations: {}}.`);
    }
    const store = new JsonStore(file, data);
    const nightsAdded = await store.update((draft) => extendAvailability(draft, seed, today));
    return { store, created: false, nightsAdded };
  }

  /**
   * Zařadí změnu do fronty. `mutator(draft)` dostane kopii dat, může ji upravit a vrátit výsledek.
   * Po úspěšném zápisu na disk se kopie stane aktuálními daty.
   */
  update(mutator) {
    const run = async () => {
      const draft = structuredClone(this.data);
      const result = await mutator(draft);
      await this.#write(draft);
      this.data = draft;
      return result;
    };
    const next = this.#queue.then(run);
    this.#queue = next.catch(() => {}); // chyba jedné změny (např. 409) nezablokuje frontu
    return next;
  }

  async #write(data) {
    const tmp = `${this.file}.tmp`;
    const fh = await open(tmp, 'w');
    try {
      await fh.writeFile(JSON.stringify(data, null, 2) + '\n', 'utf8');
      await fh.sync();
    } finally {
      await fh.close();
    }
    await rename(tmp, this.file);
  }
}
