# PHP Web Router

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.0-blue.svg)](https://php.net)
[![Apache](https://img.shields.io/badge/Apache-2.4-red.svg)](https://httpd.apache.org)

Ein schlankes PHP-Router-Script für Apache2, das alle Anfragen nach einem konfigurierbaren Regelwerk behandelt – ohne Framework, ohne Composer, ohne Abhängigkeiten.

## Inhalt

- [Features](#features)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Apache-Setup](#apache-setup)
- [Konfiguration](#konfiguration)
- [Regelwerk-Logik](#regelwerk-logik)
- [Pattern-Format](#pattern-format)
- [Logging](#logging)
- [Updates](#updates)
- [Contributing](#contributing)
- [License](#license)

## Features

- Redirects per Konfigurationsdatei, kein Code-Deployment nötig
- Wildcard-Patterns (`*.dev.t71.ch`)
- Vollqualifizierte Host-Regeln (`tools.t71.ch`)
- Subdomain-Shortcuts gegen alle managed Domains (`pad` → `pad.t71.ch`, `pad.7773.ch`, …)
- Automatischer Subdomain-Fallback (`sub.sld.tld` → `sld.tld/sub/`)
- Pfad- und Query-String-Weitergabe konfigurierbar
- Optionales Logging mit automatischer Grössenbegrenzung
- Statische Dateien werden direkt ausgeliefert (kein unnötiger PHP-Overhead)

## Voraussetzungen

- PHP >= 8.0
- Apache 2.4 mit `mod_rewrite`
- `AllowOverride All` im VirtualHost (oder `.htaccess`-Support aktiviert)

## Installation

```bash
git clone https://github.com/youruser/php-webrouter.git /var/www/html
```

`.gitignore` sicherstellen (liegt dem Repo bei):
```
router.conf
router.log
```

> **Hinweis:** `router.conf` ist bewusst nicht im Repo – sie enthält serverspezifische Einträge. Als Vorlage liegt `router.conf.example` bei.

## Apache-Setup

### VirtualHost

```apache
<VirtualHost *:80>
    ServerName t71.ch
    ServerAlias *.t71.ch *.7773.ch 7773.ch *.natureforce.ch natureforce.ch
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`mod_rewrite` aktivieren:
```bash
a2enmod rewrite
systemctl reload apache2
```

### Ohne .htaccess (alternativ direkt im VirtualHost)

```apache
FallbackResource /index.php
```

## Konfiguration

Vorlage kopieren und anpassen:
```bash
cp router.conf.example router.conf
```

```ini
[Settings]
managed_domains   = 7773.ch, t71.ch, natureforce.ch
allowed_redirects = 5
logging           = true
logfile           = ./router.log
maxsize           = 1000

[Redirects]
# pattern|target|type|preserve_path|preserve_query
pad|http://www.t71.ch/pad|302|true|true
tools.t71.ch|https://www.t71.ch/TomsTools|302|true|true
*.dev.t71.ch|https://www.t71.ch/dev|302|false|true
www.t71.ch|https://t71.ch/HomePage|302|false|false
```

### Settings

| Key | Beschreibung | Default |
|-----|-------------|---------|
| `managed_domains` | Kommagetrennte SLD.TLD-Liste | – |
| `allowed_redirects` | Max. Redirect-Hops pro Anfrage | `5` |
| `logging` | Logging aktivieren | `false` |
| `logfile` | Pfad zum Logfile | `./router.log` |
| `maxsize` | Max. Zeilen im Logfile | `1000` |

### Redirect-Felder

```
pattern|target|type|preserve_path|preserve_query
```

| Feld | Werte | Default |
|------|-------|---------|
| `type` | `301` permanent, `302` temporär | `302` |
| `preserve_path` | `true` / `false` | `true` |
| `preserve_query` | `true` / `false` | `true` |

## Regelwerk-Logik

```
Anfrage
  │
  ▼
1. Existiert die Datei/Directory lokal?  →  direkt ausliefern
  │ nein
  ▼
2. Ist der Host in managed_domains?  →  nein → 404
  │ ja
  ▼
3. Config-Redirects (top-down, first match wins)
  │ kein Match
  ▼
4. Subdomain-Fallback:  sub.sld.tld  →  https://sld.tld/sub/
  │ kein Match
  ▼
5. 404
```

## Pattern-Format

| Pattern | Beschreibung | Beispiel |
|---------|-------------|---------|
| `subdomain` | Matcht gegen alle managed_domains | `pad` → `pad.t71.ch`, `pad.7773.ch` |
| `sub.sld.tld` | Exakter Host-Match | `tools.t71.ch` |
| `*.sub.sld.tld` | Wildcard-Prefix | `*.dev.t71.ch` |
| `host/pfad` | Host + Pfad-Prefix | `www.t71.ch/old` |

> Ein leeres Pattern (Catch-all) ist möglich, aber nicht empfohlen – unbekannte Hosts sollten ein 404 erhalten.

## Logging

```
[2024-03-15 14:23:01] SERVE t71.ch/favicon.ico
[2024-03-15 14:23:05] REDIRECT [302] pad.t71.ch/ → http://www.t71.ch/pad (rule: pad)
[2024-03-15 14:23:10] FALLBACK cs.t71.ch/ → https://t71.ch/cs/
[2024-03-15 14:23:15] 404 www.natureforce.ch/index.html
```

Das Logfile wird automatisch auf `maxsize` Zeilen begrenzt – älteste Einträge werden dabei gelöscht.

## Updates

```bash
cd /var/www/html
git pull
```

`router.conf` und `router.log` bleiben unberührt, da sie in `.gitignore` eingetragen sind.

## Contributing

Pull Requests sind willkommen. Für grössere Änderungen bitte zuerst ein Issue eröffnen.

1. Fork erstellen
2. Feature-Branch anlegen (`git checkout -b feature/meine-aenderung`)
3. Änderungen committen (`git commit -m 'Add: meine Änderung'`)
4. Branch pushen (`git push origin feature/meine-aenderung`)
5. Pull Request öffnen

## License

MIT – siehe [LICENSE](LICENSE).

