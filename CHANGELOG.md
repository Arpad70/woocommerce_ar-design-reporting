# Changelog

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
