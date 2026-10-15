# Basis Login PHP/MySQL

Login-Grundgerüst mit Installationsassistent, Profil, Benutzer- und Gruppenverwaltung sowie einem Modulmanager.

## Voraussetzungen

- PHP 8.1+ mit pdo_mysql, mbstring, Sessions und ZipArchive
- MySQL 5.7+ oder MariaDB 10.3+
- Leere Datenbank; PHP darf während Installation/Upgrade Tabellen anlegen und das Admin-Konto markieren
- Schreibrecht für private/ während Installation und Modulverwaltung

## Update der vorherigen Version

1. Datenbank und private/config.php sichern.
2. Neue Anwendungsdateien hochladen, private/config.php beibehalten.
3. Einmal die Seite aufrufen. Die Anwendung ergänzt die Datenbank automatisch um Gruppen-, Administrator- und Modulmanager-Tabellen.
4. Für diesen ersten Aufruf benötigt das DB-Konto ALTER- und CREATE-Rechte. Nach erfolgreicher Migration können diese Rechte entzogen werden; der Modulmanager benötigt CREATE/ALTER/DROP nur, wenn Modul-Hooks Datenbanktabellen verwalten.

Vorhandene Benutzerkonten und Passwörter bleiben erhalten. Falls noch kein Konto als Administrator markiert ist, wird das zuerst angelegte Konto zum Administrator.

## Module installieren und verwalten

Administratoren öffnen links den Modulmanager und laden ein ZIP-Paket hoch. Ein Paket mit einem neuen slug wird installiert. Ein Paket mit bereits installiertem slug aktualisiert das Modul, wenn die Version höher ist. Zum Deinstallieren verwendet der Manager delete.php. Moduldateien werden in private/modules gespeichert und sind nicht direkt über den Webserver abrufbar.

PHP-Module führen Servercode mit den Rechten der Anwendung aus. Installiere nur Pakete, deren Quelle und Code du vertraust.

### Paketstruktur

Ein ZIP enthält die folgenden Dateien im Archivstamm:

- module.json
- install.php
- update.php
- delete.php
- mindestens ein Frontend im Format frontend_ID_name.php
- optionale PHP-, CSS-, Bild- oder andere Ressourcendateien

module.json benennt das Modul, seine Version, den kurzen Datenbankpräfix und jedes Frontend. Beispiel:

{
  "slug": "demo-probe",
  "name": "Demo: Modulprobe",
  "version": "1.0.0",
  "db_prefix": "dmo",
  "frontends": [
    {"id": 1, "name": "Übersicht", "file": "frontend_1_uebersicht.php"},
    {"id": 2, "name": "Kompaktansicht", "file": "frontend_2_kompakt.php"}
  ]
}

slug bleibt bei Updates identisch. version muss im Format Hauptversion.Nebenversion.Patchversion angegeben und bei jedem Update erhöht werden. db_prefix besteht aus 1–3 Kleinbuchstaben/Ziffern und beginnt mit einem Buchstaben. Er muss bei Updates unverändert bleiben.

### Hook-Schnittstelle

install.php, update.php und delete.php erhalten das Objekt $moduleContext. Verfügbare Eigenschaften:

- pdo: PDO-Verbindung zur Anwendungsdatenbank
- tablePrefix: globaler Tabellenpräfix + Modulpräfix + Unterstrich, z. B. school_dmo_
- globalPrefix, slug, moduleName, version, oldVersion, user, moduleDir, assetUrl und für Frontends csrfToken

Hooks geben bei Erfolg nichts zurück. Ein explizites false signalisiert einen Fehler. install.php richtet die Modultabellen ein, update.php führt nötige Schemaänderungen durch, delete.php entfernt die Moduldaten. Der Manager löscht Moduldateien und Registrierung erst nach erfolgreichem delete.php. Frontends können optionale CSS-/JS-/Bilddateien über assetUrl einbinden; diese Dateien werden nur nach Anmeldung ausgeliefert. Ein Frontend, das POST-Anfragen verarbeitet, muss csrfToken als hidden-Feld mitsenden und csrf() aufrufen. Die Hooks sind nicht sandboxed.

Modul-Tabellen müssen den Wert aus tablePrefix verwenden. Der Anwendungspräfix plus Modulpräfix muss innerhalb des MySQL-Limits für Tabellennamen bleiben.

### Frontends und Gruppen

Bei der Installation wird Frontend 1 (bzw. das Frontend mit der kleinsten ID) als Standard für alle vorhandenen Gruppen gespeichert. Für neue Gruppen wird diese Auswahl ebenfalls automatisch angelegt. Im Modulmanager kann der Admin je Modul und Benutzergruppe das Frontend festlegen.

Installierte Module erscheinen links in der Navigation. Frontends werden nur angemeldeten Benutzern ausgeliefert. Gehört ein Benutzer mehreren Gruppen mit unterschiedlichen Frontend-Auswahlen an, gilt die Gruppe mit der kleinsten Gruppen-ID. Benutzer ohne Gruppen sehen das erste Frontend.

## Datenbank und Datenschutz

Die Module werden in der globalen Datenbank registriert. Eigene Modultabellen beginnen mit globalem Präfix + Modulpräfix (maximal 3 Zeichen) + Unterstrich. Module müssen eigene Tabellen in install.php anlegen und in delete.php entfernen.

HTTP funktioniert zum Testen, überträgt Passwörter und Sitzungsdaten aber unverschlüsselt. Für echte Benutzerkonten HTTPS einrichten. HTTP allein reicht nicht für eine DSGVO-konforme Verarbeitung.

private/config.php enthält die Datenbankzugangsdaten. private/ ist per Apache .htaccess geschützt. Bei Nginx müssen Requests auf /private/ ebenfalls gesperrt werden; sicherer ist ein Konfigurationsordner außerhalb des Webroots.
