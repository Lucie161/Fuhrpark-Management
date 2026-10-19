-- Datenbankschema der Fuhrpark-Verwaltung
--
-- ACHTUNG: Das Skript löscht die gesamte Datenbank „fuhrpark“ samt Inhalt und
-- legt sie neu an. Gedacht für die Entwicklung, nicht für echte Daten.
--
-- Im Anschluss die Stammdaten einspielen: datenbank/stammdaten.sql
--
-- Bewusst nur die Grundlagen (Feedback vom 15.10.2026): Prüfungen von Werten
-- (Zeitraum, km-Stand usw.) macht der PHP-Code, nicht die Datenbank, damit sie
-- nicht an zwei Stellen gepflegt werden. Fremdschlüssel ohne ON DELETE und
-- ON UPDATE, also RESTRICT: Nichts wird über mehrere Tabellen hinweg
-- mitgeändert oder mitgelöscht. Nutzer und Fahrzeuge werden nicht gelöscht,
-- sondern bekommen ein geloescht_am.

-- Wichtig für Umlaute in Auto- und Benutzernamen
SET NAMES utf8mb4;

-- Datenbank komplett löschen
DROP DATABASE IF EXISTS fuhrpark;

-- Datenbank neu erstellen
CREATE DATABASE fuhrpark
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Datenbank verwenden
USE fuhrpark;


-- ---------------------------------------------------------------------------
-- Schlüsseltabellen
--
-- Feste Wertelisten statt ENUM. Die IDs sind fest vergeben, denn der PHP-Code
-- vergleicht mit gleichnamigen Konstanten (includes/konstanten.php), z. B.
-- STATUS_UNTERWEGS = 3. Neue Werte deshalb immer hier und dort eintragen.
-- ---------------------------------------------------------------------------

