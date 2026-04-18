# AR Design Reporting

HPOS-first WooCommerce reporting plugin pro `ar-design.sk`.

## Izolační principy

- plugin je samostatný modul ve vlastní složce
- nepřepisuje WordPress ani WooCommerce core
- používá vlastní namespace `ArDesign\Reporting`
- používá vlastní tabulky přes `$wpdb->prefix`
- při uninstallu automaticky nemaže data

## Provozní doporučení

- plugin držet ideálně v samostatném repozitáři
- při reinstalaci WordPressu zálohovat pluginovou složku i databázi
- WordPress považovat za host prostředí, ne za zdrojový domov pluginu

## Release a build

- verze pluginu je vedená v `VERSION`
- změny verzí se zapisují do `CHANGELOG.md`
- instalační ZIP se vytváří skriptem `scripts/build-plugin.sh`
- postup releasu je popsaný v `RELEASE.md`
- CI testy pro každý PR běží v GitHub Actions (`.github/workflows/ci.yml`)
- release ZIP a GitHub release se vytváří automaticky po merge do `main`, pokud se změní `VERSION`
- pri pushi branchu so zmenou `VERSION`/`CHANGELOG.md` sa automaticky vytvorí PR do `main`
- plugin umí kontrolovat nové verze z GitHub Releases (`Update URI` + interní updater)
- pri neúspešnej aktualizácii sa plugin pokúsi obnoviť predchádzajúcu verziu z lokálnej zálohy
