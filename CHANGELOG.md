# Changelog

## 0.3.12 - 2026-04-25

- KPI karta: zmenšené písmo hodnoty (`ard-kpi-value`) a zmenšené písmo delty (`ard-kpi-delta`) pre proporčne čistejší vzhľad
- doladené aj mobilné veľkosti týchto prvkov


## 0.3.11 - 2026-04-25

- opravené kotvenie `ard-kpi-delta` do pravého dolného rohu celej KPI karty (nie do horného riadku)
- upravené spacing/šírky, aby delta a hodnota neboli v kolízii pri dlhších číslach


## 0.3.10 - 2026-04-25

- KPI delta je zarovnaná do pravého dolného rohu diagonálnej časti karty
- KPI label je obmedzený na ľavú časť karty a pri dlhom texte sa skracuje (ellipsis), aby nikdy nepretekol do split oblasti


## 0.3.9 - 2026-04-25

- KPI karty upravené do vizuálu podľa referencie: diagonálne rozdelenie, farebné pozadie a výrazná delta hodnota
- vylepšený business layout pre lepšiu čitateľnosť trendu priamo v dashboarde


## 0.3.8 - 2026-04-25

- dashboard KPI rozšírené o segmenty: celkové/vybavené/čakajúce objednávky, obraty a priemerné hodnoty objednávok
- pridané porovnanie období s výchozím nastavením na rovnaký dátum minulého roka
- KPI karty upravené do business layoutu s percentuálnou zmenou (delta) voči porovnávaciemu obdobiu


## 0.3.7 - 2026-04-25

- release obnovuje kontinuitu automatických aktualizací ve WordPressu po nasazení změn dashboardu
- přehled objednávek: menší text, zalamování obsahu a paginator (výchozí 10 řádků)

## 0.3.6 - 2026-04-25

- sekce `Prehľad objednávok` má upravený layout tabulky (menší text, zalamování a responsivní wrapper), aby obsah nepřetékal
- přidán paginator pro `Prehľad objednávok` s ovládáním předchozí/další stránka
- výchozí počet řádků na stránku je nastaven na 10



## 0.3.5 - 2026-04-24

- dashboard filtry mají nově výchozí období od začátku aktuálního měsíce do dnešního dne
- Přehled výkonu se po otevření dashboardu načte automaticky pro aktuální kalendářní měsíc

## 0.3.4 - 2026-04-24

- KPI Snapshot: `Objednávky započítané do KPI` nyní zahrnují pouze objednávky s auditovatelnou workflow stopou
- historické objednávky bez workflow auditu se již do KPI počtu nezapočítávají

## 0.3.3 - 2026-04-24

- KPI Snapshot: `Počet objednávek` je nyní skutečný celkový počet WooCommerce objednávek za období ve filtru
- KPI Snapshot: `Objednávky započítané do KPI` nyní počítají objednávky, které přispívají do jakékoli KPI metriky

## 0.3.2 - 2026-04-23

- obnova objednávky ze stavu `Zrušená` do aktivních workflow stavů je nově povolena pouze oprávněným rolím (`manager/shop_manager`, `owner`, `admin`)
- neoprávněný pokus o obnovu je zablokován, stav objednávky se vrátí a událost se zapíše do auditu (`order_cancelled_restore_not_allowed`)

## 0.3.1 - 2026-04-23

- reporting dashboard nyní zobrazuje obchodní číslo objednávky i interní ID (`#číslo (ID xxx)`), aby nedocházelo k záměně
- přidána ochrana proti automatickému rušení nezaplacených objednávek WooCommerce cronem pro platební metody `dobírka (cod)` a `bankový prevod (bacs)`

## 0.3.0 - 2026-04-18

- rozšírené workflow na meranie celého procesu vybavenia objednávky podľa stavov
- akcia `Označiť ako Na odoslanie` teraz mení priamo WooCommerce status objednávky
- doplnený dashboard o prehľad objednávok, výkon zamestnancov a auditný súhrn
- doplnené KPI: obrat, čistý obrat, storna, AOV, priemerný čas spracovania, objednávky na zamestnanca
- CSV export rozšírený o detailné údaje objednávky, zodpovedné osoby a poznámky
- pridaná ochrana proti trvalému mazaniu objednávok (blokovanie hard delete)

## 0.2.0 - 2026-03-28

- přidán HPOS-first skeleton pluginu
- doplněna základní databázová vrstva a service container
- přidány safe-boot požadavky pro WordPress, PHP a WooCommerce
- doplněny první workflow akce `take over` a `finish processing`
- přidána dokumentace pro izolaci modulu a bezpečný provoz
- připraven build základ pro samostatné verzování a ZIP distribuci
