#!/usr/bin/env bash
set -euo pipefail
P=http://localhost:8000
CITY="${1:-Praha}"
FROM=$(date -d '+7 days' +%F)
TO=$(date -d '+9 days' +%F)
J='Content-Type: application/json'
jget() { node -e "const d=JSON.parse(require('fs').readFileSync(0,'utf8'));const v=($1);console.log(typeof v==='string'?v:JSON.stringify(v,null,2))"; }
step() { printf '\n\033[1;34m== %s\033[0m\n' "$*"; }

step "1) GET / – stav napojených API"
curl -s $P/ | jget 'd.providers.map(p => `${p.id.padEnd(12)} ${p.type.padEnd(14)} ${p.status.padEnd(12)} ${p.localities.join(", ")}`).join("\n")'

step "2) GET /locality/$CITY/$FROM/$TO?people=2"
OFFER=$(curl -s "$P/locality/$(node -p "encodeURIComponent('$CITY')")/$FROM/$TO?people=2")
echo "$OFFER" | jget '{summary: d.summary, partial_failures: d.partial_failures}'
echo "Ubytování (podle poskytovatele):"
echo "$OFFER" | jget 'd.accommodation.map(g => `  [${g.source}] ${g.status}` + g.hotels.map(h => h.available_rooms.map(r => `\n     ${h.hotel_id}/${r.room_id} ${h.name} – ${r.room_type}, ${r.capacity_persons} os., ${r.total_price_czk} Kč / ${r.total_price_eur} €`).join("")).join("")).join("\n")'
echo "Atrakce:"
echo "$OFFER" | jget 'd.attractions.map(g => `  [${g.source}] ${g.status}` + g.attractions.map(a => `\n     ${a.id} ${a.name} (${a.pricing.is_free ? "zdarma" : a.pricing.price_czk + " Kč/os."})`).join("")).join("\n")'
echo "Počasí:"
echo "$OFFER" | jget 'd.weather ? (d.weather.available ? d.weather.days.map(x => `  ${x.date} ${x.description}, ${x.temperature_min}–${x.temperature_max} °C, srážky ${x.precipitation_sum} mm`).join("\n") : "  " + d.weather.reason) : "  nedostupné"'

ACC=$(echo "$OFFER" | jget '(() => { for (const g of d.accommodation) for (const h of g.hotels) return {source: h.source, hotel_id: h.hotel_id, room_id: h.available_rooms[0].room_id}; return null; })()')
ATTR=$(echo "$OFFER" | jget 'd.attractions.flatMap(g => g.attractions).slice(0, 2).map(a => ({source: a.source, id: a.id}))')
[[ "$ACC" == "null" ]] && { echo "Žádné volné ubytování – zkuste jiné město."; exit 1; }
ORDER_BODY=$(node -e "console.log(JSON.stringify({city: '$CITY', date_from: '$FROM', date_to: '$TO', people: 2,
  customer: {name: 'Jan Novák', email: 'jan.novak@example.cz'}, accommodation: $ACC, attractions: $ATTR}, null, 2))")

step "3) POST /plan-trip"
echo "$ORDER_BODY"
ORDER=$(curl -s -w '\n%{http_code}' -X POST $P/plan-trip -H "$J" -d "$ORDER_BODY")
echo "HTTP $(echo "$ORDER" | tail -1)"
ORDER=$(echo "$ORDER" | sed '$d')
echo "$ORDER" | jget '{order_id: d.order_id, status: d.status, items: d.items.map(i => `${i.type} ${i.source} ${i.reservation_id ?? i.attraction_id} ${i.price_czk} Kč`), total_price: d.total_price}'
ORDER_ID=$(echo "$ORDER" | jget 'd.order_id')
RES_ID=$(echo "$ORDER" | jget 'd.items.find(i => i.type === "accommodation").reservation_id')
SOURCE=$(echo "$ACC" | jget 'd.source')
BASE=$(curl -s $P/ | jget "d.providers.find(p => p.id === '$SOURCE').base_url")

step "4) Ověření rezervace přímo u poskytovatele: GET $BASE/reservations/$RES_ID"
curl -s "$BASE/reservations/$RES_ID" | jget '{reservation_id: d.reservation_id, status: d.status, hotel: d.hotel_name, room: d.room_id, guest: d.guest, total_price_czk: d.total_price_czk}'

step "5) Druhá objednávka téhož pokoje → očekáváno 409 a objednávka ve stavu failed"
SECOND=$(curl -s -w '\n%{http_code}' -X POST $P/plan-trip -H "$J" -d "$(echo "$ORDER_BODY" | sed 's/Jan Novák/Petra Svobodová/')")
echo "HTTP $(echo "$SECOND" | tail -1)"
echo "$SECOND" | sed '$d' | jget '{error: d.error, order: {order_id: d.order.order_id, status: d.order.status}}'

step "6) Totéž přímo na API ubytování → 409"
curl -s -w '\nHTTP %{http_code}\n' -X POST "$BASE/make-reservation" -H "$J" \
  -d "$(echo "$ACC" | jget "({hotel_id: d.hotel_id, room_id: d.room_id, date_from: '$FROM', date_to: '$TO', people: 2, guest_name: 'Petra', guest_email: 'petra@example.cz'})")"

step "7) GET /orders/$ORDER_ID"
curl -s "$P/orders/$ORDER_ID" | jget '{order_id: d.order_id, status: d.status, total_price: d.total_price}'
