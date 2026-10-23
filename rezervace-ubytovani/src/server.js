import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildApp } from './app.js';
import { JsonStore, loadSeedFile } from './store.js';
import { todayInPrague } from './dates.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const port = Number(process.env.PORT ?? 8001);
const host = process.env.HOST ?? '0.0.0.0';
const seedFile = resolve(root, process.env.SEED_FILE ?? 'seeds/seed_a.json');
// Každý poskytovatel (instance) musí mít vlastní DATA_FILE – soubor drží v paměti jen jeden proces.
const dataFile = resolve(root, process.env.DATA_FILE ?? 'data/db.json');

let store, created, nightsAdded;
try {
  ({ store, created, nightsAdded } = await JsonStore.open(dataFile, { seed: loadSeedFile(seedFile), today: todayInPrague() }));
} catch (err) {
  console.error(`Nelze otevřít data: ${err.message}`);
  process.exit(1);
}

const app = await buildApp({ store, logger: { level: process.env.LOG_LEVEL ?? 'info' }, providerName: process.env.PROVIDER_NAME });
app.log.info(
  created
    ? `Vytvořen ${dataFile} ze seedu ${seedFile} (dostupnost na 120 dní)`
    : `Načten ${dataFile} (doplněno nocí ze seedu ${seedFile}: ${nightsAdded})`,
);

const shutdown = async () => {
  await app.close(); // počká na rozběhnuté požadavky, tedy i na zápisy ve frontě
  process.exit(0);
};
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

try {
  await app.listen({ port, host });
} catch (err) {
  app.log.error(err);
  process.exit(1);
}
