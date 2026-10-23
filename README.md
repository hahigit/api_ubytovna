# Plánování výletů – monorepo

Školní úloha: **Plánovač výletů** osloví všechna nakonfigurovaná API (ubytování, atrakce), přidá předpověď počasí z Open-Meteo a vrátí souhrnnou nabídku. Uživatel si z ní vybere a Plánovač vytvoří objednávku a u správného poskytovatele zarezervuje ubytování.

```
                    ┌──────────────────────────────┐
  klient ──HTTP──▶  │ Plánovač výletů (PHP, :8000) │──▶ Open-Meteo (geocoding + předpověď, cache 30 min)
                    └──┬──────────┬─────────┬──────┘
           paralelně   │          │         │
      ┌────────────────┘          │         └───────────────┐
      ▼                           ▼                         ▼
 Ubytování A :8001        Ubytování B :8002        Atrakce A :8101 / B :8102
 (Node/Fastify, seed_a)   (stejný kód, seed_b)     (mock cizího API)
```

| Adresář | Technologie | Port | Popis |
|---|---|---|---|
| `planovac-vyletu/` | PHP 8.2+, Slim 4, Guzzle, JSON soubor | 8000 | orchestrace, objednávky |
| `rezervace-ubytovani/` | Node.js 20+, Fastify 5, JSON soubor, Swagger | 8001, 8002 | hotely, dostupnost, rezervace |
| `mocks/atrakce-mock/` | Node.js, Fastify | 8101, 8102 | simulace „Lokální atrakce API“ |

Poskytovatelé mají záměrně různé lokality a **Praha je u obou**, aby šlo vidět, že Plánovač nabídky téže služby od více poskytovatelů nesloučí, ale nechá je vedle sebe (každá nese `source`).

| Poskytovatel | Lokality |
|---|---|
| `ubytovani-a` (seed_a.json) | Praha, Brno, Český Krumlov |
| `ubytovani-b` (seed_b.json) | Olomouc, Plzeň, Praha |
| `atrakce-a` (attractions_a.json) | Praha, Brno, Český Krumlov |
| `atrakce-b` (attractions_b.json) | Olomouc, Plzeň, Praha |

## Spuštění

Potřeba: **Node.js ≥ 20**, **PHP ≥ 8.2** (rozšíření `curl`, `mbstring`; `pcntl` jen pro jeden test), **Composer**, `curl`.

```bash
./run_all.sh      # nainstaluje chybějící závislosti, spustí 5 služeb na pozadí, počká, až naběhnou
./demo.sh         # projde celý scénář přes curl (volitelně: ./demo.sh Olomouc)
./stop_all.sh     # zastaví vše
```

Logy jsou v `logs/<služba>.log`. Cestu k PHP lze předat přes `PHP_BIN=/cesta/k/php ./run_all.sh`.

