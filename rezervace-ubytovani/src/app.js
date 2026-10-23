import Fastify from 'fastify';
import cors from '@fastify/cors';
import swagger from '@fastify/swagger';
import swaggerUi from '@fastify/swagger-ui';
import { randomBytes } from 'node:crypto';
import { ApiError, registerErrorHandling } from './errors.js';
import { locationKey } from './store.js';
import { nightsBetween, todayInPrague } from './dates.js';
import {
  getReservationSchema,
  hotelsSchema,
  makeReservationSchema,
  rootSchema,
  searchHotelsSchema,
} from './schemas.js';

export const SERVICE = 'Rezervace ubytování API';
export const VERSION = '1.0';
export const MAX_NIGHTS = 30;

const round2 = (n) => Math.round(n * 100) / 100;

/**
 * Sestaví Fastify aplikaci nad JSON úložištěm.
 * @param {object} opts
 * @param {import('./store.js').JsonStore} opts.store
 * @param {() => string} [opts.today] vrací dnešní datum (YYYY-MM-DD), v testech lze podstrčit
 * @param {object|boolean} [opts.logger]
 */
export async function buildApp({ store, today = todayInPrague, logger = false, providerName } = {}) {
  const app = Fastify({ logger, routerOptions: { ignoreTrailingSlash: true } });

  await app.register(cors, { origin: true });
  await app.register(swagger, {
    openapi: {
      info: {
        title: SERVICE,
        description: 'Databáze hotelů, pokojů a jejich dostupnosti. Vyhledávání volných pokojů a rezervace.',
        version: VERSION,
      },
    },
  });
  await app.register(swaggerUi, { routePrefix: '/docs' });
  registerErrorHandling(app);

  // ---- Čtení dat (z paměti) --------------------------------------------
  const byText = (key) => (a, b) => (a[key] < b[key] ? -1 : a[key] > b[key] ? 1 : 0);
  const findHotel = (data, hotelId) => data.hotels.find((h) => h.hotel_id === hotelId);
  const findRoom = (hotel, roomId) => hotel?.available_rooms.find((r) => r.room_id === roomId);
  const roomsOf = (hotel) => [...hotel.available_rooms].sort(byText('room_id'));
  const hotelsInCity = (city) =>
    store.data.hotels.filter((h) => locationKey(h.location) === locationKey(city)).sort(byText('hotel_id'));
  const allHotels = () =>
    [...store.data.hotels].sort((a, b) => byText('location')(a, b) || byText('hotel_id')(a, b));
  /** Volné noci pokoje v intervalu [from, to) – rezervované noci v availability nejsou. */
  const freeNights = (room, from, to = '9999-12-31') =>
    room.availability
      .filter((n) => n.date >= from && n.date < to)
      .sort(byText('date'))
      .map(({ date, price_czk, price_eur }) => ({ date, price_czk, price_eur }));

  const localities = () => [...new Set(store.data.hotels.map((h) => h.location))].sort();

  function validateStay(dateFrom, dateTo) {
    if (dateTo <= dateFrom) {
      throw new ApiError(400, 'INVALID_DATE_RANGE', 'Datum odjezdu (date_to) musí být pozdější než datum příjezdu (date_from).');
    }
    if (dateFrom < today()) {
      throw new ApiError(400, 'DATE_IN_PAST', `Datum příjezdu ${dateFrom} je v minulosti.`);
    }
    const nights = nightsBetween(dateFrom, dateTo);
    if (nights.length > MAX_NIGHTS) {
      throw new ApiError(400, 'STAY_TOO_LONG', `Pobyt může mít nejvýše ${MAX_NIGHTS} nocí.`);
    }
    return nights;
  }

  function summarizeNights(nights) {
    return {
      total_price_czk: nights.reduce((s, n) => s + n.price_czk, 0),
      total_price_eur: round2(nights.reduce((s, n) => s + n.price_eur, 0)),
    };
  }

  function formatReservation(r) {
    const hotel = findHotel(store.data, r.hotel_id);
    const room = findRoom(hotel, r.room_id);
    return {
      reservation_id: r.reservation_id,
      status: r.status,
      hotel_id: r.hotel_id,
      hotel_name: hotel.name,
      location: hotel.location,
      room_id: r.room_id,
      room_type: room.room_type,
      date_from: r.date_from,
      date_to: r.date_to,
      nights: r.price_per_night.length,
      people: r.people,
      guest: { name: r.guest_name, email: r.guest_email },
      total_price_czk: r.total_price_czk,
      total_price_eur: r.total_price_eur,
      price_per_night: r.price_per_night,
      created_at: r.created_at,
    };
  }

  // ---- Endpointy --------------------------------------------------------

  app.get('/', { schema: rootSchema }, async () => ({
    service: SERVICE,
    version: VERSION,
    ...(providerName ? { provider: providerName } : {}),
    endpoints: [
      { method: 'GET', path: '/', description: 'Popis služby' },
      { method: 'GET', path: '/hotels', description: 'Výpis hotelů a jejich volných nocí (volitelně ?city=)' },
      { method: 'POST', path: '/search-hotels', description: 'Vyhledání volných pokojů pro město, termín a počet osob' },
      { method: 'POST', path: '/make-reservation', description: 'Vytvoření rezervace pokoje' },
      { method: 'GET', path: '/reservations/{reservation_id}', description: 'Detail rezervace' },
      { method: 'GET', path: '/docs', description: 'Dokumentace API (Swagger UI)' },
    ],
    localities: localities(),
  }));

  app.get('/hotels', { schema: hotelsSchema }, async (request) => {
    const hotels = request.query.city ? hotelsInCity(request.query.city) : allHotels();
    return {
      service: SERVICE,
      version: VERSION,
      hotels: hotels.map((h) => ({
        hotel_id: h.hotel_id,
        name: h.name,
        location: h.location,
        available_rooms: roomsOf(h).map((r) => ({
          room_id: r.room_id,
          room_type: r.room_type,
          capacity_persons: r.capacity_persons,
          availability: freeNights(r, today()),
        })),
      })),
    };
  });

  app.post('/search-hotels', { schema: searchHotelsSchema }, async (request) => {
    const { city, date_from, date_to, people, price_min, price_max, currency = 'CZK' } = request.body;
    const nights = validateStay(date_from, date_to);
    if (price_min !== undefined && price_max !== undefined && price_min > price_max) {
      throw new ApiError(400, 'INVALID_PRICE_RANGE', 'Minimální cena (price_min) nesmí být vyšší než maximální (price_max).');
    }

    const candidates = hotelsInCity(city);
    if (candidates.length === 0) {
      throw new ApiError(
        404,
        'CITY_NOT_FOUND',
        `Lokalita '${city}' není v nabídce tohoto poskytovatele. Nabízené lokality: ${localities().join(', ')}.`,
      );
    }

    const priceKey = currency === 'EUR' ? 'total_price_eur' : 'total_price_czk';
    const hotels = [];
    for (const h of candidates) {
      const rooms = [];
      for (const r of roomsOf(h)) {
        if (r.capacity_persons < people) continue;
        const free = freeNights(r, date_from, date_to);
        if (free.length !== nights.length) continue; // aspoň jedna noc není volná
        const totals = summarizeNights(free);
        if (price_min !== undefined && totals[priceKey] < price_min) continue;
        if (price_max !== undefined && totals[priceKey] > price_max) continue;
        rooms.push({
          room_id: r.room_id,
          room_type: r.room_type,
          capacity_persons: r.capacity_persons,
          availability: free,
          nights: free.length,
          ...totals,
        });
      }
      if (rooms.length > 0) {
        hotels.push({ hotel_id: h.hotel_id, name: h.name, location: h.location, available_rooms: rooms });
      }
    }

    return {
      service: SERVICE,
      version: VERSION,
      query: { city, date_from, date_to, people, nights: nights.length, currency, price_min, price_max },
      hotels,
    };
  });

  // Rezervace jde přes serializovanou frontu zápisů úložiště: dva souběžné požadavky na stejný
  // pokoj se zpracují jeden po druhém, takže druhý už noci nenajde a dostane 409.
  // Vše se ověří dřív, než se data změní; při chybě zůstanou data i soubor beze změny.
  function reserve(draft, input, nights) {
    const hotel = findHotel(draft, input.hotel_id);
    if (!hotel) throw new ApiError(404, 'HOTEL_NOT_FOUND', `Hotel '${input.hotel_id}' neexistuje.`);
    const room = findRoom(hotel, input.room_id);
    if (!room) {
      throw new ApiError(404, 'ROOM_NOT_FOUND', `Pokoj '${input.room_id}' v hotelu '${input.hotel_id}' neexistuje.`);
    }
    if (input.people > room.capacity_persons) {
      throw new ApiError(
        409,
        'CAPACITY_EXCEEDED',
        `Pokoj '${room.room_id}' má kapacitu ${room.capacity_persons} osob, požadováno ${input.people}.`,
      );
    }

    const free = freeNights(room, input.date_from, input.date_to);
    if (free.length !== nights.length) {
      const freeDates = new Set(free.map((n) => n.date));
      const missing = nights.filter((d) => !freeDates.has(d));
      throw new ApiError(
        409,
        'ROOM_NOT_AVAILABLE',
        `Pokoj '${room.room_id}' není v požadovaném termínu volný (obsazené noci: ${missing.join(', ')}).`,
        missing,
      );
    }

    // Teprve teď měníme data: noci zmizí z dostupnosti a vznikne rezervace.
    const reservationId = `RES-${input.date_from.replaceAll('-', '')}-${randomBytes(4).toString('hex').toUpperCase()}`;
    room.availability = room.availability.filter((n) => n.date < input.date_from || n.date >= input.date_to);
    const reservation = {
      reservation_id: reservationId,
      status: 'confirmed',
      hotel_id: input.hotel_id,
      room_id: input.room_id,
      date_from: input.date_from,
      date_to: input.date_to,
      people: input.people,
      guest_name: input.guest_name.trim(),
      guest_email: input.guest_email,
      ...summarizeNights(free),
      price_per_night: free,
      created_at: new Date().toISOString(),
    };
    draft.reservations[reservationId] = reservation;
    return reservation;
  }

  app.post('/make-reservation', { schema: makeReservationSchema }, async (request, reply) => {
    const nights = validateStay(request.body.date_from, request.body.date_to);
    const created = await store.update((draft) => reserve(draft, request.body, nights));
    return reply.status(201).send(formatReservation(created));
  });

  app.get('/reservations/:reservation_id', { schema: getReservationSchema }, async (request) => {
    const r = Object.hasOwn(store.data.reservations, request.params.reservation_id)
      ? store.data.reservations[request.params.reservation_id]
      : null;
    if (!r) {
      throw new ApiError(404, 'RESERVATION_NOT_FOUND', `Rezervace '${request.params.reservation_id}' neexistuje.`);
    }
    return formatReservation(r);
  });

  return app;
}
