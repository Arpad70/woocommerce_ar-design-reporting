# Správný postup release přes Git

Tento postup je pro plugin `ar-design-reporting` a workflow:
- `.github/workflows/auto-pr-version.yml`
- `.github/workflows/release.yml`

## 1. Příprava změn

1. Udělej požadované změny v kódu.
2. Ověř lokálně:

```bash
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
php scripts/verify-version-consistency.php
php tests/run-integration-tests.php
```

## 2. Bump verze (povinné pro nový release)

Změň verzi konzistentně ve 3 místech:
- `VERSION`
- `ar-design-reporting.php` (`Version:` v headeru)
- `ar-design-reporting.php` (`ARD_REPORTING_VERSION`, `ARD_REPORTING_DB_VERSION`)

Doplň nový záznam do `CHANGELOG.md`.

Znovu ověř:

```bash
php scripts/verify-version-consistency.php
```

## 3. Git workflow (doporučené)

### Varianta A: branch + PR

1. Vytvoř branch, např. `release/0.3.16`.
2. Commitni release soubory (`VERSION`, `CHANGELOG.md`, `ar-design-reporting.php`) + související změny.
3. Pushni branch na GitHub.
4. `auto-pr-version.yml` má vytvořit PR automaticky.

Pokud auto-PR selže na oprávnění (`GitHub Actions is not permitted to create or approve pull requests`), vytvoř PR ručně:

```bash
gh pr create --base main --head release/0.3.16 --title "chore: release 0.3.16"
```

5. Po schválení mergni PR do `main`.

### Varianta B: přímý push na `main`

Použij jen pokud je to interně schválené. `release.yml` se spustí také při pushi do `main`.

## 4. Automatický release

Po merge/pushi do `main` workflow `release.yml`:
1. spustí lint + integrační testy,
2. ověří konzistenci verzí,
3. publikuje nový tag `vX.Y.Z` (jen když se změnil `VERSION`),
4. vytvoří GitHub Release,
5. přiloží artefakt `ar-design-reporting.zip`.

## 5. Ověření po release

Zkontroluj:

```bash
gh run list --workflow "Release" --limit 3
gh release view vX.Y.Z
```

Musí existovat:
- tag `vX.Y.Z`
- release `AR Design Reporting vX.Y.Z`
- asset `ar-design-reporting.zip`

## 6. Produkční upgrade

Pro produkci používej ZIP z GitHub Release assetu:
- `https://github.com/Arpad70/woocommerce_ar-design-reporting/releases/download/vX.Y.Z/ar-design-reporting.zip`

Nepoužívej lokální build ZIP, pokud cílem je reprodukovatelný release z CI.
