-- Stammdaten der Fuhrpark-Verwaltung: Nutzer und Fahrzeuge.
--
-- Passwort aller Testnutzer: fuhrpark
--
-- IDs aus den Schlüsseltabellen (schema.sql, includes/konstanten.php):
--   rolle_id   1 Mitarbeiter, 2 Fuhrparkleiter
--   art_id     1 Auto, 2 Luxusauto, 3 Transporter, 4 Roller, 5 Fahrrad
--   antrieb_id 1 Benzin, 2 Diesel, 3 Elektro

SET NAMES utf8mb4;

USE fuhrpark;

-- Testnutzer erstellen. Paul Becker ist ausgeschieden (geloescht_am gesetzt):
-- Er kann sich nicht mehr anmelden, seine Fahrt steht weiter im Fahrtenbuch.
-- Luxusautos (den Tesla) dürfen Ella und Kenneth fahren (darf_luxus = 1).
INSERT INTO nutzer (id, vorname, nachname, rolle_id, passwort_hash, darf_luxus, geloescht_am) VALUES
    (1, 'Lucie',   'Schneider', 1, '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.', 0, NULL),
    (2, 'Ella',    'Luppold',   1, '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.', 1, NULL),
    (3, 'Finn',    'Clausen',   1, '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.', 0, NULL),
    (4, 'Kenneth', 'Sander',    1, '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.', 1, NULL),
    (5, 'Larissa', 'Wagner',    1, '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.', 0, NULL),
    (6, 'Jonas',   'Weber',     2, '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.', 0, NULL),
    (7, 'Paul',    'Becker',    1, '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.', 0,
        TIMESTAMP(CURDATE() - INTERVAL 30 DAY, '17:00'));


-- Führerscheine erstellen
INSERT INTO nutzer_fuehrerscheine (nutzer_id, klasse) VALUES
    (1, 'B'), (1, 'A1'),
    (2, 'B'),
    (3, 'B'), (3, 'A1'),
    (4, 'B'), (4, 'C1'), -- Wer C1 hat, bekommt B zusätzlich eingetragen
    (5, 'B'), (5, 'C1'),
    (7, 'B');

-- Fahrzeuge einfügen. Nummer 10 ist ausgemustert; der heutige Passat (1) hat
-- sein Kennzeichen übernommen.
INSERT INTO fahrzeuge
    (id, kennzeichen, art_id, typ, hersteller, modell, baujahr, bild,
     sitzplaetze, antrieb_id, kmstand, fuehrerschein, hu_au, geloescht_am)
VALUES
    (1,  'M-HS 101',  1, 'Kombi',       'Volkswagen',     'Passat Variant', 2021, 'vw-passat-variant.jpg',     5, 2, 48250,  'B',  '2027-03-01', NULL),
    (2,  'M-HS 102',  1, 'Kombi',       'Škoda',          'Octavia Combi',  2023, 'skoda-octavia-combi.jpg',   5, 1, 9870,   'B',  '2026-05-01', NULL),
    (3,  'M-HS 201',  3, 'Transporter', 'Ford',           'Transit',        2019, 'ford-transit.jpg',          3, 2, 112400, 'B',  '2026-11-01', NULL),
    (4,  'M-HS 202',  3, 'Transporter', 'Mercedes-Benz',  'Sprinter',       2020, 'mercedes-sprinter.jpg',     3, 2, 87310,  'C1', '2026-10-01', NULL),
    (5,  'M-HS 301E', 1, 'E-Auto',      'Volkswagen',     'ID.3',           2022, 'vw-id3.jpg',                5, 3, 31540,  'B',  '2027-08-01', NULL),
    (6,  'M-HS 302E', 2, 'E-Auto',      'Tesla',          'Model 3',        2024, 'tesla-model-3.jpg',         5, 3, 12020,  'B',  '2027-02-01', NULL),
    (7,  'M-HS 401',  4, 'Roller',      'Vespa',          'Primavera 125',  2022, 'vespa-primavera.jpg',       2, 1, 6400,   'A1', '2027-06-01', NULL),
    (8,  'Rad 1',     5, 'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, 'riese-mueller-charger.jpg', 1, 3, NULL,   NULL, NULL,         NULL),
    (9,  'Rad 2',     5, 'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, 'riese-mueller-charger.jpg', 1, 3, NULL,   NULL, NULL,         NULL),
    (10, 'M-HS 101',  1, 'Kombi',       'Volkswagen',     'Passat Variant', 2014, NULL,                        5, 2, 187430, 'B',  '2026-03-01',
        TIMESTAMP(CURDATE() - INTERVAL 90 DAY, '12:00'));
