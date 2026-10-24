# Nova Upload für Shopware 6

🇬🇧 [English version](README.en.md)

**Datei-Uploads** für Shopware 6.6 und 6.7 – im **Kontaktformular** (z. B. Fotos, Zeichnungen oder 3D-Dateien zu einer Anfrage) und bei **Artikeln** (z. B. das Logo für einen individuellen Artikel).

Die Dateien werden **geschützt im Shop gespeichert**: Sie liegen im privaten Speicher von Shopware, sind über keine Internetadresse erreichbar und lassen sich nur nach der Anmeldung in der Administration herunterladen. Mails enthalten keine Anhänge, nur die Dateinamen und einen Link in die Administration. Abgelaufene Dateien löscht das Plugin automatisch.

## Funktionen

| Funktion | Beschreibung |
|---|---|
| **Kontaktformular** | Shopwares eigenes Kontaktformular (Erlebniswelt-Element „Formular“, Typ Kontakt) bekommt ein Feld „Dateien anhängen“. Pro Verkaufskanal einschaltbar |
| **Artikel** | Pro Artikel einschaltbar (Zusatzfelder): Upload-Feld auf der Produktseite über dem „In den Warenkorb“-Button, optional als **Pflichtfeld**, mit eigener Bezeichnung („Ihr Logo“) und eigenem Hinweis. Derselbe Artikel mit einer anderen Datei wird eine eigene Warenkorb-Position. Der Dateiname steht im Warenkorb, in der Bestellübersicht und in den Bestellungen im Kundenkonto |
| **Pflicht-Upload in Listen** | Artikel mit Pflicht-Upload zeigen in Kategorien und Suche „Details“ statt „In den Warenkorb“ – die Datei wird auf der Produktseite gewählt |
| **Administration** | *Bestellungen → Uploads*: alle Dateien mit Kunde, Betreff bzw. Artikel und Bestellnummer, Suche und Filter, Herunterladen und Löschen. In jeder Bestellung zeigt die Karte **„Hochgeladene Dateien“** die Dateien dieser Bestellung |
| **Mail-Hinweis** | Die Kontaktformular-Mail an den Shop enthält die Dateinamen und einen Link direkt zur Anfrage in der Administration. Eine Kopie an den Kunden bekommt keinen Hinweis |
| **Prüfung** | Anzahl, Größe je Datei und Gesamtgröße werden schon im Browser und noch einmal auf dem Server geprüft. Nur erlaubte Dateitypen (siehe unten) |
| **Download-Bereich im Kundenkonto** | Für freigeschaltete Konten (z. B. Fertigung, Partner) ohne Zugang zur Administration: *Mein Konto → Anfragen & Dateien* zeigt alle Dateien des Verkaufskanals zum Herunterladen. **Nur mit Passwort und Code aus der Authenticator-App** (Plugin [Nova 2FA](https://github.com/DrdotHouse2106/SW6-Nova-2FA)) |
| **Freigabe fürs Sortiment** | Optionales, freiwilliges Häkchen im Kontaktformular: „Teil darf ins Sortiment aufgenommen werden“. Die Zustimmung steht in der Mail, im Download-Bereich und in der Administration |
| **Aufbewahrung** | Automatisches Löschen nach einstellbaren Fristen: Anfragen (Standard 180 Tage), Dateien aus Warenkörben ohne Bestellung (14 Tage), Dateien von Bestellungen (Standard: behalten) |

**Erlaubte Dateitypen:** Bilder (JPG, PNG, GIF, WebP, HEIC, TIFF, BMP, SVG), Grafik (AI, EPS, PSD, CDR), Dokumente (PDF, Word, Excel, OpenDocument, CSV, TXT, RTF), ZIP sowie 3D/CAD (STL, 3MF, OBJ, STEP/STP, IGES/IGS, AMF, PLY, WRL, X3D, DXF, DWG). Programmdateien, Skripte und HTML werden abgelehnt.

## Sicherheit und Datenschutz

- Dateien liegen in Shopwares **privatem Dateisystem** (`files/plugins/nova_upload/`), nicht im öffentlichen Ordner. Es gibt keine öffentliche Adresse zu einer Datei.
- Gespeichert wird **ohne Dateiendung** unter einer zufälligen ID – eine hochgeladene Datei kann auf dem Server nie ausgeführt werden.
- **Herunterladen** nur in der Administration mit dem Recht „Bestellungen ansehen“, **Löschen** mit „Bestellungen löschen“. Dateien werden immer als Download ausgeliefert, nie im Browser geöffnet.
- **Keine Anhänge in Mails** – die Dateien liegen nicht in Postfächern und werden nicht unverschlüsselt weitergeleitet.
- **Download-Bereich** für Konten ohne Admin-Zugang nur mit Passwort **und** Authenticator-Code (Nova 2FA), nur Dateien des eigenen Verkaufskanals, keine öffentlichen Datei-Links.
- **Automatisches Löschen** nach den eingestellten Fristen (Datenminimierung). Unter dem Upload-Feld steht ein kurzer Hinweis für Kunden; in der Datenschutzerklärung sollte der Upload erwähnt werden (Zweck: Bearbeitung der Anfrage bzw. Bestellung, Speicherdauer wie eingestellt).
- Dateinamen werden bereinigt (keine Pfade oder Sonderzeichen).

## Installation

**Mit dem GitHub Plugin-Updater:** *Erweiterungen → Meine Erweiterungen → Plugin-Katalog → Nova Upload → Installieren*.

**Per Konsole:**

```bash
cd <shopware-root>/custom/plugins
git clone https://github.com/DrdotHouse2106/SW6-Nova-Upload.git NovaUpload

cd <shopware-root>
bin/console plugin:refresh
bin/console plugin:install --activate NovaUpload
bin/console theme:compile
bin/console cache:clear
```

- Die Installation legt die Tabelle `nova_upload` und die Zusatzfelder **„Datei-Upload (Nova Upload)“** für Artikel an.
- `theme:compile` übernimmt die Gestaltung des Upload-Feldes ins Theme.
- Für das automatische Löschen müssen Shopwares **Scheduled Tasks** laufen (Standard: Cronjob/Dienst bzw. Admin-Worker).

## Einrichtung

### Kontaktformular

1. *Erweiterungen → Meine Erweiterungen → Nova Upload → Konfigurieren* – oben den Verkaufskanal wählen – **Kontaktformular → Datei-Upload im Kontaktformular** einschalten
2. In der Erlebniswelt der Anfrage-Seite ein Element **Formular** einfügen, Typ **Kontakt**, Empfänger eintragen
3. Cache leeren und eine Testanfrage mit Datei absenden – die Datei erscheint unter *Bestellungen → Uploads*

### Download-Bereich für die Fertigung (ohne Admin-Zugang)

1. Plugin **Nova 2FA** installieren (Plugin-Katalog)
2. Nova Upload → Konfigurieren → Verkaufskanal → **„Download-Bereich im Kundenkonto (mit Nova 2FA)“** einschalten
3. Für die Person ein Kundenkonto im Verkaufskanal anlegen (oder vorhandenes nutzen) → in der Plugin-Konfiguration unter **„Kunden mit Zugriff auf den Download-Bereich“** nach der E-Mail suchen und auswählen → Speichern (alternativ: *Kunden → Kunde → Zusatzfelder „Nova Upload“ → „Zugriff auf Anfragen & Dateien“*). Nova 2FA ab 1.2.3 nötig
4. Die Person meldet sich im Shop an, richtet unter *Mein Konto → Zwei-Faktor-Anmeldung* die Authenticator-App ein und sieht danach *Mein Konto → Anfragen & Dateien*
5. Die Kontaktformular-Mail enthält dann einen Link direkt zur Anfrage im Bereich

### Artikel (z. B. Logo)

1. Artikel öffnen → Tab **Spezifikationen** → Zusatzfelder **„Datei-Upload (Nova Upload)“**
2. **Datei-Upload anbieten** einschalten, bei Bedarf **Datei ist Pflicht**, Bezeichnung (z. B. „Ihr Logo“) und Hinweis (z. B. „Am besten als SVG, PDF oder PNG mit transparentem Hintergrund“)
3. Speichern – Varianten übernehmen die Einstellung vom Hauptartikel, solange sie nicht eigene Werte haben
4. Nach einer Bestellung stehen die Dateien in der Bestellung (Tab **Details** → Karte **Hochgeladene Dateien**) und unter *Bestellungen → Uploads*

## Einstellungen

*Erweiterungen → Meine Erweiterungen → Nova Upload → Konfigurieren* (pro Verkaufskanal):

| Bereich | Einstellung | Standard |
|---|---|---|
| Kontaktformular | Datei-Upload im Kontaktformular | aus |
| | Max. Anzahl Dateien / Größe je Datei / Gesamtgröße | 5 / 10 MB / 25 MB |
| | Hinweis in der Kontaktformular-Mail (Dateinamen + Link) | an |
| | Download-Bereich im Kundenkonto (mit Nova 2FA) | aus |
| | Freiwillige Zustimmung: Teil darf ins Sortiment | aus |
| Artikel | Max. Anzahl Dateien / Größe je Datei / Gesamtgröße | 3 / 20 MB / 50 MB |
| Aufbewahrung | Dateien aus dem Kontaktformular löschen nach | 180 Tagen |
| | Artikel-Dateien ohne Bestellung löschen nach | 14 Tagen |
| | Artikel-Dateien von Bestellungen löschen nach | 0 = behalten |

## Entwicklung

- Storefront-JavaScript ohne Shopware bauen: `cd src/Resources/app/storefront && npm install && npm run build`
- Administration in einer Shopware-Installation bauen: `bin/build-administration.sh`

## Kompatibilität

Shopware 6.6.x und 6.7.x · alle aktuellen Browser. Läuft mit jedem Theme; mit dem Nova Theme passt die Gestaltung automatisch zu den Theme-Farben.

## Häufige Probleme und Lösungen

### Upload: „Datei konnte nicht hochgeladen werden“ oder das Formular hängt

**Ursache:** Die PHP-Grenzen auf dem Server sind kleiner als die Einstellung im Plugin. Ab Werk erlaubt PHP oft nur 2 MB je Datei (`upload_max_filesize`) und 8 MB pro Anfrage (`post_max_size`)

**Lösung:** In der Hosting-Verwaltung (z. B. Plesk → *PHP-Einstellungen* der Domain) `upload_max_filesize` mindestens auf die Größe je Datei und `post_max_size` etwas über die Gesamtgröße setzen (z. B. 20M und 60M)

### Upload-Feld erscheint nicht im Kontaktformular

**Ursache:** Der Upload ist nur für einen anderen Verkaufskanal eingeschaltet, das Formular ist vom Typ „Allgemein“ statt „Kontakt“, oder der Cache ist noch alt

**Lösung:** Schalter im richtigen Verkaufskanal setzen, Formular-Typ **Kontakt** wählen, Cache leeren

### Upload-Feld erscheint doppelt im Kontaktformular

**Ursache:** Das Nova Theme hatte in Version 1.7.x einen eigenen Datei-Anhang im Kontaktformular

**Lösung:** Nova Theme auf mindestens 1.8.0 aktualisieren – dort ist die Funktion entfernt und liegt nur noch in Nova Upload

### Upload-Feld erscheint nicht auf der Produktseite

**Ursache:** Zusatzfeld nicht gesetzt, nur an einer anderen Variante gesetzt, oder der Artikel ist nicht kaufbar (ausverkauft, Hauptartikel mit Varianten)

**Lösung:** Beim Hauptartikel *Spezifikationen → Datei-Upload (Nova Upload) → Datei-Upload anbieten* einschalten und speichern, Cache leeren. Das Feld steht nur dort, wo auch der „In den Warenkorb“-Button steht

### „Anfragen & Dateien“ fehlt im Kundenkonto

**Ursache:** Download-Bereich im Verkaufskanal aus, Kunde weder in der Konfiguration ausgewählt noch Zusatzfeld gesetzt, oder Nova 2FA nicht installiert

**Lösung:** Alle drei prüfen. Ohne eingerichtete Zwei-Faktor-Anmeldung leitet der Bereich zuerst zur Einrichtung (*Mein Konto → Zwei-Faktor-Anmeldung*)

### Zusatzfeld „Nova Upload“ fehlt beim Kunden

**Ursache:** Das Zusatzfeld-Set wird beim Installieren/Aktualisieren angelegt; je nach Shopware-Stand erscheint es beim Kunden nicht

**Lösung:** Ab 1.2.0 nicht mehr nötig – Kunden direkt in der Plugin-Konfiguration unter „Kunden mit Zugriff auf den Download-Bereich“ auswählen

### Dateien werden nicht automatisch gelöscht

**Ursache:** Shopwares Scheduled Tasks laufen nicht

**Lösung:** Prüfen, ob `bin/console scheduled-task:run` und `bin/console messenger:consume` als Cronjob/Dienst laufen (oder der Admin-Worker aktiv ist). Die Aufgabe heißt `nova_upload.cleanup`

## Lizenz

MIT
