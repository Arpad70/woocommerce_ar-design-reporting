# AR Design Reporting - Products Module

Samostatný doplnkový plugin pre `ar-design-reporting`.

## Čo robí

- Vkladá sekciu **Produktový reporting** do dashboardu reportingu.
- Zobrazuje:
  - najpredávanejšie produkty,
  - najvyššie skladové zásoby,
  - predaj v čase (graf),
  - historický sklad produktu (graf + tabuľka).
- Pridáva exporty:
  - `Exportovať produkty (XLSX)`
  - `Exportovať históriu skladu produktu (XLSX)`
- Vytvára vlastnú tabuľku `wp_ard_product_stock_history` a denne robí snapshot zásob.

## Inštalácia

1. Zabaľte celý priečinok `ar-design-reporting-products-module` do ZIP.
2. Vo WordPress: `Pluginy -> Pridať nový -> Nahrať plugin`.
3. Aktivujte plugin.
4. Otvorte `AR Design Reporting` dashboard.

## Odinštalácia

- Deaktivácia pluginu zastaví cron snapshoty.
- Odinštalácia pluginu odstráni tabuľku histórie skladu.

## Požiadavky

- Aktívny hlavný plugin `ar-design-reporting`.
- WooCommerce.
