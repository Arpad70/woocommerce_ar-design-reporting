# AR Design Reporting: Manuál Podle Rolí

Tento manuál popisuje práci s modulem **AR Design Reporting** pro role:
- skladník
- expediční pracovník
- administrátor
- majitel

## 1. Základní workflow objednávky

Nejčastější průchod objednávky:
1. `Čaká sa na platbu` (`pending`) nebo `Spracováva sa` (`processing`)
2. `Na odoslanie` (`na-odoslanie`)
3. `Zabalená` (`zabalena`)
4. `Vybavená` (`vybavena`)

Další používané stavy:
- `Pozastavená` (`on-hold`)
- `Zrušená` (`cancelled`)
- `Neúspěšná` (`failed`)
- `Refundovaná` (`refunded`)

Poznámka: systém umožňuje přechod mezi stavy bez pevné maticové blokace.

## 2. Společná pravidla pro všechny role

- Každá změna objednávky je auditovaná.
- Audit obsahuje kdo změnu provedl, kdy ji provedl a jaký byl přechod stavu.
- Pokud akci provádí jiný uživatel než aktuálně přiřazený, systém objednávku nejdřív automaticky přiřadí tomuto uživateli a až potom provede změnu stavu.
- Mazání objednávek a přesun do koše jsou blokované.
- Místo mazání se používá stav `Zrušená`.

---

## 3. Manuál pro skladníka

### Hlavní odpovědnosti
- převzetí objednávky do práce
- příprava a zabalení objednávky
- signalizace problémů (`Pozastavená`)

### Postup v detailu objednávky
1. Otevřít detail objednávky.
2. V panelu `AR Workflow` kliknout `Prevziať objednávku`.
3. Připravit a zabalit zásilku.
4. Kliknout `Označiť ako Zabalená`.

### Kdy použít `Pozastavená`
- chybí položka
- poškozená položka
- čeká se na doplnění údajů

### Důležité
- Není potřeba ručně potvrzovat přeřazení objednávky.
- Pokud je objednávka přiřazená jinému uživateli, při akci se přeřadí automaticky a tato změna se zapíše do auditu.

---

## 4. Manuál pro expedičního pracovníka

### Hlavní odpovědnosti
- převzetí zabalené objednávky
- vystavení přepravních dokumentů
- uzavření objednávky stavem `Vybavená`

### Postup
1. Filtrovat objednávky ve stavu `Zabalená`.
2. Otevřít detail objednávky.
3. Převzít objednávku (pokud je potřeba).
4. Vygenerovat štítek/dokumenty dopravce.
5. Kliknout `Označiť ako Vybavená`.

### Výjimky
- pokud se zásilka vrátí nebo nedoručí, použít odpovídající stav (např. `Neúspěšná` nebo `Pozastavená`) podle interního procesu.

---

## 5. Manuál pro administrátora

### Hlavní odpovědnosti
- konfigurace modulu
- správa uživatelských oprávnění
- kontrola auditu a exportů
- údržba a aktualizace pluginu

### Klíčová nastavení
1. Ověřit, že role mají správná oprávnění ve WooCommerce.
2. Průběžně kontrolovat auditní záznamy v `AR Reporting`.

### Exporty
- v sekci `Export a emailing` nastavit filtry
- stáhnout CSV pro audit/analýzu

### E-mail digest
- přidat příjemce
- zvolit `daily` nebo `weekly`
- ověřit ručním testem `Odeslat teď`

### Aktualizace
- plugin umí update přes GitHub Releases
- při selhání update proběhne rollback ze zálohy

---

## 6. Manuál pro majitele

### Co sledovat pravidelně
- `Obrat`
- `Čistý obrat`
- `Storná`
- `Priemerný celkový čas procesu`
- `Priemer na zahájenie workflow`
- `Objednávky na zamestnanca`

### Týdenní kontrola
1. Otevřít dashboard za posledních 7 dní.
2. Porovnat KPI s minulým obdobím.
3. Zkontrolovat výkon zaměstnanců.
4. Zkontrolovat auditní přehled (nestandardní zásahy).
5. Exportovat CSV pro archivaci.

### Rozhodovací signály
- roste `Priemer na zahájenie workflow` -> problém v předání objednávek
- roste počet `Pozastavená` -> problém ve skladové dostupnosti
- roste `Neúspěšná` -> problém v expedici/dopravě

---

## 7. Přehled chybových situací a reakce

### „Nelze smazat objednávku"
- očekávané chování
- použít stav `Zrušená`

### Stav se změnil a objednávka má jiného vlastníka
- očekávané chování
- systém nejdřív automaticky změnil přiřazení na uživatele, který akci provedl
- změna přiřazení i změna stavu jsou zaznamenané v auditu

---

## 8. Rychlá mapa akcí podle role

- Skladník: převzetí objednávky, příprava balení, `zabalena`.
- Expedice: dokončení expedice, `vybavena`, řešení výjimek.
- Administrátor: audit, export, digest, update, oprávnění.
- Majitel: KPI, výkon týmu, trendové vyhodnocení.