> PHP bez práv správce: stačí statický binár ze [static-php-cli](https://dl.static-php.dev/static-php-cli/common/)
> (např. `php-8.4.x-cli-linux-x86_64.tar.gz`) rozbalit do `~/.local/bin` a Composer stáhnout jako
> `composer.phar` tamtéž.

Jednotlivě:

```bash
cd rezervace-ubytovani && npm install
npm start                                                                # seeds/seed_a.json → data/db.json, port 8001
SEED_FILE=seeds/seed_b.json DATA_FILE=data/db_b.json PORT=8002 npm start  # druhý poskytovatel

cd mocks/atrakce-mock && npm install
DATA_FILE=data/attractions_a.json PORT=8101 npm start   # u mocku je DATA_FILE jen ke čtení (katalog atrakcí)
DATA_FILE=data/attractions_b.json PORT=8102 npm start
DELAY_MS=3000 PORT=8103 npm start                    # pomalý poskytovatel pro test timeoutu

cd planovac-vyletu && composer install
php -S 0.0.0.0:8000 -t public public/index.php
```

Docker Compose není součástí – bez něj je běh jednodušší a `run_all.sh` pokryje totéž.

### Testy

```bash
cd rezervace-ubytovani && npm test        # Vitest + Fastify inject (35 testů)
cd planovac-vyletu && vendor/bin/phpunit  # PHPUnit 11 (42 testů)
```

* **Ubytování:** vytvoření `DATA_FILE` ze seedu, víkendové ceny, přežití restartu, atomický zápis, poškozený soubor, rollback při chybě zápisu, hledání, kapacita, cenový filtr, obsazená noc uprostřed pobytu, rezervace, dvojitá a překrývající se rezervace → 409, 20 souběžných požadavků → právě jeden projde, souběžné překrývající se termíny → žádná noc dvakrát, validace vstupů, 404, Swagger, CORS.
* **Plánovač:** agregace s výpadkem a timeoutem poskytovatele, prázdný seznam není chyba, neplatná cizí data se hlásí, počasí (cache, částečný rozsah, výpadek), neznámé město → 404, výpočet ceny, `plan-trip` (rezervace u správného poskytovatele, 409 / 502 / 504, neznámý nebo špatný `source`, neexistující atrakce), HTTP vrstva přes Slim, úložiště objednávek (JSON, poškozený soubor, 8 souběžných procesů × 15 zápisů bez ztráty).
  Testy Plánovače nepotřebují běžící služby – HTTP se podvrhuje přes Guzzle handler.

---

## Společné konvence

* JSON v UTF-8, data ve formátu `YYYY-MM-DD`, CORS zapnutý.
* `date_from` = první noc, `date_to` = den odjezdu (nepočítá se). Pobyt max. 30 nocí, 1–20 osob, příjezd nesmí být v minulosti.
* Chyby mají jednotný tvar (u validace navíc `details` se všemi chybami):

```json
{ "error": { "code": "VALIDATION_ERROR", "message": "Chybí povinné pole 'city'.", "details": ["Chybí povinné pole 'city'."] } }
```

| HTTP | Kdy | Příklady `code` |
|---|---|---|
| 400 | chybný vstup | `VALIDATION_ERROR`, `INVALID_JSON`, `INVALID_DATE_RANGE`, `DATE_IN_PAST`, `UNKNOWN_SOURCE`, `SOURCE_TYPE_MISMATCH` |
| 404 | nenalezeno | `CITY_NOT_FOUND`, `HOTEL_NOT_FOUND`, `ROOM_NOT_FOUND`, `RESERVATION_NOT_FOUND`, `ORDER_NOT_FOUND`, `ATTRACTION_NOT_FOUND`, `NOT_FOUND` |
| 409 | kolize / nedostupnost | `ROOM_NOT_AVAILABLE`, `CAPACITY_EXCEEDED`, `ACCOMMODATION_RESERVATION_FAILED` |
| 502 / 504 | chyba / timeout navazujícího API | `UPSTREAM_ERROR`, `ACCOMMODATION_RESERVATION_FAILED` |

---

## API 1: Rezervace ubytování (`:8001`, `:8002`)

Dokumentace Swagger UI: <http://localhost:8001/docs> (OpenAPI JSON na `/docs/json`).

| Metoda | Cesta | Popis |
|---|---|---|
| GET | `/` | popis služby, endpointy, lokality |
| GET | `/hotels?city=` | hotely a volné noci ve formátu ze zadání |
| POST | `/search-hotels` | hledání volných pokojů |
| POST | `/make-reservation` | rezervace pokoje |
| GET | `/reservations/{reservation_id}` | detail rezervace |

Proměnné prostředí:

| Proměnná | Výchozí | Popis |
|---|---|---|
| `DATA_FILE` | `data/db.json` | JSON soubor s hotely, dostupností a rezervacemi (složka se vytvoří sama) |
| `SEED_FILE` | `seeds/seed_a.json` | seed, ze kterého se `DATA_FILE` vytvoří, pokud neexistuje |
| `PORT` | `8001` | port |
| `HOST`, `PROVIDER_NAME`, `LOG_LEVEL` | `0.0.0.0`, –, `info` | |

`run_all.sh` používá `data/db_a.json` (port 8001) a `data/db_b.json` (port 8002). Relativní cesty se berou vůči složce `rezervace-ubytovani/`. **Každá instance musí mít vlastní `DATA_FILE`**: data drží v paměti jeden proces.

**Data a seed:** při startu se `DATA_FILE` načte do paměti. Pokud neexistuje, vytvoří se ze seedu (`seeds/seed_*.json`, u pokoje je navíc `pricing` s cenou ve všední den a o víkendu) s dostupností na **120 dní od dneška**, noci pá a so jsou dražší. Při dalších startech se okno dostupnosti jen posune, chybějící budoucí noci se doplní. Existující noci ani rezervace se nepřepisují a rezervované noci se do dostupnosti nevrátí. Poškozený soubor služba odmítne načíst a nepřepíše ho. Pro čistý stav stačí smazat `rezervace-ubytovani/data/`.

Soubor má strukturu hotelů ze zadání a navíc rezervace. Rezervované noci z `availability` zmizí:

```json
{
  "service": "Rezervace ubytování API", "version": "1.0",
  "hotels": [ { "hotel_id": "HOTEL-PRG-01", "name": "Hotel Grand Praha", "location": "Praha",
                "available_rooms": [ { "room_id": "R101", "room_type": "Standard Deluxe", "capacity_persons": 2,
                                       "availability": [ { "date": "2026-11-02", "price_czk": 2100, "price_eur": 85 } ] } ] } ],
  "reservations": { "RES-20261030-C49A5DD5": { "reservation_id": "…", "hotel_id": "…", "room_id": "…", "price_per_night": [ "…" ], "…": "…" } }
}
```

### POST /search-hotels

```bash
curl -s -X POST localhost:8001/search-hotels -H 'Content-Type: application/json' \
  -d '{"city":"Praha","date_from":"2026-10-30","date_to":"2026-11-01","people":2,"price_max":6000,"currency":"CZK"}'
```

Volitelné: `price_min`, `price_max` (filtr na **celkovou** cenu pobytu), `currency` `CZK|EUR` (výchozí CZK). Město se porovnává bez ohledu na velikost písmen a diakritiku. Vrací jen pokoje s `capacity_persons >= people`, které jsou volné ve **všech** nocích.

```json
{
  "service": "Rezervace ubytování API",
  "version": "1.0",
  "query": { "city": "Praha", "date_from": "2026-10-30", "date_to": "2026-11-01", "people": 2, "nights": 2, "currency": "CZK", "price_max": 6000 },
  "hotels": [
    {
      "hotel_id": "HOTEL-PRG-01",
      "name": "Hotel Grand Praha",
      "location": "Praha",
      "available_rooms": [
        {
          "room_id": "R101",
          "room_type": "Standard Deluxe",
          "capacity_persons": 2,
          "availability": [
            { "date": "2026-10-30", "price_czk": 2350, "price_eur": 95 },
            { "date": "2026-10-31", "price_czk": 2350, "price_eur": 95 }
          ],
          "nights": 2,
          "total_price_czk": 4700,
          "total_price_eur": 190
        }
      ]
    }
  ]
}
```

Město, které poskytovatel vůbec nemá → `404 CITY_NOT_FOUND`. Město má, ale nic volného → `200` s prázdným `hotels`.

### POST /make-reservation

```bash
curl -s -X POST localhost:8001/make-reservation -H 'Content-Type: application/json' -d '{
  "hotel_id":"HOTEL-PRG-01","room_id":"R101","date_from":"2026-10-30","date_to":"2026-11-01",
  "people":2,"guest_name":"Jan Novák","guest_email":"jan.novak@example.cz"}'
```

`201 Created`:

```json
{
  "reservation_id": "RES-20261030-C49A5DD5",
  "status": "confirmed",
  "hotel_id": "HOTEL-PRG-01",
  "hotel_name": "Hotel Grand Praha",
  "location": "Praha",
  "room_id": "R101",
  "room_type": "Standard Deluxe",
  "date_from": "2026-10-30",
  "date_to": "2026-11-01",
  "nights": 2,
  "people": 2,
  "guest": { "name": "Jan Novák", "email": "jan.novak@example.cz" },
  "total_price_czk": 4700,
  "total_price_eur": 190,
  "price_per_night": [
    { "date": "2026-10-30", "price_czk": 2350, "price_eur": 95 },
    { "date": "2026-10-31", "price_czk": 2350, "price_eur": 95 }
  ],
  "created_at": "2026-10-23T09:55:24.049Z"
}
```

Znovu tentýž pokoj (nebo překrývající se termín) → `409`:

```json
{ "error": { "code": "ROOM_NOT_AVAILABLE", "message": "Pokoj 'R101' není v požadovaném termínu volný (obsazené noci: 2026-10-30, 2026-10-31).", "details": ["2026-10-30", "2026-10-31"] } }
```

**Souběh a ukládání:** všechny zápisy jdou přes jednu serializovanou frontu (promise chain), takže dvě rezervace se nikdy nezpracují zároveň a druhá už obsazené noci nenajde. Každá změna:

1. pracuje nad kopií dat,
2. nejdřív ověří hotel, pokoj, kapacitu a volné noci (při 404/409 se nic nezmění, ani soubor),
3. odebere noci z dostupnosti a přidá rezervaci,
4. zapíše soubor atomicky: `db.json.tmp` → `fsync` → `rename` na `db.json`,
5. teprve po úspěšném zápisu se kopie stane aktuálními daty. Když zápis selže, vrátí se 500 a data v paměti zůstanou původní.

Ověřeno 10 souběžnými curl na stejný pokoj: 1× 201, 9× 409.

### GET /reservations/{id}

```bash
curl -s localhost:8001/reservations/RES-20261030-C49A5DD5
```

Vrací totéž co `make-reservation`, neexistující → `404 RESERVATION_NOT_FOUND`.

---

## Kontrakt „Lokální atrakce API“ (mock `:8101`, `:8102`)

```bash
curl -s localhost:8101/
# {"service":"Lokální atrakce API","version":"1.0","localities":["Brno","Český Krumlov","Praha"]}

curl -s 'localhost:8101/attractions/date/?city=Praha'
```

```json
{
  "service": "Lokální atrakce API",
  "version": "1.0",
  "attractions": [
    { "id": "ATTR-PRG-01", "name": "Karlov most", "location": "Praha", "type": "outdoor", "pricing": { "price_czk": 0, "price_eur": 0.0, "is_free": true } },
    { "id": "ATTR-PRG-02", "name": "Národní muzeum", "location": "Praha", "type": "indoor", "pricing": { "price_czk": 350, "price_eur": 14.0, "is_free": false } }
  ]
}
```

Pro město bez atrakcí vrací prázdný seznam, chybějící `city` → 400. `DELAY_MS` zpomalí odpovědi.

---

## API 2: Plánovač výletů (`:8000`)

| Metoda | Cesta | Popis |
|---|---|---|
| GET | `/` | popis služby a živý stav všech poskytovatelů |
| GET | `/locality/{city}/{date_from}/{date_to}?people=N` | souhrnná nabídka (`people` výchozí 1) |
| POST | `/plan-trip` | objednávka + rezervace ubytování |
| GET | `/orders/{order_id}` | detail objednávky |

Proměnné prostředí: `DATA_FILE` (výchozí `data/orders.json`, soubor s objednávkami), `CACHE_DIR` (`var/cache`, cache počasí), `PROVIDERS_JSON` (přepis seznamu poskytovatelů).

### GET /

Všichni poskytovatelé se zavolají paralelně (`GET /`, timeout 1,5 s). Nedostupný má `"status": "unavailable"` a důvod, ostatní fungují dál.

```json
{
  "service": "Plánovač výletů API",
  "version": "1.0",
  "endpoints": [ "…" ],
  "localities": ["Brno", "Olomouc", "Plzeň", "Praha", "Český Krumlov"],
  "providers": [
    { "id": "ubytovani-a", "type": "accommodation", "name": "Ubytování A (Praha, Brno, Český Krumlov)", "base_url": "http://localhost:8001", "timeout": 3.0,
      "status": "available", "remote_service": "Rezervace ubytování API", "localities": ["Brno", "Praha", "Český Krumlov"] },
    { "id": "atrakce-b", "type": "attractions", "name": "Lokální atrakce B", "base_url": "http://localhost:8102", "timeout": 2.0,
      "status": "unavailable", "localities": [], "reason": "Služba je nedostupná (nelze se připojit)." }
  ]
}
```

### GET /locality/{city}/{date_from}/{date_to}?people=N

```bash
curl -s 'localhost:8000/locality/Praha/2026-10-30/2026-11-01?people=2'
curl -s 'localhost:8000/locality/%C4%8Cesk%C3%BD%20Krumlov/2026-10-30/2026-11-01?people=3'   # Český Krumlov
```

Všechna API se volají **paralelně** (Guzzle `requestAsync` + `Promise\Utils::settle`, pod tím curl_multi), počasí také. Celková doba odpovědi tak odpovídá nejpomalejšímu poskytovateli, ne jejich součtu.

Zkrácená odpověď (atrakce-b je vypnutá):

```json
{
  "service": "Plánovač výletů API",
  "query": { "city": "Praha", "date_from": "2026-10-30", "date_to": "2026-11-01", "people": 2, "nights": 2 },
  "summary": { "hotels": 2, "rooms": 3, "attractions": 3, "providers_queried": 4, "providers_failed": 1 },
  "accommodation": [
    { "source": "ubytovani-a", "provider_name": "Ubytování A (…)", "status": "ok", "message": null,
      "hotels": [ { "source": "ubytovani-a", "hotel_id": "HOTEL-PRG-01", "name": "Hotel Grand Praha", "location": "Praha",
                    "available_rooms": [ { "room_id": "R101", "room_type": "Standard Deluxe", "capacity_persons": 2, "nights": 2,
                                           "total_price_czk": 4700, "total_price_eur": 190.0, "availability": [ "…" ] } ] } ] },
    { "source": "ubytovani-b", "provider_name": "Ubytování B (…)", "status": "ok", "message": null,
      "hotels": [ { "source": "ubytovani-b", "hotel_id": "HOTEL-PRG-B1", "name": "Pension Vltava Residence", "…": "…" } ] }
  ],
  "attractions": [
    { "source": "atrakce-a", "provider_name": "Lokální atrakce A", "status": "ok", "message": null,
      "attractions": [ { "source": "atrakce-a", "id": "ATTR-PRG-02", "name": "Národní muzeum", "location": "Praha", "type": "indoor",
                         "pricing": { "price_czk": 350, "price_eur": 14.0, "is_free": false },
                         "price_for_group": { "unit_price_czk": 350, "unit_price_eur": 14.0, "quantity": 2, "price_czk": 700, "price_eur": 28.0 } } ] },
    { "source": "atrakce-b", "provider_name": "Lokální atrakce B", "status": "error", "message": "Služba je nedostupná (nelze se připojit).", "attractions": [] }
  ],
  "weather": {
    "available": true,
    "provider": "Open-Meteo",
    "location": { "name": "Praha", "country": "Česko", "latitude": 50.08804, "longitude": 14.42076 },
    "days": [ { "date": "2026-10-30", "weathercode": 45, "description": "Mlha", "temperature_max": 11.3, "temperature_min": 5.1, "precipitation_sum": 0 } ],
    "units": { "temperature": "°C", "precipitation": "mm" },
    "cached": false
  },
  "partial_failures": [
    { "source": "atrakce-b", "type": "attractions", "reason": "Služba je nedostupná (nelze se připojit)." }
  ]
}
```

* Každá skupina má `status`: `ok`, `empty` (poskytovatel lokalitu nenabízí nebo nemá nic volného; nejde o chybu) nebo `error`.
* `partial_failures` obsahuje výpadky, timeouty (`"Vypršel časový limit 2 s."`), chybné HTTP odpovědi, nevalidní JSON i jednotlivé vynechané položky s neplatným formátem. Cizí data se tedy nikdy nezahazují potichu.
* **Počasí:** geocoding → denní předpověď, obojí se cachuje v souborech (`var/cache`) na 30 minut (`"cached": true`). Termín za hranicí 16denní předpovědi vrátí `{"available": false, "reason": "Předpověď počasí je k dispozici jen 16 dní dopředu (do …)"}`. Termín, který hranici přesahuje, se ořízne (`"partial": true`). Když Open-Meteo selže, `weather` je `null` a chyba je v `partial_failures` (`source: "open-meteo"`). Nabídka se vrátí i tak.
* Město, které nenabízí žádný poskytovatel a nezná ho ani geocoding → `404 CITY_NOT_FOUND`. Existující město bez nabídky (např. Ostrava) → `200` s prázdnými skupinami.

### POST /plan-trip

```bash
curl -s -X POST localhost:8000/plan-trip -H 'Content-Type: application/json' -d '{
  "city": "Praha", "date_from": "2026-10-30", "date_to": "2026-11-01", "people": 2,
  "customer": { "name": "Jan Novák", "email": "jan.novak@example.cz" },
  "accommodation": { "source": "ubytovani-b", "hotel_id": "HOTEL-PRG-B1", "room_id": "V3" },
  "attractions": [
    { "source": "atrakce-a", "id": "ATTR-PRG-01" },
    { "source": "atrakce-a", "id": "ATTR-PRG-03" },
    { "source": "atrakce-b", "id": "B-PRG-01" }
  ]
}'
```

Postup:
1. validace vstupu (všechny chyby najednou),
2. každý `source` musí existovat v konfiguraci a mít správný typ (400 `UNKNOWN_SOURCE` / `SOURCE_TYPE_MISMATCH`),
3. ceny atrakcí se načtou znovu přímo od poskytovatelů, protože cenám od klienta nevěříme (neexistující atrakce → 404, výpadek → 502/504; v obou případech se nic nerezervuje),
4. `POST /make-reservation` u vybraného poskytovatele ubytování,
5. výpočet ceny: ubytování + Σ atrakce × počet osob (kromě `is_free: true`), v CZK i EUR,
6. uložení objednávky do JSON souboru `DATA_FILE` (výchozí `planovac-vyletu/data/orders.json`, složka se vytvoří sama). Zápis probíhá pod exkluzivním zámkem `flock(LOCK_EX)` na `orders.json.lock`: načte se aktuální obsah, přidá se objednávka a vše se atomicky zapíše přes `orders.json.tmp` + `rename`. Zámek je na zvláštním souboru, protože `rename` mění inode datového souboru. Souběžné zápisy z více PHP procesů (`PHP_CLI_SERVER_WORKERS`) se tak navzájem nepřepíšou.

`201 Created`:

```json
{
  "order_id": "ORD-20261023-A304894D",
  "status": "confirmed",
  "created_at": "2026-10-23T11:55:24+02:00",
  "city": "Praha", "date_from": "2026-10-30", "date_to": "2026-11-01", "nights": 2, "people": 2,
  "customer": { "name": "Jan Novák", "email": "jan.novak@example.cz" },
  "items": [
    { "type": "accommodation", "source": "ubytovani-b", "provider_name": "Ubytování B (…)", "reservation_id": "RES-20261030-87E04065",
      "hotel_id": "HOTEL-PRG-B1", "hotel_name": "Pension Vltava Residence", "room_id": "V3", "room_type": "Apartmá s výhledem na řeku",
      "date_from": "2026-10-30", "date_to": "2026-11-01", "nights": 2, "status": "confirmed", "price_czk": 6400, "price_eur": 260.0 },
    { "type": "attraction", "source": "atrakce-a", "attraction_id": "ATTR-PRG-01", "name": "Karlov most", "is_free": true, "status": "registered",
      "unit_price_czk": 0, "unit_price_eur": 0.0, "quantity": 2, "price_czk": 0, "price_eur": 0.0, "…": "…" },
    { "type": "attraction", "source": "atrakce-a", "attraction_id": "ATTR-PRG-03", "name": "Pražský hrad - Okruh", "is_free": false, "status": "registered",
      "unit_price_czk": 450, "unit_price_eur": 18.0, "quantity": 2, "price_czk": 900, "price_eur": 36.0, "…": "…" },
    { "type": "attraction", "source": "atrakce-b", "attraction_id": "B-PRG-01", "name": "Petřínská rozhledna", "is_free": false, "status": "registered",
      "unit_price_czk": 220, "unit_price_eur": 9.0, "quantity": 2, "price_czk": 440, "price_eur": 18.0, "…": "…" }
  ],
  "total_price": { "czk": 7740, "eur": 314.0 }
}
```

Když rezervace ubytování neprojde, objednávka se **nepotvrdí**: uloží se se stavem `failed` (atrakce `cancelled`) a vrátí se spolu s chybou. Kód je 409, pokud poskytovatel odmítl (obsazeno, kapacita, neexistující pokoj), 502 při chybě poskytovatele a 504 při timeoutu.

```json
{
  "error": { "code": "ACCOMMODATION_RESERVATION_FAILED",
             "message": "Rezervace ubytování se nezdařila: HTTP 409: Pokoj 'V3' není v požadovaném termínu volný (obsazené noci: 2026-10-30, 2026-10-31)." },
  "order": { "order_id": "ORD-20261023-0772F9E8", "status": "failed", "failure_reason": "…", "items": [ "…" ], "total_price": { "…": "…" } }
}
```

`accommodation` je volitelné (objednávka jen s atrakcemi), ale aspoň jedna položka být musí.

### GET /orders/{order_id}

```bash
curl -s localhost:8000/orders/ORD-20261023-A304894D
```

Vrací uloženou objednávku (i `failed`), neexistující → `404 ORDER_NOT_FOUND`.

---

## Jak přidat dalšího poskytovatele

1. Spusťte službu, která dodržuje příslušný kontrakt, např. třetí ubytování s vlastním seedem:
   ```bash
   cd rezervace-ubytovani && SEED_FILE=seeds/seed_c.json DATA_FILE=data/db_c.json PORT=8003 npm start
   ```
2. Přidejte **jeden řádek** do `planovac-vyletu/config.php`:
   ```php
   ['id' => 'ubytovani-c', 'type' => 'accommodation', 'name' => 'Ubytování C', 'base_url' => 'http://localhost:8003', 'timeout' => 3.0],
   ```
   `type` je `accommodation` (kontrakt Rezervace ubytování API) nebo `attractions` (kontrakt Lokální atrakce API), `timeout` je v sekundách.

Nic dalšího se neupravuje. Plánovač nového poskytovatele zahrne do `GET /`, do nabídek i do `plan-trip`. Bez úpravy souboru lze seznam přepsat proměnnou `PROVIDERS_JSON` (JSON pole se stejnou strukturou).

## Struktura

```
├── run_all.sh / stop_all.sh / demo.sh
├── planovac-vyletu/
│   ├── config.php                 # poskytovatelé, Open-Meteo, cesty
│   ├── public/index.php           # vstupní bod (php -S)
│   ├── src/
│   │   ├── Bootstrap.php          # sestavení aplikace z konfigurace
│   │   ├── Http/AppFactory.php    # routy Slim, chyby, CORS
│   │   ├── Service/OfferAggregator.php   # GET /locality – paralelní agregace
│   │   ├── Service/TripPlanner.php       # POST /plan-trip
│   │   ├── Service/ProviderGateway.php   # klient kontraktů + validace cizích dat
│   │   ├── Service/WeatherService.php    # Open-Meteo + cache
│   │   ├── Service/HttpJson.php          # async JSON přes Guzzle, české důvody chyb
│   │   ├── Service/OrderRepository.php  # objednávky v JSON (flock + atomický zápis)
│   │   ├── Service/FileCache.php, UpstreamException.php
│   │   └── Validator.php, PriceCalculator.php, ProviderRegistry.php, Provider.php, Dates.php, ApiException.php
│   └── tests/                     # PHPUnit
├── rezervace-ubytovani/
│   ├── seeds/seed_a.json, seed_b.json
│   ├── data/                      # runtime data (db_a.json, db_b.json) – v .gitignore
│   ├── src/server.js (start), app.js (routy), store.js (JSON úložiště + seed), schemas.js, errors.js, dates.js
│   └── test/app.test.js           # Vitest
└── mocks/atrakce-mock/
    ├── server.js
    └── data/attractions_a.json, attractions_b.json
```
