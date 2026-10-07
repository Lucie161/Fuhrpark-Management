-- Stammdaten der Fuhrpark-Verwaltung: Nutzer und Fahrzeuge.
--
-- Passwort aller Testnutzer: fuhrpark

SET NAMES utf8mb4;

USE fuhrpark;

-- Testnutzer erstellen
INSERT INTO nutzer (id, vorname, nachname, rolle, passwort_hash) VALUES
    (1, 'Lucie',   'Schneider', 'mitarbeiter',    '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.'),
    (2, 'Ella',    'Luppold',   'mitarbeiter',    '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.'),
    (3, 'Finn',    'Clausen',   'mitarbeiter',    '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.'),
    (4, 'Kenneth', 'Sander',    'mitarbeiter',    '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.'),
    (5, 'Larissa', 'Wagner',    'mitarbeiter',    '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.'),
    (6, 'Jonas',   'Weber',     'fuhrparkleiter', '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.');


-- Führerscheine erstellen
INSERT INTO nutzer_fuehrerscheine (nutzer_id, klasse) VALUES
    (1, 'B'), (1, 'A1'),
    (2, 'B'),
    (3, 'B'), (3, 'A1'),
    (4, 'B'), (4, 'C1'), -- Wer C1 hat, bekommt B zusätzlich eingetragen
    (5, 'B'), (5, 'C1');

-- Fahrzeuge einfügen
INSERT INTO fahrzeuge
    (id, kennzeichen, art, typ, hersteller, modell, baujahr, bild, status,
     sitzplaetze, antrieb, kmstand, fuehrerschein, tuev)
VALUES
    (1, 'M-HS 101',  'auto',    'Kombi',       'Volkswagen',     'Passat Variant', 2021, NULL, 'verfuegbar', 5, 'diesel',  48250,  'B',  '2027-03-01'),
    (2, 'M-HS 102',  'auto',    'Kombi',       'Škoda',          'Octavia Combi',  2023, NULL, 'verfuegbar', 5, 'benzin',  9870,   'B',  '2026-05-01'),
    (3, 'M-HS 201',  'auto',    'Transporter', 'Ford',           'Transit',        2019, NULL, 'verfuegbar', 3, 'diesel',  112400, 'B',  '2026-11-01'),
    (4, 'M-HS 202',  'auto',    'Transporter', 'Mercedes-Benz',  'Sprinter',       2020, NULL, 'wartung',    3, 'diesel',  87310,  'C1', '2026-10-01'),
    (5, 'M-HS 301E', 'auto',    'E-Auto',      'Volkswagen',     'ID.3',           2022, NULL, 'verfuegbar', 5, 'elektro', 31540,  'B',  '2027-08-01'),
    (6, 'M-HS 302E', 'auto',    'E-Auto',      'Tesla',          'Model 3',        2024, NULL, 'verfuegbar', 5, 'elektro', 12020,  'B',  '2027-02-01'),
    (7, 'M-HS 401',  'roller',  'Roller',      'Vespa',          'Primavera 125',  2022, NULL, 'verfuegbar', 2, 'benzin',  6400,   'A1', '2027-06-01'),
    (8, 'Rad 1',     'fahrrad', 'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, NULL, 'verfuegbar', 1, 'elektro', NULL,   NULL, NULL),
    (9, 'Rad 2',     'fahrrad', 'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, NULL, 'verfuegbar', 1, 'elektro', NULL,   NULL, NULL);
