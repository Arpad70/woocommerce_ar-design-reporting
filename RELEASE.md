# Release Process

## Samostatné verzování

1. uprav `VERSION`
2. uprav verzi v `ar-design-reporting.php`
3. uprav `ARD_REPORTING_VERSION` a `ARD_REPORTING_DB_VERSION`
4. doplň záznam do `CHANGELOG.md`
5. merge PR do `main`

CI i release workflow validují konzistenci verzí (`VERSION`, plugin header a konstanty) skriptem:

```bash
php scripts/verify-version-consistency.php
```

Po merge do `main` se automaticky spustí workflow `.github/workflows/release.yml`.
Pokud se v commitu změnil soubor `VERSION`, workflow:

- spustí lint a integrační testy,
- vytvoří tag `v<version>`,
- vytvoří GitHub Release,
- přiloží instalační asset `ar-design-reporting.zip`.

## Lokální build ZIP balíčku (volitelně)

```bash
bash scripts/build-plugin.sh
```

Výstup lokálně:

- ZIP se vytvoří do `build/`
- název souboru bude `ar-design-reporting-<version>.zip`

## Doporučený deployment

1. zazálohuj databázi
2. v produkci nech WordPress detekovat novou verzi pluginu z GitHub release
3. spusť standardní aktualizaci pluginu v administraci
4. zkontroluj dashboard modulu a DB verzi