-- Rollen
CREATE TABLE rollen (
    id          TINYINT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(30)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO rollen (id, bezeichnung) VALUES
    (1, 'Mitarbeiter'),
    (2, 'Fuhrparkleiter');

-- Fahrzeugarten
CREATE TABLE fahrzeugarten (
    id          TINYINT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(30)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fahrzeugarten (id, bezeichnung) VALUES
    (1, 'Auto'),
    (2, 'Transporter'),
    (3, 'Roller'),
    (4, 'Fahrrad'),
    (5, 'Luxusauto');        -- nur mit nutzer.darf_luxus buchbar

-- Antriebe
CREATE TABLE antriebe (
    id          TINYINT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(30)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO antriebe (id, bezeichnung) VALUES
    (1, 'Benzin'),
    (2, 'Diesel'),
    (3, 'Elektro');

-- Buchungsstatus, geordnet nach dem Ablauf: 1–3 laufend (belegen das
-- Fahrzeug), 4–6 erledigt. So geht auch status_id <= 3 statt einer Liste.
CREATE TABLE buchungsstatus (
    id          TINYINT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(30)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO buchungsstatus (id, bezeichnung) VALUES
    (1, 'beantragt'),        -- STATUS_OFFEN
    (2, 'genehmigt'),
    (3, 'unterwegs'),
    (4, 'abgeschlossen'),
    (5, 'abgelehnt'),
    (6, 'storniert');

-- Zwecke einer Fahrt
CREATE TABLE zwecke (
    id          TINYINT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(30)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO zwecke (id, bezeichnung) VALUES
    (1, 'Montage'),
    (2, 'Wartung/Service'),
    (3, 'Kundentermin'),
    (4, 'Aufmaß'),
    (5, 'Materialtransport'),
    (6, 'Lieferantenbesuch'),
    (7, 'Schulung'),
    (8, 'Sonstiges');

-- Wann ein Schaden gemeldet wurde
CREATE TABLE schadensanlaesse (
    id          TINYINT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(30)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schadensanlaesse (id, bezeichnung) VALUES
    (1, 'bei Übernahme'),
    (2, 'bei Rückgabe');

-- Schwere eines Schadens; 2 sperrt das Fahrzeug
CREATE TABLE schweregrade (
    id          TINYINT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(30)      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schweregrade (id, bezeichnung) VALUES
    (1, 'Kleinschaden'),
    (2, 'Schaden');          -- mit Wartung


-- ---------------------------------------------------------------------------
-- Daten
-- ---------------------------------------------------------------------------

-- Nutzer
CREATE TABLE nutzer (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    vorname       VARCHAR(50)      NOT NULL,
    nachname      VARCHAR(50)      NOT NULL,
    rolle_id      TINYINT UNSIGNED NOT NULL DEFAULT 1, -- Mitarbeiter
    passwort_hash VARCHAR(255)     NOT NULL,           -- Ergebnis von password_hash()
    darf_luxus    TINYINT(1)       NOT NULL DEFAULT 0, -- 1 = darf Luxusautos fahren (art_id 5)
    geloescht_am  DATETIME         NULL,               -- NULL = aktiv; sonst keine Anmeldung, nicht mehr wählbar
    PRIMARY KEY (id),
    CONSTRAINT fk_nutzer_rolle                         -- Referenz auf Rolle
        FOREIGN KEY (rolle_id) REFERENCES rollen (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Führerscheine
CREATE TABLE nutzer_fuehrerscheine (
    nutzer_id INT UNSIGNED NOT NULL,    -- welcher Nutzer
    klasse    VARCHAR(5)   NOT NULL,    -- z. B. 'B', 'C1', 'A1'
    PRIMARY KEY (nutzer_id, klasse),
    CONSTRAINT fk_fuehrerscheine_nutzer -- Referenz auf Nutzer
        FOREIGN KEY (nutzer_id) REFERENCES nutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fahrzeuge
-- Das Kennzeichen ist nicht eindeutig: Ein ausgemustertes Fahrzeug behält
-- seins im Fahrtenbuch, und ein Nachfolger kann dasselbe bekommen.
CREATE TABLE fahrzeuge (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    kennzeichen   VARCHAR(20)       NOT NULL,
    art_id        TINYINT UNSIGNED  NOT NULL,
    typ           VARCHAR(30)       NOT NULL,      -- Kombi, Transporter, E-Auto …
    hersteller    VARCHAR(50)       NOT NULL,
    modell        VARCHAR(50)       NOT NULL,
    baujahr       SMALLINT UNSIGNED NULL,
    bild          VARCHAR(100)      NULL,          -- Dateiname in assets/img/, NULL = Platzhalter
    sitzplaetze   TINYINT UNSIGNED  NOT NULL,
    antrieb_id    TINYINT UNSIGNED  NULL,
    kmstand       INT UNSIGNED      NULL,          -- NULL beim Fahrrad
    fuehrerschein VARCHAR(5)        NULL,          -- NULL = kein Führerschein nötig
    hu_au         DATE              NULL,          -- nächste HU/AU, Monatserster des fälligen Monats
    geloescht_am  DATETIME          NULL,          -- NULL = im Bestand; sonst ausgemustert, Fahrtenbuch bleibt
    PRIMARY KEY (id),
    CONSTRAINT fk_fahrzeuge_art                    -- Referenz auf Fahrzeugart
        FOREIGN KEY (art_id) REFERENCES fahrzeugarten (id),
    CONSTRAINT fk_fahrzeuge_antrieb                -- Referenz auf Antrieb
        FOREIGN KEY (antrieb_id) REFERENCES antriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Buchungen
CREATE TABLE buchungen (
    id                     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    fahrzeug_id            INT UNSIGNED     NOT NULL,  -- welches Fahrzeug
    antragsteller_id       INT UNSIGNED     NOT NULL,  -- wer gebucht hat
    fahrer_id              INT UNSIGNED     NOT NULL,  -- wer fährt, meist dieselbe Person
    start                  DATE             NOT NULL,
    ende                   DATE             NOT NULL,
    zweck_id               TINYINT UNSIGNED NOT NULL,
    personenanzahl         TINYINT UNSIGNED NOT NULL,  -- einschließlich Fahrer
    status_id              TINYINT UNSIGNED NOT NULL DEFAULT 1, -- beantragt
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
    KEY idx_buchungen_status (status_id),
    CONSTRAINT fk_buchungen_fahrzeug                    -- Referenz auf Fahrzeug
        FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeuge (id),
    CONSTRAINT fk_buchungen_antragsteller               -- Referenz auf Benutzer (Antragsteller)
        FOREIGN KEY (antragsteller_id) REFERENCES nutzer (id),
    CONSTRAINT fk_buchungen_fahrer                      -- Referenz auf Benutzer (Fahrer)
        FOREIGN KEY (fahrer_id) REFERENCES nutzer (id),
    CONSTRAINT fk_buchungen_entscheidung_von            -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (entscheidung_von) REFERENCES nutzer (id),
    CONSTRAINT fk_buchungen_zweck                       -- Referenz auf Zweck
        FOREIGN KEY (zweck_id) REFERENCES zwecke (id),
    CONSTRAINT fk_buchungen_status                      -- Referenz auf Buchungsstatus
        FOREIGN KEY (status_id) REFERENCES buchungsstatus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schäden
-- Gemeldet bei Übernahme oder Rückgabe einer Buchung. Fahrzeug und meldende
-- Person (der Fahrer) ergeben sich aus der Buchung.
CREATE TABLE schaeden (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    buchung_id   INT UNSIGNED     NOT NULL,             -- bei welcher Fahrt gemeldet
    anlass_id    TINYINT UNSIGNED NOT NULL,
    schwere_id   TINYINT UNSIGNED NOT NULL,             -- 2 (mit Wartung) sperrt das Fahrzeug
    beschreibung TEXT             NOT NULL,
    gemeldet_am  DATETIME         NOT NULL,
    behoben_am   DATETIME         NULL,                 -- NULL = noch offen
    behoben_von  INT UNSIGNED     NULL,                 -- Fuhrparkleiter
    PRIMARY KEY (id),
    KEY idx_schaeden_offen (behoben_am),
    CONSTRAINT fk_schaeden_buchung                      -- Referenz auf Buchung
        FOREIGN KEY (buchung_id) REFERENCES buchungen (id),
    CONSTRAINT fk_schaeden_anlass                       -- Referenz auf Anlass
        FOREIGN KEY (anlass_id) REFERENCES schadensanlaesse (id),
    CONSTRAINT fk_schaeden_schwere                      -- Referenz auf Schweregrad
        FOREIGN KEY (schwere_id) REFERENCES schweregrade (id),
    CONSTRAINT fk_schaeden_behoben_von                  -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (behoben_von) REFERENCES nutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schadensfotos
CREATE TABLE schadensfotos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schaden_id INT UNSIGNED NOT NULL,              -- Schaden
    datei      VARCHAR(50)  NOT NULL,              -- zufälliger Name in uploads/schaeden/
    PRIMARY KEY (id),
    UNIQUE KEY uq_schadensfotos_datei (datei),     -- jede Datei gehört zu genau einem Foto
    CONSTRAINT fk_schadensfotos_schaden            -- Referenz auf Schaden
        FOREIGN KEY (schaden_id) REFERENCES schaeden (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Wartungen
-- Ein Fahrzeug ist in Wartung, solange es eine Zeile ohne freigegeben_am
-- gibt (höchstens eine je Fahrzeug, das prüft PHP). Abgeschlossene Wartungen
-- bleiben als Verlauf und erscheinen im Fahrtenbuch.
CREATE TABLE wartungen (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fahrzeug_id         INT UNSIGNED NOT NULL,
    schaden_id          INT UNSIGNED NULL,               -- ausgelöst durch einen Schaden, NULL = ohne Schaden
    grund               VARCHAR(200) NOT NULL,           -- z. B. „HU/AU“, Beschreibung des Schadens
    begonnen_am         DATE         NOT NULL,
    voraussichtlich_bis DATE         NULL,               -- NULL = Ende noch offen (nach Rückgabe)
    angelegt_von        INT UNSIGNED NULL,               -- Fuhrparkleiter, NULL bei Rückgabe mit Schaden
    freigegeben_am      DATE         NULL,               -- NULL = läuft noch
    freigegeben_von     INT UNSIGNED NULL,               -- Fuhrparkleiter
    PRIMARY KEY (id),
    KEY idx_wartungen_laufend (fahrzeug_id, freigegeben_am),
    CONSTRAINT fk_wartungen_fahrzeug                     -- Referenz auf Fahrzeug
        FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeuge (id),
    CONSTRAINT fk_wartungen_schaden                      -- Referenz auf Schaden
        FOREIGN KEY (schaden_id) REFERENCES schaeden (id),
    CONSTRAINT fk_wartungen_angelegt_von                 -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (angelegt_von) REFERENCES nutzer (id),
    CONSTRAINT fk_wartungen_freigegeben_von              -- Referenz auf Benutzer (Fuhrparkleiter)
        FOREIGN KEY (freigegeben_von) REFERENCES nutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
