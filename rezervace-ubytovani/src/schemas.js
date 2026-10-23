const isoDate = (description) => ({ type: 'string', format: 'date', description, examples: ['2026-11-20'] });

const errorResponse = {
  type: 'object',
  description: 'Chyba v jednotném tvaru',
  properties: {
    error: {
      type: 'object',
      properties: {
        code: { type: 'string' },
        message: { type: 'string' },
        details: { type: 'array', items: { type: 'string' } },
      },
    },
  },
};

const night = {
  type: 'object',
  properties: {
    date: { type: 'string', format: 'date' },
    price_czk: { type: 'integer' },
    price_eur: { type: 'number' },
  },
};

const reservation = {
  type: 'object',
  properties: {
    reservation_id: { type: 'string' },
    status: { type: 'string', enum: ['confirmed'] },
    hotel_id: { type: 'string' },
    hotel_name: { type: 'string' },
    location: { type: 'string' },
    room_id: { type: 'string' },
    room_type: { type: 'string' },
    date_from: { type: 'string', format: 'date' },
    date_to: { type: 'string', format: 'date' },
    nights: { type: 'integer' },
    people: { type: 'integer' },
    guest: { type: 'object', properties: { name: { type: 'string' }, email: { type: 'string' } } },
    total_price_czk: { type: 'integer' },
    total_price_eur: { type: 'number' },
    price_per_night: { type: 'array', items: night },
    created_at: { type: 'string' },
  },
};

export const searchHotelsSchema = {
  tags: ['Ubytování'],
  summary: 'Vyhledání volných hotelů',
  description:
    'Vrátí hotely v daném městě, které mají aspoň jeden pokoj s dostatečnou kapacitou volný ve všech nocích pobytu. ' +
    'date_from je první noc, date_to je den odjezdu (nepočítá se). Cenový filtr se aplikuje na celkovou cenu pobytu v měně `currency`.',
  body: {
    type: 'object',
    required: ['city', 'date_from', 'date_to', 'people'],
    properties: {
      city: { type: 'string', minLength: 1, maxLength: 100, pattern: '\\S', description: 'Město', examples: ['Praha'] },
      date_from: isoDate('Datum příjezdu (první noc)'),
      date_to: isoDate('Datum odjezdu'),
      people: { type: 'integer', minimum: 1, maximum: 20, description: 'Počet osob' },
      price_min: { type: 'number', minimum: 0, description: 'Minimální celková cena pobytu' },
      price_max: { type: 'number', minimum: 0, description: 'Maximální celková cena pobytu' },
      currency: { type: 'string', enum: ['CZK', 'EUR'], default: 'CZK', description: 'Měna cenového filtru' },
    },
  },
  response: { 400: errorResponse, 404: errorResponse },
};

export const makeReservationSchema = {
  tags: ['Ubytování'],
  summary: 'Vytvoření rezervace',
  description:
    'Ověří existenci hotelu a pokoje, kapacitu a dostupnost všech nocí a vytvoří rezervaci. ' +
    'Rezervované noci zmizí z dostupnosti. Při nedostupnosti vrací 409.',
  body: {
    type: 'object',
    required: ['hotel_id', 'room_id', 'date_from', 'date_to', 'people', 'guest_name', 'guest_email'],
    properties: {
      hotel_id: { type: 'string', minLength: 1, examples: ['HOTEL-PRG-01'] },
      room_id: { type: 'string', minLength: 1, examples: ['R101'] },
      date_from: isoDate('Datum příjezdu (první noc)'),
      date_to: isoDate('Datum odjezdu'),
      people: { type: 'integer', minimum: 1, maximum: 20 },
      guest_name: { type: 'string', minLength: 1, maxLength: 200, pattern: '\\S', examples: ['Jan Novák'] },
      guest_email: { type: 'string', format: 'email', maxLength: 200, examples: ['jan.novak@example.cz'] },
    },
  },
  response: { 201: reservation, 400: errorResponse, 404: errorResponse, 409: errorResponse },
};

export const getReservationSchema = {
  tags: ['Ubytování'],
  summary: 'Detail rezervace',
  params: {
    type: 'object',
    required: ['reservation_id'],
    properties: { reservation_id: { type: 'string', minLength: 1 } },
  },
  response: { 200: reservation, 404: errorResponse },
};

export const hotelsSchema = {
  tags: ['Ubytování'],
  summary: 'Výpis hotelů a jejich volných nocí',
  description: 'Data ve formátu {service, version, hotels: [...]} – volitelně jen pro jedno město.',
  querystring: {
    type: 'object',
    properties: { city: { type: 'string', minLength: 1 } },
  },
};

export const rootSchema = {
  tags: ['Služba'],
  summary: 'Popis služby',
};
