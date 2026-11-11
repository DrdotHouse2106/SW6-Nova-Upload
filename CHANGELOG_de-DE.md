# 1.4.2
- Behoben: Der Link in der Mail zum Kontaktformular (Administration und Download-Bereich) zeigte bei mehreren Dateien nur die **erste** Datei. Jetzt enthält er alle Datei-IDs der Anfrage (mit Komma getrennt), die Liste zeigt alle Dateien dieser Anfrage

# 1.4.1
- Sicherheit: **SVG-Prüfung neu** – SVG wird als XML geparst (ohne Netzwerkzugriff und ohne Entity-Ersetzung) statt per Textsuche und nur angenommen ohne DOCTYPE/Entities, Skripte, `foreignObject`, Animationen, Ereignis-Attribute (`on…`), Verarbeitungsanweisungen und Verweise auf externe Dateien/Adressen. Verschleierungen über Zeichenreferenzen (`&#x61;`) greifen nicht mehr. Abgelehnte SVG bekommen einen eigenen, erklärenden Hinweis
- Sicherheit: Bilder müssen die zum Format passende Signatur und genau den passenden Typ haben (statt beliebigem `image/*` – ein SVG mit Endung .jpg wird abgelehnt); PDF muss mit `%PDF-` beginnen (unbekannte Binärdaten gelten nicht mehr als PDF); SVG-Inhalt wird in anderen Endungen abgelehnt
- Kontingent für Artikel-Uploads: zusätzlich höchstens 200 MB je Besucher (IP) und Stunde (einstellbar); ist die Gesamtgrenze offener Uploads erreicht, werden zuerst offene Dateien älter als 3 Tage vorzeitig gelöscht, sonst wird abgelehnt und die Administration benachrichtigt (höchstens alle 6 Stunden)
- Unit-Tests für die Inhaltsprüfung

# 1.4.0
- Behoben: **Datei-Upload bei Artikeln griff nicht** – die Prüfung lief, bevor Shopware den Verkaufskanal kannte. Jetzt werden Dateien wieder geprüft und gespeichert, und „Datei ist Pflicht“ verhindert das Hinzufügen ohne Datei
- Sicherheit: **Kontingent für Artikel-Uploads** – höchstens 20 Dateien je Besucher (IP) und Stunde, neue Uploads werden abgelehnt, solange Dateien aus Warenkörben ohne Bestellung zusammen 2048 MB belegen (beides einstellbar, 0 = keine Grenze)
- Sicherheit: Upload-Angaben im Warenkorb setzt nur noch der Server; einer Bestellung werden nur Dateien zugeordnet, die für genau diese Position hochgeladen wurden
- Sicherheit: Inhalt wird gegen die Endung geprüft (Bilder, PDF), HTML-/Programminhalte werden abgelehnt, SVG mit Skripten ebenfalls
- Datenschutz: Wird ein Kundenkonto gelöscht, werden dessen Anfragen und Warenkorb-Dateien mitgelöscht (nicht bei Gastkonten und nicht, solange ein anderes Konto die E-Mail-Adresse nutzt); wird eine Bestellung gelöscht, ihre Dateien
- Datenschutz: Dateien von Bestellungen werden standardmäßig nach **730 Tagen** gelöscht. Beim Update wird die Frist nur gesetzt, wenn bisher „0 = behalten“ eingestellt war; eine eigene Frist bleibt
- Aufräumen löscht abgelaufene Dateien jetzt vollständig statt 500 je Lauf; schlägt das Speichern fehl, bleibt keine verwaiste Datei zurück

# 1.3.0
- Neu: **Bezeichnung und Hinweis des Upload-Feldes im Kontaktformular pro Verkaufskanal** einstellbar (z. B. „Fotos vom Einbauort …“ statt des allgemeinen Textes mit 3D-Dateien). Platzhalter `%count%` und `%size%`; leer = Standardtext

# 1.2.0
- Neu: **Kunden mit Zugriff direkt in der Plugin-Konfiguration auswählen** (Feld „Kunden mit Zugriff auf den Download-Bereich“, Suche nach E-Mail) – das Zusatzfeld am Kunden ist nicht mehr nötig, funktioniert aber weiter
- Passend dazu Nova 2FA ab 1.2.3: ausgewählte Kunden können die Zwei-Faktor-Anmeldung einrichten

# 1.1.0
- Neu: **Download-Bereich im Kundenkonto** („Mein Konto → Anfragen & Dateien“) für freigeschaltete Konten – z. B. die Fertigung ohne Zugang zur Administration. Zugang nur mit Passwort **und** Code aus der Authenticator-App (Plugin **Nova 2FA**, Pflicht); nur Dateien des eigenen Verkaufskanals
- Kontaktformular-Mail verlinkt bei eingeschaltetem Bereich direkt dorthin
- Neu: freiwilliges Häkchen „Teil darf ins Sortiment aufgenommen werden“ – Zustimmung in der Mail, im Download-Bereich und unter *Bestellungen → Uploads* sichtbar
- Hinweis unter dem Upload-Feld angepasst

# 1.0.1
- Neues Plugin-Icon im Nova-Stil (Erweiterungen → Meine Erweiterungen)

# 1.0.0
- Erste Version: Datei-Upload im Kontaktformular und bei Artikeln (pro Artikel über Zusatzfelder, optional Pflicht), geschützte Ablage im privaten Dateisystem, Übersicht unter Bestellungen → Uploads und in der Bestellung, Hinweis in der Kontaktformular-Mail, automatisches Löschen nach Fristen
