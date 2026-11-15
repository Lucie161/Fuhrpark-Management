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
-- Luxusautos (Macan und A6) dürfen Ella und Kenneth fahren (darf_luxus = 1).
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

-- Fahrzeuge einfügen (Liste: docs/konzept/fahrzeugliste.md). Nummer 17 ist
-- ausgemustert; der A4 Avant (6) hat sein Kennzeichen übernommen. Bilder sind
-- freigestellte PNGs in assets/img/ (Quellen: assets/img/QUELLEN.md).
-- HU/AU bewusst bald fällig bzw. überfällig, damit die Warnungen testbar sind:
-- Sprinter (11) überfällig, Buzz Cargo (9) diesen Monat, i4 (4) im Dezember.
-- Große Transporter brauchen C1. Die Kleinbusse sind Transporter mit vielen
-- Sitzen und gelten deshalb nur für Montage und Materialtransport.
INSERT INTO fahrzeuge
    (id, kennzeichen, art_id, typ, hersteller, modell, baujahr, bild,
     sitzplaetze, antrieb_id, kmstand, fuehrerschein, hu_au, geloescht_am)
VALUES
    (1,  'M-HS 101E', 1, 'E-Auto',              'Volkswagen',     'ID.3',            2023, 'vw-id3.png',                 5, 3, 31540, 'B',  '2027-08-01', NULL),
    (2,  'M-HS 102E', 1, 'E-Kombi',             'Volkswagen',     'ID.7 Tourer',     2024, 'vw-id7-tourer.png',          5, 3, 9870,  'B',  '2027-05-01', NULL),
    (3,  'M-HS 103E', 1, 'E-SUV',               'Audi',           'Q4 e-tron',       2024, 'audi-q4-e-tron.png',         5, 3, 18640, 'B',  '2027-09-01', NULL),
    (4,  'M-HS 104E', 1, 'E-Limousine',         'BMW',            'i4 eDrive40',     2024, 'bmw-i4.png',                 5, 3, 27310, 'B',  '2026-12-01', NULL),
    (5,  'M-HS 105E', 1, 'E-SUV',               'Mercedes-Benz',  'EQA 250+',        2023, 'mercedes-eqa.png',           5, 3, 14280, 'B',  '2027-04-01', NULL),
    (6,  'M-HS 106',  1, 'Kombi',               'Audi',           'A4 Avant',        2022, 'audi-a4-avant.png',          5, 2, 48250, 'B',  '2027-03-01', NULL),
    (7,  'M-HS 302E', 2, 'E-SUV',               'Porsche',        'Macan Electric',  2025, 'porsche-macan-electric.png', 5, 3, 12020, 'B',  '2028-02-01', NULL),
    (8,  'M-HS 306E', 2, 'E-Kombi',             'Audi',           'A6 Avant e-tron', 2025, 'audi-a6-avant-e-tron.png',   5, 3, 7350,  'B',  '2028-06-01', NULL),
    (9,  'M-HS 201E', 3, 'E-Transporter',       'Volkswagen',     'ID. Buzz Cargo',  2024, 'vw-id-buzz-cargo.png',       3, 3, 24400, 'B',  '2026-11-01', NULL),
    (10, 'M-HS 202E', 3, 'E-Transporter, groß', 'Mercedes-Benz',  'eSprinter',       2024, 'mercedes-esprinter.png',     3, 3, 9840,  'C1', '2027-10-01', NULL),
    (11, 'M-HS 203',  3, 'Transporter, groß',   'Mercedes-Benz',  'Sprinter',        2021, 'mercedes-sprinter.png',      3, 2, 87310, 'C1', '2026-10-01', NULL),
    (12, 'M-HS 204E', 3, 'Kleinbus',            'Mercedes-Benz',  'eVito Tourer',    2023, 'mercedes-evito-tourer.png',  9, 3, 23480, 'B',  '2027-01-01', NULL),
    (13, 'M-HS 205E', 3, 'Kleinbus',            'Volkswagen',     'ID. Buzz',        2024, 'vw-id-buzz.png',             7, 3, 11260, 'B',  '2027-11-01', NULL),
    (14, 'M-HS 401E', 4, 'E-Roller',            'BMW',            'CE 02',           2024, 'bmw-ce-02.png',              1, 3, 2900,  'A1', '2027-06-01', NULL),
    (15, 'Rad 1',     5, 'E-Fahrrad',           'Riese & Müller', 'Charger4',        2023, 'riese-mueller-charger.png',  1, 3, NULL,  NULL, NULL,         NULL),
    (16, 'Rad 2',     5, 'E-Fahrrad',           'Riese & Müller', 'Charger4',        2023, 'riese-mueller-charger.png',  1, 3, NULL,  NULL, NULL,         NULL),
    (17, 'M-HS 106',  1, 'Kombi',               'Volkswagen',     'Passat Variant',  2014, NULL,                         5, 2, 187430,'B',  '2026-03-01',
        TIMESTAMP(CURDATE() - INTERVAL 90 DAY, '12:00'));
