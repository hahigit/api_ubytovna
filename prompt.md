Vytvoř monorepo se dvěma REST API pro školní úlohu "Plánování výletů". Nejdřív stručně navrhni strukturu a pak rovnou implementuj, otestuj a spusť. Nezastavuj se na schvalování plánu.

## Základní myšlenka
Uživatel si vybere lokalitu a termín. Plánovač výletů osloví všechna nakonfigurovaná API (ubytování, atrakce) a pošle zpět souhrnnou nabídku včetně předpovědi počasí. Uživatel si z nabídky vybere, co chce, a Plánovač vytvoří objednávku a zarezervuje ubytování. Každé napojené API může nabízet jinou lokalitu, takže Plánovač musí umět pracovat s více poskytovateli téže služby (např. dvě API s hotely, dvě API s atrakcemi) a nabídnout uživateli výběr mezi nimi.

## Struktura repozitáře
- planovac-vyletu/ : PHP 8.2+, Slim 4 (nebo čisté PHP s malým routerem, pokud to vyjde jednodušeji), Composer, Guzzle pro HTTP volání
- rezervace-ubytovani/ : Node.js 20+ (ES moduly), Fastify s JSON schématy pro validaci vstupů a @fastify/swagger + @fastify/swagger-ui pro automatickou dokumentaci na /docs, data v SQLite (better-sqlite3) nebo v paměti se seedem, spouštění přes npm start
- mocks/atrakce-mock/ : malý Fastify server, který simuluje cizí "Lokální atrakce API" podle kontraktu níže. Spouští se vícekrát na různých portech s různými daty, aby šlo testovat více poskytovatelů.
- docker-compose.yml (volitelné, pokud nezkomplikuje běh) a hlavní README.md v češtině s postupem spuštění a příklady curl volání.

Všechna API vrací JSON v UTF-8, data jsou ve formátu ISO (YYYY-MM-DD), chyby mají jednotný tvar {"error": {"code": "...", "message": "..."}} se správnými HTTP kódy (400 validace, 404 nenalezeno, 409 kolize/nedostupnost, 502/504 chyba navazujícího API). Zapni CORS. Popisy služeb a chybové hlášky piš česky, názvy polí a kód anglicky.

## API 1: Rezervace ubytování (Node.js/Fastify, port 8001)
Zodpovědnost: databáze hotelů a jejich kapacit.

Endpointy:
- GET / : popis služby ({"service": "Rezervace ubytování API", "version": "1.0", "endpoints": [...], "localities": [...]}).
- POST /search-hotels : vstup city, date_from, date_to, people, volitelně price_min, price_max, currency (CZK|EUR, výchozí CZK). Vrátí hotely v daném městě, které mají aspoň jeden pokoj s capacity_persons >= people a volný ve VŠECH nocích intervalu (date_from je první noc, date_to je den odjezdu, tedy se nepočítá). U každého pokoje vrať i total_price_czk a total_price_eur za celý pobyt a ceny po nocích. Cenový filtr se aplikuje na celkovou cenu.
- POST /make-reservation : vstup hotel_id, room_id, date_from, date_to, people, guest_name, guest_email. Ověř existenci, kapacitu a dostupnost všech nocí. Při úspěchu vytvoř rezervaci (reservation_id, status "confirmed", celková cena, údaje o hostovi) a odeber tyto noci z dostupnosti, aby šel jeden pokoj rezervovat jen jednou (ošetři souběh, např. transakcí v SQLite). Při nedostupnosti vrať 409.
- Navíc GET /reservations/{reservation_id} pro ověření.

Formát dat hotelů (drž přesně tuto strukturu):
{"service": "Rezervace ubytování API", "version": "1.0", "hotels": [{"hotel_id": "HOTEL-PRG-01", "name": "Hotel Grand Praha", "location": "Praha", "available_rooms": [{"room_id": "R101", "room_type": "Standard Deluxe", "capacity_persons": 2, "availability": [{"date": "2026-06-15", "price_czk": 2100, "price_eur": 85.0}]}]}]}

Seed data (výchozí soubor seed_a.json):
- HOTEL-PRG-01, Hotel Grand Praha, Praha: R101 Standard Deluxe, 2 os., 2100 CZK / 85 EUR (některé noci 2350 / 95); R205 Executive Suite, 4 os., 3700 CZK / 150 EUR
- HOTEL-BRN-01, Hotel Petrov, Brno: R12 Dvoulůžkový pokoj, 2 os., 1600 CZK / 65 EUR
- HOTEL-KRU-01, Penzion Růže, Český Krumlov: A1 Rodinné Apartmá, 3 os., 1850 CZK / 75 EUR

Vzorová data ze zadání mají termíny v červnu 2026, což je v minulosti. Seed proto negeneruj ze statických dat, ale skript při prvním startu vytvoří dostupnost pro následujících 120 dní od dnešního data (o víkendech lehce vyšší cena, ať jsou ceny po nocích různé). Přidej i druhý seed (seed_b.json) s jinou lokalitou a jinými hotely (např. Olomouc, Plzeň), a cestu k seedu načítej z proměnné prostředí SEED_FILE a port z PORT (process.env). Tak se stejný kód spustí jako dva různé poskytovatele ubytování (porty 8001 a 8002).

## API 2: Plánovač výletů (PHP, port 8000)
Zodpovědnost: orchestrace ostatních API, objednávka výletu.

