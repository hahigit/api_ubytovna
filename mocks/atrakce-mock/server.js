// Mock cizího "Lokální atrakce API". Spouští se vícekrát s různými daty:
//   DATA_FILE=data/attractions_a.json PORT=8101 node server.js
//   DATA_FILE=data/attractions_b.json PORT=8102 node server.js
// Volitelně DELAY_MS=3000 zpomalí odpovědi (pro testování timeoutu v Plánovači).
import Fastify from 'fastify';
import cors from '@fastify/cors';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const port = Number(process.env.PORT ?? 8101);
const host = process.env.HOST ?? '0.0.0.0';
const delayMs = Number(process.env.DELAY_MS ?? 0);
const dataFile = resolve(root, process.env.DATA_FILE ?? 'data/attractions_a.json');
const data = JSON.parse(readFileSync(dataFile, 'utf8'));

const SERVICE = 'Lokální atrakce API';
const VERSION = '1.0';

const cityKey = (s) => String(s).normalize('NFD').replace(/\p{Diacritic}/gu, '').trim().toLowerCase();
const localities = [...new Set(data.attractions.map((a) => a.location))].sort((a, b) => a.localeCompare(b, 'cs'));
const error = (code, message) => ({ error: { code, message } });

const app = Fastify({ logger: { level: process.env.LOG_LEVEL ?? 'info' }, routerOptions: { ignoreTrailingSlash: true } });
await app.register(cors, { origin: true });

if (delayMs > 0) {
  app.addHook('onRequest', async () => new Promise((r) => setTimeout(r, delayMs)));
}

app.get('/', async () => ({ service: SERVICE, version: VERSION, localities }));

app.get('/attractions/date/', async (request, reply) => {
  const { city } = request.query;
  if (typeof city !== 'string' || city.trim() === '') {
    return reply.status(400).send(error('VALIDATION_ERROR', "Chybí povinný parametr 'city'."));
  }
  // Pro město bez atrakcí vracíme prázdný seznam, ne chybu.
  const attractions = data.attractions.filter((a) => cityKey(a.location) === cityKey(city));
  return { service: SERVICE, version: VERSION, attractions };
});

app.setNotFoundHandler((request, reply) => {
  reply.status(404).send(error('NOT_FOUND', `Endpoint ${request.method} ${request.url} neexistuje.`));
});

app.setErrorHandler((err, request, reply) => {
  request.log.error(err);
  reply.status(err.statusCode ?? 500).send(error('INTERNAL_ERROR', 'Interní chyba serveru.'));
});

try {
  await app.listen({ port, host });
  app.log.info(`Data ${dataFile}, lokality: ${localities.join(', ')}`);
} catch (err) {
  app.log.error(err);
  process.exit(1);
}
