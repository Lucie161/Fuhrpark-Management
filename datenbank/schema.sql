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
    art           ENUM('auto', 'transporter', 'roller', 'fahrrad') NOT NULL,
    typ           VARCHAR(30)       NOT NULL,      -- Kombi, Transporter, E-Auto …
    hersteller    VARCHAR(50)       NOT NULL,
    modell        VARCHAR(50)       NOT NULL,
    baujahr       SMALLINT UNSIGNED NULL,
    bild          VARCHAR(100)      NULL,          -- Dateiname in assets/img/, NULL = Platzhalter
    sitzplaetze   TINYINT UNSIGNED  NOT NULL, 
    antrieb       ENUM('benzin', 'diesel', 'elektro') NULL,
    kmstand       INT UNSIGNED      NULL,          -- NULL beim Fahrrad
    fuehrerschein VARCHAR(5)        NULL,          -- NULL = kein Führerschein nötig
    hu_au         DATE              NULL,          -- nächste HU/AU, Monatserster des fälligen Monats
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

    -- Fahrt beginnen; vorhandene Schäden stehen in der Tabelle schaeden
    begonnen_am            DATETIME         NULL,
    km_start               INT UNSIGNED     NULL,      -- aus fahrzeuge.kmstand

    -- Rückgabe; neue Schäden stehen in der Tabelle schaeden
    zurueckgegeben_am      DATETIME         NULL,
    km_ende                INT UNSIGNED     NULL,
    bemerkung              TEXT             NULL,      -- Notiz zum Zustand, optional

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

-- Schäden
CREATE TABLE schaeden (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    buchung_id   INT UNSIGNED NOT NULL,                    -- bei welcher Fahrt gemeldet
    anlass       ENUM('rueckgabe', 'uebernahme') NOT NULL,
    schwere      ENUM('klein', 'wartung') NOT NULL,        -- 'wartung' sperrt das Fahrzeug
    beschreibung TEXT         NOT NULL,
    gemeldet_am  DATETIME     NOT NULL,
    behoben_am   DATETIME     NULL,                        -- NULL = noch offen
    behoben_von  INT UNSIGNED NULL,                        -- Fuhrparkleiter
    PRIMARY KEY (id),
    KEY idx_schaeden_offen (behoben_am),
    CONSTRAINT fk_schaeden_buchung                         -- Referenz auf Buchung
        FOREIGN KEY (buchung_id) REFERENCES buchungen (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_schaeden_behoben_von                     -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (behoben_von) REFERENCES nutzer (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_schaeden_behoben                        -- behoben: Zeitpunkt und Person, sonst keins von beiden
        CHECK ((behoben_am IS NULL) = (behoben_von IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schadensfotos
CREATE TABLE schadensfotos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schaden_id INT UNSIGNED NOT NULL,              -- Schaden
    datei      VARCHAR(50)  NOT NULL,              -- zufälliger Name in uploads/schaeden/
    PRIMARY KEY (id),
    UNIQUE KEY uq_schadensfotos_datei (datei),
    CONSTRAINT fk_schadensfotos_schaden            -- Referenz auf Schaden
        FOREIGN KEY (schaden_id) REFERENCES schaeden (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Wartungen
-- Ein Fahrzeug ist in Wartung, solange es eine Zeile ohne freigegeben_am
-- gibt (höchstens eine je Fahrzeug, das prüft PHP). Abgeschlossene Wartungen
-- bleiben als Verlauf und erscheinen im Fahrtenbuch.
CREATE TABLE wartungen (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fahrzeug_id         INT UNSIGNED NOT NULL,
    schaden_id          INT UNSIGNED NULL,               -- ausgelöst durch einen Schaden
    grund               VARCHAR(200) NOT NULL,           -- z. B. „HU/AU“, Beschreibung des Schadens
    begonnen_am         DATE         NOT NULL,
    voraussichtlich_bis DATE         NULL,               -- NULL = Ende noch offen (nach Rückgabe)
    angelegt_von        INT UNSIGNED NULL,               -- Fuhrparkleiter, NULL bei Rückgabe mit Schaden
    freigegeben_am      DATE         NULL,               -- NULL = läuft noch
    freigegeben_von     INT UNSIGNED NULL,               -- Fuhrparkleiter
    PRIMARY KEY (id),
    KEY idx_wartungen_laufend (fahrzeug_id, freigegeben_am),
    CONSTRAINT fk_wartungen_fahrzeug                     -- Referenz auf Fahrzeug
        FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeuge (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_wartungen_schaden                      -- Referenz auf Schaden
        FOREIGN KEY (schaden_id) REFERENCES schaeden (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_wartungen_angelegt_von                 -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (angelegt_von) REFERENCES nutzer (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_wartungen_freigegeben_von              -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (freigegeben_von) REFERENCES nutzer (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_wartungen_bis                         -- Ende nicht vor dem Beginn
        CHECK (voraussichtlich_bis >= begonnen_am),
    CONSTRAINT chk_wartungen_freigegeben                 -- Freigabe: Tag und Person, sonst keins von beiden
        CHECK ((freigegeben_am IS NULL) = (freigegeben_von IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
