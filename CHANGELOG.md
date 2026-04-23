# Changelog

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
