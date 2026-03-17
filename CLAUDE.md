# NorilivingDruckfreigabe – Shopware 6 Plugin

## Was ist das?
Shopware 6.7 Plugin: Druckfreigabe-Seite für Kunden von **pepeprint / noriliving**.
Kunden können ihre Druckdaten ansehen und Druckfreigabe erteilen oder ablehnen.

## Git & Server
- **Lokal:** `/c/Users/micha/.claude/projects/C--Users-micha/b335664f-34e0-4a4d-b33a-fc95530d6855/NorilivingDruckfreigabe/`
- **Server (Produktion):** `dev2.pepeprint.de` → `/var/www/clients/client1/web5/web/custom/plugins/NorilivingDruckfreigabe/`
- **Deploy:** `git push` → auf Server `git pull` + `bin/console cache:clear`

## Wichtige Pfade auf dem Server
- XML-Eingangsdaten: `/media/norilivingdruckfreigabe/som/{orderNumber}_XML.xml`
- Druckfreigabe-Output: `/media/norilivingdruckfreigabe/som-druckfreigabe/druckfreigabe-{orderNumber}.xml`
- Banner-Bild: `/media/norilivingdruckfreigabe/banner-erklaerung.jpg`

## URLs / Routen
| Route | Beschreibung |
|-------|-------------|
| `/druckfreigabe` | Landing: Bestellnummer + PLZ eingeben |
| `/druckfreigabe/{orderNumber}` | Druckfreigabe-Seite (GET = anzeigen, POST = abschicken) |
| `/druckfreigabe/{orderNumber}/verify` | PLZ-Verifikation POST |

## Zugang für Kunden
- **Eingeloggte Kunden:** direkt Zugang
- **Gäste:** PLZ-Verifikation (5 Versuche, dann 15 Min. Sperre)
- Button „Druckfreigabe" erscheint im Kundenaccount nur wenn XML-Datei vorhanden

## Plugin-Konfiguration (Shopware Admin)
- **Erlebniswelt ID:** UUID der CMS-Seite die nach Freigabe angezeigt wird
- Aktuell: `019cf7977caa78b7b2c148439054ef63` (dev2.pepeprint.de)
- Einzustellen unter: Extensions → Noriliving Druckfreigabe → Konfigurieren

## Technische Details
- PHP 8 Attribute-Routing (`#[Route(...)]`)
- `_csrf_protection: false` auf allen Routen
- No-Cache Headers auf allen Druckfreigabe-Seiten
- Erlebniswelt wird per AJAX via `/widgets/cms/{id}` geladen
- Path Traversal Schutz: `preg_match('/^\d+$/', $orderNumber)`
- PLZ Brute-Force Schutz: Session-basiert (5 Versuche, 900s Sperre)

## Bekannte Besonderheiten / Fixes
- **NetzpPowerPack6** dekoriert `SalesChannelCmsPageLoader` → deshalb Erlebniswelt per AJAX statt PHP-Injection
- **sw_csrf** Twig-Funktion existiert in Shopware 6.6+ nicht mehr → `_csrf_protection: false` in Routen
- ZIP für Plugin-Upload: `git archive --format=zip --prefix=NorilivingDruckfreigabe/ HEAD` (Linux-kompatible Pfade)
- Platten-Breiten proportional: `flex: {total_breite} 1 {breite * 0.17 + 152}px` (Scale 0.17 px/mm)
- Grauer Platten-Trenner: `gap: 2px; background: #e0e0e0` auf `.df-plates-row`, weiße `.df-plate-col`

## Dateien (wichtigste)
```
src/
  Storefront/
    Controller/DruckfreigabeController.php   ← Hauptcontroller
    Subscriber/AccountOrderSubscriber.php    ← Button im Kundenaccount
    Subscriber/DruckfreigabeExtension.php   ← Struct für Extension
  Resources/
    config/
      services.xml                           ← Service-Definitionen
      routes.xml                             ← (leer, Routen per Annotation)
      config.xml                             ← Plugin-Konfigurationsfelder
      plugin.png                             ← Plugin-Logo (128x128)
    views/storefront/page/
      druckfreigabe/index.html.twig          ← Haupt-Template
      account/order-history/order-item.html.twig ← Druckfreigabe-Button
composer.json
CLAUDE.md                                    ← Diese Datei
```

## Offene Punkte / Ideen
- Status-Badge in Bestellliste (ausstehend / erteilt / abgelehnt) — noch nicht implementiert
- Sperre nach Freigabe (Output-XML = Sperre) — noch nicht implementiert
- Plugin auf zweitem Dev-Server (Shopware 6.6.10) testen
