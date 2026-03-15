# `hnzio/exif-import`

Kirby-Plugin zum Import von EXIF-, IPTC- und GPS-Metadaten aus Bilddateien. Das Plugin kann Datei-Metadaten anreichern, Geo-Daten auf Seiten spiegeln und optional Story-Titel aus importierten Orts- und Datumsfeldern ableiten.

## Features

- liest EXIF, IPTC und XMP-Fallbacks
- schreibt Alt-Text, Caption, Kamera-, Zeit- und Dateimetadaten in Datei-Sidecars
- uebernimmt GPS-Daten und reverse-geocodete Ortsdaten
- spiegelt Geo-Felder vom Titelbild in Seitenfelder
- optionaler manueller Reimport per Route
- konfigurierbare Story-Titel- und Lizenzlogik

## Installation

Plugin in `site/plugins/exif-import` ablegen oder per Composer installieren:

```bash
composer require hnzio/kirby-exif-import
```

## Konfiguration

Das Plugin verwendet dieselbe Struktur wie die anderen vorbereiteten Plugins:

- `config.sample.php`: Vorlage mit allen Optionen
- `config.php`: lokale Overrides, per `.gitignore` ausgeschlossen

Zum Start:

```bash
cp site/plugins/exif-import/config.sample.php site/plugins/exif-import/config.php
```

Wichtige Optionen:

- `requireGpsOnUpload`
- `requireGpsTemplates`
- `autoSyncOnPageUpdate`
- `autoSyncOnFileUpdate`
- `titleImageFields`
- `pageMirrorMap`
- `storyImport.*`
- `license.*`

Die Werte werden intern als `hnzio.exif.*` gelesen. Alte `hnz.exif.*`-Keys funktionieren weiter als Fallback.

## Verhalten

- Beim Datei-Upload kann das Plugin GPS-Daten pruefen und danach EXIF/IPTC sofort in die Datei-Sidecar schreiben.
- Beim Seiten- oder Datei-Update kann optional ein erneuter Sync laufen.
- Geo-Felder werden nur vom erkannten Titelbild auf die Seite gespiegelt.
- Wenn `storyImport.enabled` aktiv ist, kann bei Story-Seiten automatisch ein passender Titel und Slug erzeugt werden.

## Hinweise

- Reverse Geocoding laeuft ueber Nominatim/OpenStreetMap.
- Ohne `ext-exif` funktionieren Teile des Imports weiter, aber mit deutlich weniger Metadaten.
- Die Route `exif-import/run` ist fuer manuelle Reimporte gedacht und setzt einen eingeloggten Kirby-User voraus.

## Lizenz

MIT