Konfigurace (config.php nebo .env): seznam napojených API ve tvaru {id, type: "accommodation"|"attractions", name, base_url, timeout}. Výchozí stav: dvě ubytování (localhost:8001, localhost:8002) a dvě atrakce (localhost:8101, localhost:8102). Přidání dalšího API musí znamenat jen přidání řádku do konfigurace.

Endpointy:
- GET / : popis služby včetně seznamu napojených API. U každého zjisti živě (paralelně, krátký timeout) jeho status a lokality, které nabízí (volá se jejich GET /). Nedostupné API označ "status": "unavailable" a nenech kvůli němu spadnout celý endpoint.
- GET /locality/{city}/{date_from}/{date_to}?people=N : souhrnná nabídka pro lokalitu a termín. Paralelně (Guzzle Pool nebo curl_multi) zavolej všechna nakonfigurovaná API příslušného typu: ubytování přes POST /search-hotels, atrakce přes GET /attractions/date/?city={city}. Vrať JSON s částmi accommodation, attractions a weather. Každá nabídka nese pole source (id poskytovatele), aby poznal uživatel, od koho nabídka je, a aby šlo později rezervovat u správného API. Nabídky téže služby od více poskytovatelů zůstávají vedle sebe (nesluč je), seskupené podle source. Pokud některé API selže nebo vyprší timeout, zbytek vrať a chybu uveď v poli partial_failures [{source, reason}]. Cizí data nikdy neignoruj tiše.
- Počasí: volej Open-Meteo (bez API klíče). Nejdřív geocoding (https://geocoding-api.open-meteo.com/v1/search?name=...&count=1&language=cs), potom denní předpověď (https://api.open-meteo.com/v1/forecast?latitude=..&longitude=..&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum&timezone=Europe/Prague&start_date=..&end_date=..). Open-Meteo dává předpověď jen asi 16 dní dopředu. Pro termíny mimo rozsah vrať weather s "available": false a krátkým českým vysvětlením. Výsledky počasí cachuj (soubor nebo APCu) na 30 minut. Pokud počasí selže, nabídka se vrací bez něj.
- POST /plan-trip : vytvoří objednávku. Vstup: city, date_from, date_to, people, customer {name, email}, attractions: [{source, id}], accommodation: {source, hotel_id, room_id} (volitelné). Postup: zvaliduj vstup, ověř že source existuje a je správného typu, pošli POST /make-reservation vybranému poskytovateli ubytování, atrakce přidej do objednávky jako položky (atrakční API nemá rezervační endpoint, jde jen o evidenci) a spočítej celkovou cenu (CZK i EUR, ceny atrakcí krát počet lidí, pokud is_free není true). Objednávku ulož (SQLite nebo JSON soubor) a vrať order_id, položky, celkovou cenu a stav ("confirmed", nebo "failed" s důvodem, když rezervace ubytování neprošla; v tom případě objednávku nepotvrzuj).
- Navíc GET /orders/{order_id}.

## Kontrakt cizího API Lokální atrakce (pro mock i klienta)
- GET / : {"service": "Lokální atrakce API", "version": "1.0", "localities": [...]}
- GET /attractions/date/?city=Praha : seznam atrakcí pro město.
Formát: {"service": "Lokální atrakce API", "version": "1.0", "attractions": [{"id": "ATTR-PRG-01", "name": "Karlov most", "location": "Praha", "type": "outdoor", "pricing": {"price_czk": 0, "price_eur": 0.0, "is_free": true}}]}
Seed pro první mock (port 8101): ATTR-PRG-01 Karlov most (outdoor, zdarma), ATTR-PRG-02 Národní muzeum (indoor, 350 CZK / 14 EUR), ATTR-PRG-03 Pražský hrad - Okruh (outdoor, 450 / 18), ATTR-BRN-01 Hrad Špilberk (outdoor, 200 / 8), ATTR-BRN-02 Vila Tugendhat (indoor, 400 / 16), ATTR-KRU-01 Státní hrad a zámek Český Krumlov (outdoor, 300 / 12). Druhý mock (port 8102) má jiná data a jiné lokality (např. Olomouc, Plzeň).
Plánovač musí zvládnout, že poskytovatel pro dané město nic nemá (prázdný seznam, ne chyba).

## Kvalita a ověření
- Každý endpoint ošetři proti špatnému vstupu (chybějící pole, date_to <= date_from, people < 1, neznámé město, neplatné datum).
- Napiš testy: Vitest + Fastify inject (nebo supertest) pro ubytování (hledání, kapacita, rezervace, dvojitá rezervace -> 409) a PHPUnit pro Plánovač (agregace s částečným výpadkem poskytovatele, výpočet ceny, plan-trip).
- Přidej skript run_all.sh (nebo docker compose), který spustí všechny služby (plánovač, 2x ubytování, 2x mock atrakcí).
- Na závěr spusť všechny služby, projdi celý scénář přes curl (GET / -> GET /locality/... -> POST /plan-trip -> ověření rezervace u ubytování, včetně pokusu o druhou rezervaci téhož pokoje) a ukaž, že vše funguje. Chyby oprav, nepřeskakuj je.
- README.md: jak spustit, přehled endpointů s ukázkovými požadavky a odpověďmi, jak přidat další poskytovatele.
