-- Datenbankschema der Fuhrpark-Verwaltung
--
-- ACHTUNG: Das Skript löscht die gesamte Datenbank „fuhrpark“ samt Inhalt und
-- legt sie neu an. Gedacht für die Entwicklung, nicht für echte Daten.
--
-- Im Anschluss die Stammdaten einspielen: datenbank/stammdaten.sql

-- Wichtig für Umlaute in Auto- und Benutzernamen
SET NAMES utf8mb4;

-- Datenbank komplett löschen und neu erstellen
DROP DATABASE IF EXISTS fuhrpark;

CREATE DATABASE fuhrpark
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fuhrpark;


-- Nutzer
CREATE TABLE nutzer (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vorname       VARCHAR(50)  NOT NULL,
    nachname      VARCHAR(50)  NOT NULL,
    rolle         ENUM('mitarbeiter', 'fuhrparkleiter') NOT NULL DEFAULT 'mitarbeiter',
    passwort_hash VARCHAR(255) NOT NULL,           -- Ergebnis von password_hash()
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Führerscheine
CREATE TABLE nutzer_fuehrerscheine (
    nutzer_id INT UNSIGNED NOT NULL,    -- welcher Nutzer
    klasse    VARCHAR(5)   NOT NULL,    -- z. B. 'B', 'C1', 'A1'
    PRIMARY KEY (nutzer_id, klasse),
    CONSTRAINT fk_fuehrerscheine_nutzer -- Referenz auf Nutzer
        FOREIGN KEY (nutzer_id) REFERENCES nutzer (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fahrzeuge
CREATE TABLE fahrzeuge (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    kennzeichen   VARCHAR(20)       NOT NULL,      
    art           ENUM('auto', 'roller', 'fahrrad') NOT NULL,
    typ           VARCHAR(30)       NOT NULL,      -- Kombi, Transporter, E-Auto …
    hersteller    VARCHAR(50)       NOT NULL,
    modell        VARCHAR(50)       NOT NULL,
    baujahr       SMALLINT UNSIGNED NULL,
    bild          VARCHAR(100)      NULL,          -- Dateiname in assets/img/, NULL = Platzhalter
    status        ENUM('verfuegbar', 'wartung') NOT NULL DEFAULT 'verfuegbar',
    sitzplaetze   TINYINT UNSIGNED  NOT NULL, 
    antrieb       ENUM('benzin', 'diesel', 'elektro') NULL,
    kmstand       INT UNSIGNED      NULL,          -- NULL beim Fahrrad
    fuehrerschein VARCHAR(5)        NULL,          -- NULL = kein Führerschein nötig
    tuev          DATE              NULL,          -- Monatserster des fälligen Monats
    PRIMARY KEY (id),
    UNIQUE KEY uq_fahrzeuge_kennzeichen (kennzeichen), -- Kennzeichen müssen einzigartig sein
    CONSTRAINT chk_fahrzeuge_sitzplaetze CHECK (sitzplaetze >= 1) -- min. 1 Sitzplatz
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Buchungen
CREATE TABLE buchungen (
    id                     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    fahrzeug_id            INT UNSIGNED     NOT NULL,  -- welches Fahrzeug
    antragsteller_id       INT UNSIGNED     NOT NULL,  -- wer gebucht hat
    fahrer_id              INT UNSIGNED     NOT NULL,  -- wer fährt, meist dieselbe Person
    start                  DATE             NOT NULL, 
    ende                   DATE             NOT NULL,
    zweck                  VARCHAR(20)      NOT NULL,  -- Kürzel aus Dropdopwn
    personenanzahl         TINYINT UNSIGNED NOT NULL,  -- einschließlich Fahrer
    status                 ENUM('offen', 'genehmigt', 'abgelehnt', 'storniert',
                                'unterwegs', 'abgeschlossen') NOT NULL DEFAULT 'offen',
    beantragt_am           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Bei Roller und Fahrrad bleibt
    -- entscheidung_von NULL, sie sind sofort genehmigt.
    entscheidung_von       INT UNSIGNED     NULL,      -- Fuhrparkleiter
    entscheidung_am        DATETIME         NULL,
    entscheidung_kommentar TEXT             NULL,      -- Pflicht bei Ablehnung

    -- Fahrt beginnen
    begonnen_am            DATETIME         NULL,
    km_start               INT UNSIGNED     NULL,      -- aus fahrzeuge.kmstand
    zustand_start          TEXT             NULL,      -- vorhandene Schäden bei Übernahme

    -- Rückgabe
    zurueckgegeben_am      DATETIME         NULL,
    km_ende                INT UNSIGNED     NULL,
    schaden                BOOLEAN          NOT NULL DEFAULT FALSE,
    bemerkung              TEXT             NULL,      -- Pflicht bei Schaden (PHP)

    PRIMARY KEY (id),
    KEY idx_buchungen_belegung (fahrzeug_id, start, ende),
    KEY idx_buchungen_status (status),
    CONSTRAINT fk_buchungen_fahrzeug                    -- Referenz auf Fahrzeug
        FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeuge (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_buchungen_antragsteller               -- Referenz auf Benutzer (Antragsteller)
        FOREIGN KEY (antragsteller_id) REFERENCES nutzer (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_buchungen_fahrer                      -- Referenz auf Benutzer (Fahrer)
        FOREIGN KEY (fahrer_id) REFERENCES nutzer (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_buchungen_entscheidung_von            -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (entscheidung_von) REFERENCES nutzer (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_buchungen_zeitraum CHECK (ende >= start), -- Ende darf nicht vor Start liegen
    CONSTRAINT chk_buchungen_personenanzahl CHECK (personenanzahl >= 1), -- Min. 1 Person
    CONSTRAINT chk_buchungen_km       CHECK (km_ende >= km_start)  -- NULL besteht die Prüfung
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Höchstens 3 Fotos je Rückgabe, das prüft PHP.
CREATE TABLE schadensfotos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    buchung_id INT UNSIGNED NOT NULL,              -- Buchung
    datei      VARCHAR(50)  NOT NULL,              -- zufälliger Name in uploads/schaeden/
    PRIMARY KEY (id),
    UNIQUE KEY uq_schadensfotos_datei (datei),
    CONSTRAINT fk_schadensfotos_buchung            -- Referenz auf Buchung
        FOREIGN KEY (buchung_id) REFERENCES buchungen (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
