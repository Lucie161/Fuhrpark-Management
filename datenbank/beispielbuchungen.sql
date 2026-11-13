-- Beispielbuchungen der Fuhrpark-Verwaltung.
--
-- Im Anschluss an schema.sql und stammdaten.sql einspielen. Alle Daten sind
-- relativ zum heutigen Tag, damit jede Seite zu jedem Zeitpunkt alle Zustände
-- zeigt: offen, genehmigt, unterwegs (davon eine überfällig), abgeschlossen,
-- abgelehnt und storniert. Dazu Schäden: offen und behoben, Kleinschäden und
-- Schäden mit Wartung, gemeldet bei Rückgabe oder Übernahme.
--
-- Die IDs 11–24 entsprechen den Beispieldaten der Prototyp-Seiten. Ab 56
-- folgen weitere Buchungen, ab 60 ältere Fahrten über etwa vier Monate. Die
-- km-Stände ergeben je Fahrzeug eine lückenlose Kette bis zu fahrzeuge.kmstand
-- aus stammdaten.sql. Schadensfotos gibt es keine; sie entstehen erst bei einer Rückgabe.
--
-- Nutzer: 1 Lucie, 2 Ella, 3 Finn, 4 Kenneth, 5 Larissa (Mitarbeiter),
-- 6 Jonas (Fuhrparkleiter), 7 Paul (ausgeschieden).
--
-- IDs aus den Schlüsseltabellen (schema.sql, includes/konstanten.php):
--   zweck_id   1 Montage, 2 Wartung/Service, 3 Kundentermin, (4 Aufmaß entfällt),
--              5 Materialtransport, 6 Lieferantenbesuch, 7 Schulung, 8 Sonstiges
--   status_id  1 beantragt (offen), 2 genehmigt, 3 unterwegs, 4 abgeschlossen,
--              5 abgelehnt, 6 storniert
--   anlass_id  1 bei Übernahme, 2 bei Rückgabe
--   schwere_id 1 Kleinschaden, 2 Schaden mit Wartung

SET NAMES utf8mb4;

USE fuhrpark;

SET @heute = CURDATE();


-- Offen, genehmigt, abgelehnt und storniert (Fahrt nicht begonnen).
-- Roller und Fahrräder sind sofort genehmigt, ohne Entscheidung.
INSERT INTO buchungen
    (id, fahrzeug_id, antragsteller_id, fahrer_id, start, ende, zweck_id, personenanzahl, status_id,
     beantragt_am, entscheidung_von, entscheidung_am, entscheidung_kommentar)
VALUES
    -- Lucie: heute mit Rad 1, kann beginnen
    (11, 8, 1, 1, @heute,                    @heute,                    3, 1, 2,
        TIMESTAMP(@heute - INTERVAL 2 DAY, '09:10'), NULL, NULL, NULL),
    (13, 5, 1, 1, @heute + INTERVAL 2 DAY,   @heute + INTERVAL 2 DAY,   3, 1, 2,
        TIMESTAMP(@heute - INTERVAL 4 DAY, '11:20'), 6, TIMESTAMP(@heute - INTERVAL 4 DAY, '15:00'), NULL),
    -- Kenneth bucht für Lucie
    (14, 2, 4, 1, @heute + INTERVAL 5 DAY,   @heute + INTERVAL 6 DAY,   1, 3, 1,
        TIMESTAMP(@heute - INTERVAL 3 DAY, '08:45'), NULL, NULL, NULL),
    (15, 1, 1, 1, @heute + INTERVAL 10 DAY,  @heute + INTERVAL 11 DAY,  6, 2, 1,
        TIMESTAMP(@heute - INTERVAL 1 DAY, '10:05'), NULL, NULL, NULL),
    (16, 6, 1, 1, @heute + INTERVAL 3 DAY,   @heute + INTERVAL 3 DAY,   3, 1, 5,
        TIMESTAMP(@heute - INTERVAL 4 DAY, '09:30'), 6, TIMESTAMP(@heute - INTERVAL 3 DAY, '10:15'),
        'Für Einzeltermine bitte den ID.3 oder ein E-Fahrrad nutzen.'),
    -- war genehmigt, dann storniert
    (17, 3, 1, 1, @heute - INTERVAL 6 DAY,   @heute - INTERVAL 6 DAY,   5, 2, 6,
        TIMESTAMP(@heute - INTERVAL 12 DAY, '13:00'), 6, TIMESTAMP(@heute - INTERVAL 11 DAY, '09:00'), NULL),
    (19, 3, 4, 4, @heute + INTERVAL 3 DAY,   @heute + INTERVAL 4 DAY,   5, 2, 1,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '14:12'), NULL, NULL, NULL),
    (20, 6, 4, 4, @heute + INTERVAL 8 DAY,   @heute + INTERVAL 9 DAY,   3, 1, 1,
        TIMESTAMP(@heute - INTERVAL 2 DAY, '16:30'), NULL, NULL, NULL),
    -- Ella: heute, kann wegen ihrer überfälligen Tesla-Rückgabe nicht beginnen
    (21, 1, 2, 2, @heute,                    @heute,                    3, 2, 2,
        TIMESTAMP(@heute - INTERVAL 6 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 6 DAY, '14:30'), NULL),
    (25, 1, 3, 3, @heute + INTERVAL 4 DAY,   @heute + INTERVAL 6 DAY,   3, 1, 2,
        TIMESTAMP(@heute - INTERVAL 8 DAY, '08:15'), 6, TIMESTAMP(@heute - INTERVAL 7 DAY, '09:40'), NULL),
    (26, 3, 5, 5, @heute + INTERVAL 7 DAY,   @heute + INTERVAL 8 DAY,   5, 2, 2,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '11:00'), 6, TIMESTAMP(@heute - INTERVAL 4 DAY, '08:30'), NULL),
    (27, 9, 5, 5, @heute + INTERVAL 3 DAY,   @heute + INTERVAL 4 DAY,   3, 1, 2,
        TIMESTAMP(@heute - INTERVAL 3 DAY, '12:00'), NULL, NULL, NULL),
    -- Ella: genehmigt, aber ihre Tesla-Rückgabe ist überfällig
    (31, 9, 2, 2, @heute + INTERVAL 6 DAY,   @heute + INTERVAL 6 DAY,   3, 1, 2,
        TIMESTAMP(@heute - INTERVAL 1 DAY, '09:00'), NULL, NULL, NULL),
    -- Larissa bucht für Ella; fünfter offener Antrag
    (32, 2, 5, 2, @heute + INTERVAL 9 DAY,   @heute + INTERVAL 9 DAY,   1, 3, 1,
        TIMESTAMP(@heute - INTERVAL 1 DAY, '15:20'), NULL, NULL, NULL),
    (33, 9, 5, 5, @heute + INTERVAL 1 DAY,   @heute + INTERVAL 1 DAY,   3, 1, 6,
        TIMESTAMP(@heute - INTERVAL 6 DAY, '08:00'), NULL, NULL, NULL),
    (34, 5, 4, 4, @heute - INTERVAL 4 DAY,   @heute - INTERVAL 4 DAY,   3, 2, 5,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '10:30'), 6, TIMESTAMP(@heute - INTERVAL 6 DAY, '08:50'),
        'Der ID.3 ist an dem Tag zur Inspektion angemeldet.'),
    -- automatisch storniert, als der Sprinter in Wartung ging
    (54, 4, 5, 5, @heute + INTERVAL 2 DAY,   @heute + INTERVAL 3 DAY,   5, 2, 6,
        TIMESTAMP(@heute - INTERVAL 15 DAY, '09:30'), 6, TIMESTAMP(@heute - INTERVAL 13 DAY, '16:50'),
        'Automatisch storniert: Das Fahrzeug ist in diesem Zeitraum in Wartung.'),
    -- Lucie: ID.3 an zwei Tagen hintereinander, übermorgen genehmigt (13), danach offen
    (56, 5, 1, 1, @heute + INTERVAL 3 DAY,   @heute + INTERVAL 3 DAY,   3, 1, 1,
        TIMESTAMP(@heute - INTERVAL 1 DAY, '08:30'), NULL, NULL, NULL),
    (57, 7, 1, 1, @heute + INTERVAL 7 DAY,   @heute + INTERVAL 7 DAY,   3, 1, 2,
        TIMESTAMP(@heute - INTERVAL 2 DAY, '13:10'), NULL, NULL, NULL),
    (58, 11, 3, 3, @heute + INTERVAL 1 DAY,  @heute + INTERVAL 2 DAY,   3, 2, 2,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '09:45'), 6, TIMESTAMP(@heute - INTERVAL 4 DAY, '10:20'), NULL),
    (59, 11, 1, 1, @heute + INTERVAL 14 DAY, @heute + INTERVAL 15 DAY,  6, 1, 2,
        TIMESTAMP(@heute - INTERVAL 3 DAY, '10:30'), 6, TIMESTAMP(@heute - INTERVAL 2 DAY, '08:40'), NULL),
    (117, 14, 4, 4, @heute + INTERVAL 5 DAY, @heute + INTERVAL 6 DAY,   1, 3, 1,
        TIMESTAMP(@heute - INTERVAL 1 DAY, '11:40'), NULL, NULL, NULL),
    (118, 17, 5, 5, @heute + INTERVAL 10 DAY, @heute + INTERVAL 11 DAY, 1, 8, 2,
        TIMESTAMP(@heute - INTERVAL 6 DAY, '07:50'), 6, TIMESTAMP(@heute - INTERVAL 5 DAY, '09:10'), NULL),
    (119, 12, 2, 2, @heute + INTERVAL 12 DAY, @heute + INTERVAL 12 DAY, 3, 1, 1,
        TIMESTAMP(@heute - INTERVAL 4 DAY, '16:05'), NULL, NULL, NULL),
    (120, 16, 4, 4, @heute + INTERVAL 12 DAY, @heute + INTERVAL 13 DAY, 5, 2, 2,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '14:30'), 6, TIMESTAMP(@heute - INTERVAL 6 DAY, '09:25'), NULL),
    (121, 18, 3, 3, @heute + INTERVAL 8 DAY,  @heute + INTERVAL 9 DAY,  1, 5, 1,
        TIMESTAMP(@heute - INTERVAL 2 DAY, '12:15'), NULL, NULL, NULL);


-- Unterwegs. km_start ist der km-Stand des Fahrzeugs bei Fahrtbeginn.
INSERT INTO buchungen
    (id, fahrzeug_id, antragsteller_id, fahrer_id, start, ende, zweck_id, personenanzahl, status_id,
     beantragt_am, entscheidung_von, entscheidung_am, begonnen_am, km_start)
VALUES
    -- Lucie: heute fällig, nicht überfällig
    (12, 7, 1, 1, @heute - INTERVAL 2 DAY,   @heute,                    3, 1, 3,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '09:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 2 DAY, '08:00'), 6400),
    -- fristgerecht unterwegs; Kratzer bei Übernahme notiert (Schaden 3)
    (18, 3, 4, 4, @heute,                    @heute + INTERVAL 2 DAY,   1, 3, 3,
        TIMESTAMP(@heute - INTERVAL 6 DAY, '10:20'), 6, TIMESTAMP(@heute - INTERVAL 5 DAY, '09:15'),
        TIMESTAMP(@heute, '07:15'), 112400),
    -- Ella: überfällig seit vorgestern
    (30, 6, 2, 2, @heute - INTERVAL 3 DAY,   @heute - INTERVAL 2 DAY,   3, 1, 3,
        TIMESTAMP(@heute - INTERVAL 9 DAY, '14:00'), 6, TIMESTAMP(@heute - INTERVAL 8 DAY, '08:20'),
        TIMESTAMP(@heute - INTERVAL 3 DAY, '07:45'), 12020);


-- Abgeschlossen: das Fahrtenbuch. Neueste zuerst. Fahrräder ohne km.
-- Autos und Transporter sind vom Fuhrparkleiter genehmigt (entscheidung_von = 6).
INSERT INTO buchungen
    (id, fahrzeug_id, antragsteller_id, fahrer_id, start, ende, zweck_id, personenanzahl, status_id,
     beantragt_am, entscheidung_von, entscheidung_am, begonnen_am, km_start,
     zurueckgegeben_am, km_ende, bemerkung)
VALUES
    -- Kleinschaden (Schaden 7)
    (48, 8, 2, 2, @heute - INTERVAL 5 DAY,   @heute - INTERVAL 5 DAY,   3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '16:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '08:30'), NULL,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '15:10'), NULL, NULL),
    -- Kleinschaden (Schaden 4)
    (53, 9, 4, 4, @heute - INTERVAL 7 DAY,   @heute - INTERVAL 7 DAY,   3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 8 DAY, '11:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '09:00'), NULL,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '13:40'), NULL, NULL),
    (22, 5, 1, 1, @heute - INTERVAL 9 DAY,   @heute - INTERVAL 9 DAY,   3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 13 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 12 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 9 DAY, '08:00'), 31494,
        TIMESTAMP(@heute - INTERVAL 9 DAY, '16:20'), 31540, NULL),
    (23, 8, 1, 1, @heute - INTERVAL 10 DAY,  @heute - INTERVAL 10 DAY,  3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 11 DAY, '14:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 10 DAY, '08:15'), NULL,
        TIMESTAMP(@heute - INTERVAL 10 DAY, '14:30'), NULL, 'Akku nach Rückkehr wieder angeschlossen.'),
    (35, 1, 4, 4, @heute - INTERVAL 11 DAY,  @heute - INTERVAL 11 DAY,  3, 2, 4,
        TIMESTAMP(@heute - INTERVAL 15 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 14 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 11 DAY, '07:45'), 48166,
        TIMESTAMP(@heute - INTERVAL 11 DAY, '17:05'), 48250, NULL),
    -- Schaden mit Wartung (Schaden 1): Sprinter steht deshalb in Wartung (stammdaten.sql)
    (36, 4, 5, 5, @heute - INTERVAL 15 DAY,  @heute - INTERVAL 13 DAY,  1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 19 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 18 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 15 DAY, '07:00'), 87167,
        TIMESTAMP(@heute - INTERVAL 13 DAY, '16:45'), 87310, NULL),
    (37, 3, 2, 2, @heute - INTERVAL 14 DAY,  @heute - INTERVAL 14 DAY,  1, 2, 4,
        TIMESTAMP(@heute - INTERVAL 18 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 17 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 14 DAY, '07:30'), 112304,
        TIMESTAMP(@heute - INTERVAL 14 DAY, '16:00'), 112400, NULL),
    (38, 1, 5, 5, @heute - INTERVAL 16 DAY,  @heute - INTERVAL 16 DAY,  3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 20 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 19 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 16 DAY, '08:00'), 48034,
        TIMESTAMP(@heute - INTERVAL 16 DAY, '15:30'), 48166, 'Klappergeräusch hinten rechts bei Tempo über 100.'),
    (39, 3, 4, 4, @heute - INTERVAL 18 DAY,  @heute - INTERVAL 18 DAY,  5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 22 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 21 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 18 DAY, '07:15'), 112246,
        TIMESTAMP(@heute - INTERVAL 18 DAY, '14:50'), 112304, 'Ladefläche verschmutzt, Spanngurt fehlt.'),
    -- vorhandener Kratzer bei Übernahme notiert (Schaden 2)
    (47, 5, 4, 4, @heute - INTERVAL 20 DAY,  @heute - INTERVAL 20 DAY,  3, 2, 4,
        TIMESTAMP(@heute - INTERVAL 24 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 23 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 20 DAY, '08:10'), 31402,
        TIMESTAMP(@heute - INTERVAL 20 DAY, '15:00'), 31494, NULL),
    (40, 1, 3, 3, @heute - INTERVAL 22 DAY,  @heute - INTERVAL 22 DAY,  6, 1, 4,
        TIMESTAMP(@heute - INTERVAL 26 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 25 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 22 DAY, '07:30'), 47824,
        TIMESTAMP(@heute - INTERVAL 22 DAY, '17:20'), 48034, 'Innenraum könnte mal gereinigt werden.'),
    (41, 6, 2, 2, @heute - INTERVAL 25 DAY,  @heute - INTERVAL 25 DAY,  7, 2, 4,
        TIMESTAMP(@heute - INTERVAL 29 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 28 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 25 DAY, '07:00'), 11832,
        TIMESTAMP(@heute - INTERVAL 25 DAY, '18:10'), 12020, NULL),
    (42, 9, 5, 5, @heute - INTERVAL 28 DAY,  @heute - INTERVAL 28 DAY,  3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 30 DAY, '12:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 28 DAY, '09:00'), NULL,
        TIMESTAMP(@heute - INTERVAL 28 DAY, '12:30'), NULL, NULL),
    (49, 4, 4, 4, @heute - INTERVAL 29 DAY,  @heute - INTERVAL 29 DAY,  5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 33 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 32 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 29 DAY, '07:00'), 86980,
        TIMESTAMP(@heute - INTERVAL 29 DAY, '16:30'), 87167, NULL),
    (43, 7, 3, 3, @heute - INTERVAL 30 DAY,  @heute - INTERVAL 30 DAY,  3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 31 DAY, '15:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 30 DAY, '09:30'), 6378,
        TIMESTAMP(@heute - INTERVAL 30 DAY, '12:15'), 6400, NULL),
    -- Kleinschaden, inzwischen behoben (Schaden 5)
    (44, 2, 4, 4, @heute - INTERVAL 37 DAY,  @heute - INTERVAL 36 DAY,  2, 1, 4,
        TIMESTAMP(@heute - INTERVAL 41 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 40 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 37 DAY, '07:30'), 9605,
        TIMESTAMP(@heute - INTERVAL 36 DAY, '16:00'), 9870, NULL),
    (50, 7, 1, 1, @heute - INTERVAL 40 DAY,  @heute - INTERVAL 40 DAY,  3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 42 DAY, '09:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 40 DAY, '10:00'), 6341,
        TIMESTAMP(@heute - INTERVAL 40 DAY, '13:00'), 6378, 'Helmfach klemmt beim Öffnen.'),
    (45, 1, 2, 2, @heute - INTERVAL 44 DAY,  @heute - INTERVAL 44 DAY,  3, 2, 4,
        TIMESTAMP(@heute - INTERVAL 48 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 47 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 44 DAY, '08:00'), 47706,
        TIMESTAMP(@heute - INTERVAL 44 DAY, '16:40'), 47824, NULL),
    -- Schaden mit Wartung, nach der Reparatur freigegeben (Schaden 6)
    (46, 3, 3, 3, @heute - INTERVAL 51 DAY,  @heute - INTERVAL 50 DAY,  1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 55 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 54 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 51 DAY, '07:00'), 112072,
        TIMESTAMP(@heute - INTERVAL 50 DAY, '17:30'), 112246, 'Rückfahrkamera zeigt zeitweise kein Bild.'),
    (24, 2, 1, 1, @heute - INTERVAL 57 DAY,  @heute - INTERVAL 57 DAY,  6, 1, 4,
        TIMESTAMP(@heute - INTERVAL 61 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 60 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 57 DAY, '08:00'), 9508,
        TIMESTAMP(@heute - INTERVAL 57 DAY, '15:45'), 9605, NULL),
    (51, 1, 5, 5, @heute - INTERVAL 64 DAY,  @heute - INTERVAL 64 DAY,  1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 68 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 67 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 64 DAY, '07:00'), 47588,
        TIMESTAMP(@heute - INTERVAL 64 DAY, '16:15'), 47706, NULL),
    -- Paul, inzwischen ausgeschieden: bleibt im Fahrtenbuch
    (52, 2, 7, 7, @heute - INTERVAL 71 DAY,  @heute - INTERVAL 70 DAY,  7, 2, 4,
        TIMESTAMP(@heute - INTERVAL 75 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 74 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 71 DAY, '07:30'), 9420,
        TIMESTAMP(@heute - INTERVAL 70 DAY, '18:00'), 9508, NULL),
    -- letzte Fahrt mit dem alten Passat (Fahrzeug 10), vor dem Ausmustern
    (55, 10, 1, 1, @heute - INTERVAL 95 DAY, @heute - INTERVAL 95 DAY,  3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 99 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 98 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 95 DAY, '08:00'), 187312,
        TIMESTAMP(@heute - INTERVAL 95 DAY, '16:30'), 187430, NULL);

-- Weitere abgeschlossene Fahrten, damit Fahrtenbuch, Historie und Dashboard
-- mehrere Monate zeigen. Bei den älteren Fahrzeugen liegen sie vor den Fahrten
-- oben, die km-Kette läuft also weiter lückenlos bis fahrzeuge.kmstand. Der
-- heutige Passat (1) fährt erst seit dem Ausmustern des alten (Tag -90).
INSERT INTO buchungen
    (id, fahrzeug_id, antragsteller_id, fahrer_id, start, ende, zweck_id, personenanzahl, status_id,
     beantragt_am, entscheidung_von, entscheidung_am, begonnen_am, km_start,
     zurueckgegeben_am, km_ende, bemerkung)
VALUES
    -- Finn, eVito Tourer
    (60, 17, 3, 3, @heute - INTERVAL 2 DAY, @heute - INTERVAL 1 DAY, 1, 8, 4,
        TIMESTAMP(@heute - INTERVAL 6 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 5 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 2 DAY, '07:30'), 23290,
        TIMESTAMP(@heute - INTERVAL 1 DAY, '16:15'), 23480, NULL),
    -- Larissa, Fiat 500e
    (61, 13, 5, 5, @heute - INTERVAL 3 DAY, @heute - INTERVAL 3 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 6 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 3 DAY, '07:30'), 14236,
        TIMESTAMP(@heute - INTERVAL 3 DAY, '16:15'), 14280, NULL),
    -- Kenneth, E-Transit
    (62, 14, 4, 4, @heute - INTERVAL 4 DAY, @heute - INTERVAL 4 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 8 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 7 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 4 DAY, '07:30'), 21820,
        TIMESTAMP(@heute - INTERVAL 4 DAY, '16:15'), 21950, NULL),
    -- Finn, Enyaq
    (63, 11, 3, 3, @heute - INTERVAL 6 DAY, @heute - INTERVAL 6 DAY, 3, 2, 4,
        TIMESTAMP(@heute - INTERVAL 10 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 9 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 6 DAY, '07:30'), 18510,
        TIMESTAMP(@heute - INTERVAL 6 DAY, '16:15'), 18640, NULL),
    -- Ella, Ioniq 5
    (64, 12, 2, 2, @heute - INTERVAL 8 DAY, @heute - INTERVAL 8 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 12 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 11 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 8 DAY, '07:30'), 27190,
        TIMESTAMP(@heute - INTERVAL 8 DAY, '16:15'), 27310, NULL),
    -- Larissa, eSprinter
    (65, 15, 5, 5, @heute - INTERVAL 9 DAY, @heute - INTERVAL 8 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 13 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 12 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 9 DAY, '07:30'), 9620,
        TIMESTAMP(@heute - INTERVAL 8 DAY, '16:15'), 9840, NULL),
    -- Ella, ID. Buzz
    (66, 18, 2, 2, @heute - INTERVAL 11 DAY, @heute - INTERVAL 11 DAY, 1, 5, 4,
        TIMESTAMP(@heute - INTERVAL 15 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 14 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 11 DAY, '07:30'), 11080,
        TIMESTAMP(@heute - INTERVAL 11 DAY, '16:15'), 11260, NULL),
    -- Larissa, E-Transit
    (67, 14, 5, 5, @heute - INTERVAL 12 DAY, @heute - INTERVAL 12 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 16 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 15 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 12 DAY, '07:30'), 21640,
        TIMESTAMP(@heute - INTERVAL 12 DAY, '16:15'), 21820, NULL),
    -- Kenneth, eVito Tourer
    (68, 17, 4, 4, @heute - INTERVAL 14 DAY, @heute - INTERVAL 14 DAY, 1, 7, 4,
        TIMESTAMP(@heute - INTERVAL 18 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 17 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 14 DAY, '07:30'), 23130,
        TIMESTAMP(@heute - INTERVAL 14 DAY, '16:15'), 23290, NULL),
    -- Kenneth, Daily
    (69, 16, 4, 4, @heute - INTERVAL 16 DAY, @heute - INTERVAL 16 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 20 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 19 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 16 DAY, '07:30'), 63890,
        TIMESTAMP(@heute - INTERVAL 16 DAY, '16:15'), 64120, NULL),
    -- Lucie, Fiat 500e
    (70, 13, 1, 1, @heute - INTERVAL 17 DAY, @heute - INTERVAL 17 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 21 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 20 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 17 DAY, '07:30'), 14190,
        TIMESTAMP(@heute - INTERVAL 17 DAY, '16:15'), 14236, NULL),
    -- Lucie, Enyaq
    (71, 11, 1, 1, @heute - INTERVAL 19 DAY, @heute - INTERVAL 19 DAY, 6, 1, 4,
        TIMESTAMP(@heute - INTERVAL 23 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 22 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 19 DAY, '07:30'), 18330,
        TIMESTAMP(@heute - INTERVAL 19 DAY, '16:15'), 18510, NULL),
    -- Lucie, Rad 2
    (72, 9, 1, 1, @heute - INTERVAL 20 DAY, @heute - INTERVAL 20 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 24 DAY, '10:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 20 DAY, '07:30'), NULL,
        TIMESTAMP(@heute - INTERVAL 20 DAY, '16:15'), NULL, NULL),
    -- Kenneth, eSprinter
    (73, 15, 4, 4, @heute - INTERVAL 23 DAY, @heute - INTERVAL 23 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 27 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 26 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 23 DAY, '07:30'), 9480,
        TIMESTAMP(@heute - INTERVAL 23 DAY, '16:15'), 9620, NULL),
    -- Kenneth, Ioniq 5
    (74, 12, 4, 4, @heute - INTERVAL 24 DAY, @heute - INTERVAL 24 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 28 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 27 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 24 DAY, '07:30'), 27020,
        TIMESTAMP(@heute - INTERVAL 24 DAY, '16:15'), 27190, NULL),
    -- Finn, ID. Buzz
    (75, 18, 3, 3, @heute - INTERVAL 26 DAY, @heute - INTERVAL 26 DAY, 1, 6, 4,
        TIMESTAMP(@heute - INTERVAL 30 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 29 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 26 DAY, '07:30'), 10900,
        TIMESTAMP(@heute - INTERVAL 26 DAY, '16:15'), 11080, NULL),
    -- Finn, E-Transit
    (76, 14, 3, 3, @heute - INTERVAL 27 DAY, @heute - INTERVAL 27 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 31 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 30 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 27 DAY, '07:30'), 21480,
        TIMESTAMP(@heute - INTERVAL 27 DAY, '16:15'), 21640, NULL),
    -- Larissa, eVito Tourer
    (77, 17, 5, 5, @heute - INTERVAL 30 DAY, @heute - INTERVAL 30 DAY, 1, 9, 4,
        TIMESTAMP(@heute - INTERVAL 34 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 33 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 30 DAY, '07:30'), 22960,
        TIMESTAMP(@heute - INTERVAL 30 DAY, '16:15'), 23130, NULL),
    -- Finn, Fiat 500e
    (78, 13, 3, 3, @heute - INTERVAL 31 DAY, @heute - INTERVAL 31 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 35 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 34 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 31 DAY, '07:30'), 14130,
        TIMESTAMP(@heute - INTERVAL 31 DAY, '16:15'), 14190, NULL),
    -- Lucie, ID.3
    (79, 5, 1, 1, @heute - INTERVAL 33 DAY, @heute - INTERVAL 33 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 37 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 36 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 33 DAY, '07:30'), 31290,
        TIMESTAMP(@heute - INTERVAL 33 DAY, '16:15'), 31402, NULL),
    -- Larissa, Rad 1
    (80, 8, 5, 5, @heute - INTERVAL 33 DAY, @heute - INTERVAL 33 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 37 DAY, '10:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 33 DAY, '07:30'), NULL,
        TIMESTAMP(@heute - INTERVAL 33 DAY, '16:15'), NULL, NULL),
    -- Finn, Rad 1
    (81, 8, 3, 3, @heute - INTERVAL 35 DAY, @heute - INTERVAL 35 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 39 DAY, '10:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 35 DAY, '07:30'), NULL,
        TIMESTAMP(@heute - INTERVAL 35 DAY, '16:15'), NULL, 'Licht vorne flackert.'),
    -- Larissa, Daily
    (82, 16, 5, 5, @heute - INTERVAL 36 DAY, @heute - INTERVAL 36 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 40 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 39 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 36 DAY, '07:30'), 63650,
        TIMESTAMP(@heute - INTERVAL 36 DAY, '16:15'), 63890, 'Plane der Ladefläche hat ein kleines Loch.'),
    -- Larissa, Enyaq
    (83, 11, 5, 5, @heute - INTERVAL 38 DAY, @heute - INTERVAL 38 DAY, 7, 3, 4,
        TIMESTAMP(@heute - INTERVAL 42 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 41 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 38 DAY, '07:30'), 18150,
        TIMESTAMP(@heute - INTERVAL 38 DAY, '16:15'), 18330, NULL),
    -- Kenneth, Tesla
    (84, 6, 4, 4, @heute - INTERVAL 40 DAY, @heute - INTERVAL 40 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 44 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 43 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 40 DAY, '07:30'), 11640,
        TIMESTAMP(@heute - INTERVAL 40 DAY, '16:15'), 11832, NULL),
    -- Lucie, E-Transit
    (85, 14, 1, 1, @heute - INTERVAL 43 DAY, @heute - INTERVAL 43 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 47 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 46 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 43 DAY, '07:30'), 21290,
        TIMESTAMP(@heute - INTERVAL 43 DAY, '16:15'), 21480, NULL),
    -- Larissa, Sprinter
    (86, 4, 5, 5, @heute - INTERVAL 45 DAY, @heute - INTERVAL 45 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 49 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 48 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 45 DAY, '07:30'), 86795,
        TIMESTAMP(@heute - INTERVAL 45 DAY, '16:15'), 86980, NULL),
    -- Lucie, Ioniq 5
    (87, 12, 1, 1, @heute - INTERVAL 45 DAY, @heute - INTERVAL 45 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 49 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 48 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 45 DAY, '07:30'), 26850,
        TIMESTAMP(@heute - INTERVAL 45 DAY, '16:15'), 27020, 'Ladekabel lag nicht im Kofferraum.'),
    -- Kenneth, ID. Buzz
    (88, 18, 4, 4, @heute - INTERVAL 47 DAY, @heute - INTERVAL 47 DAY, 1, 4, 4,
        TIMESTAMP(@heute - INTERVAL 51 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 50 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 47 DAY, '07:30'), 10720,
        TIMESTAMP(@heute - INTERVAL 47 DAY, '16:15'), 10900, NULL),
    -- Ella, ID.3
    (89, 5, 2, 2, @heute - INTERVAL 48 DAY, @heute - INTERVAL 48 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 52 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 51 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 48 DAY, '07:30'), 31150,
        TIMESTAMP(@heute - INTERVAL 48 DAY, '16:15'), 31290, NULL),
    -- Larissa, eSprinter
    (90, 15, 5, 5, @heute - INTERVAL 50 DAY, @heute - INTERVAL 50 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 54 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 53 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 50 DAY, '07:30'), 9300,
        TIMESTAMP(@heute - INTERVAL 50 DAY, '16:15'), 9480, NULL),
    -- Ella, Fiat 500e
    (91, 13, 2, 2, @heute - INTERVAL 52 DAY, @heute - INTERVAL 52 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 56 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 55 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 52 DAY, '07:30'), 14075,
        TIMESTAMP(@heute - INTERVAL 52 DAY, '16:15'), 14130, NULL),
    -- Finn, Vespa
    (92, 7, 3, 3, @heute - INTERVAL 55 DAY, @heute - INTERVAL 55 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 59 DAY, '10:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 55 DAY, '07:30'), 6300,
        TIMESTAMP(@heute - INTERVAL 55 DAY, '16:15'), 6341, NULL),
    -- Ella, Enyaq
    (93, 11, 2, 2, @heute - INTERVAL 58 DAY, @heute - INTERVAL 58 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 62 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 61 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 58 DAY, '07:30'), 17940,
        TIMESTAMP(@heute - INTERVAL 58 DAY, '16:15'), 18150, NULL),
    -- Lucie, eVito Tourer
    (94, 17, 1, 1, @heute - INTERVAL 59 DAY, @heute - INTERVAL 59 DAY, 1, 6, 4,
        TIMESTAMP(@heute - INTERVAL 63 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 62 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 59 DAY, '07:30'), 22790,
        TIMESTAMP(@heute - INTERVAL 59 DAY, '16:15'), 22960, NULL),
    -- Kenneth, Transit
    (95, 3, 4, 4, @heute - INTERVAL 60 DAY, @heute - INTERVAL 60 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 64 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 63 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 60 DAY, '07:30'), 111890,
        TIMESTAMP(@heute - INTERVAL 60 DAY, '16:15'), 112072, NULL),
    -- Ella, Rad 2
    (96, 9, 2, 2, @heute - INTERVAL 60 DAY, @heute - INTERVAL 60 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 64 DAY, '10:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 60 DAY, '07:30'), NULL,
        TIMESTAMP(@heute - INTERVAL 60 DAY, '16:15'), NULL, NULL),
    -- Ella, Tesla
    (97, 6, 2, 2, @heute - INTERVAL 62 DAY, @heute - INTERVAL 62 DAY, 6, 1, 4,
        TIMESTAMP(@heute - INTERVAL 66 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 65 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 62 DAY, '07:30'), 11420,
        TIMESTAMP(@heute - INTERVAL 62 DAY, '16:15'), 11640, NULL),
    -- Kenneth, Daily
    (98, 16, 4, 4, @heute - INTERVAL 63 DAY, @heute - INTERVAL 63 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 67 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 66 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 63 DAY, '07:30'), 63400,
        TIMESTAMP(@heute - INTERVAL 63 DAY, '16:15'), 63650, NULL),
    -- Kenneth, Sprinter
    (99, 4, 4, 4, @heute - INTERVAL 66 DAY, @heute - INTERVAL 66 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 70 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 69 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 66 DAY, '07:30'), 86590,
        TIMESTAMP(@heute - INTERVAL 66 DAY, '16:15'), 86795, NULL),
    -- Kenneth, E-Transit
    (100, 14, 4, 4, @heute - INTERVAL 68 DAY, @heute - INTERVAL 68 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 72 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 71 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 68 DAY, '07:30'), 21100,
        TIMESTAMP(@heute - INTERVAL 68 DAY, '16:15'), 21290, NULL),
    -- Finn, ID.3
    (101, 5, 3, 3, @heute - INTERVAL 70 DAY, @heute - INTERVAL 70 DAY, 7, 2, 4,
        TIMESTAMP(@heute - INTERVAL 74 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 73 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 70 DAY, '07:30'), 31020,
        TIMESTAMP(@heute - INTERVAL 70 DAY, '16:15'), 31150, NULL),
    -- Finn, Ioniq 5
    (102, 12, 3, 3, @heute - INTERVAL 72 DAY, @heute - INTERVAL 72 DAY, 7, 2, 4,
        TIMESTAMP(@heute - INTERVAL 76 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 75 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 72 DAY, '07:30'), 26680,
        TIMESTAMP(@heute - INTERVAL 72 DAY, '16:15'), 26850, NULL),
    -- Larissa, Transit
    (103, 3, 5, 5, @heute - INTERVAL 75 DAY, @heute - INTERVAL 75 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 79 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 78 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 75 DAY, '07:30'), 111720,
        TIMESTAMP(@heute - INTERVAL 75 DAY, '16:15'), 111890, 'Ladefläche voller Sand.'),
    -- Lucie, Passat
    (104, 1, 1, 1, @heute - INTERVAL 78 DAY, @heute - INTERVAL 78 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 82 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 81 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 78 DAY, '07:30'), 47488,
        TIMESTAMP(@heute - INTERVAL 78 DAY, '16:15'), 47588, NULL),
    -- Lucie, Vespa
    (105, 7, 1, 1, @heute - INTERVAL 80 DAY, @heute - INTERVAL 80 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 84 DAY, '10:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 80 DAY, '07:30'), 6255,
        TIMESTAMP(@heute - INTERVAL 80 DAY, '16:15'), 6300, NULL),
    -- Larissa, Octavia
    (106, 2, 5, 5, @heute - INTERVAL 84 DAY, @heute - INTERVAL 84 DAY, 6, 1, 4,
        TIMESTAMP(@heute - INTERVAL 88 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 87 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 84 DAY, '07:30'), 9310,
        TIMESTAMP(@heute - INTERVAL 84 DAY, '16:15'), 9420, NULL),
    -- Kenneth, Passat
    (107, 1, 4, 4, @heute - INTERVAL 85 DAY, @heute - INTERVAL 85 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 89 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 88 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 85 DAY, '07:30'), 47395,
        TIMESTAMP(@heute - INTERVAL 85 DAY, '16:15'), 47488, NULL),
    -- Kenneth, Enyaq
    (108, 11, 4, 4, @heute - INTERVAL 86 DAY, @heute - INTERVAL 86 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 90 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 89 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 86 DAY, '07:30'), 17760,
        TIMESTAMP(@heute - INTERVAL 86 DAY, '16:15'), 17940, NULL),
    -- Kenneth, Tesla
    (109, 6, 4, 4, @heute - INTERVAL 90 DAY, @heute - INTERVAL 90 DAY, 7, 2, 4,
        TIMESTAMP(@heute - INTERVAL 94 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 93 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 90 DAY, '07:30'), 11230,
        TIMESTAMP(@heute - INTERVAL 90 DAY, '16:15'), 11420, NULL),
    -- Finn, Transit
    (110, 3, 3, 3, @heute - INTERVAL 96 DAY, @heute - INTERVAL 96 DAY, 1, 3, 4,
        TIMESTAMP(@heute - INTERVAL 100 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 99 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 96 DAY, '07:30'), 111540,
        TIMESTAMP(@heute - INTERVAL 96 DAY, '16:15'), 111720, NULL),
    -- Lucie, Octavia
    (111, 2, 1, 1, @heute - INTERVAL 100 DAY, @heute - INTERVAL 100 DAY, 7, 2, 4,
        TIMESTAMP(@heute - INTERVAL 104 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 103 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 100 DAY, '07:30'), 9180,
        TIMESTAMP(@heute - INTERVAL 100 DAY, '16:15'), 9310, NULL),
    -- Paul, Enyaq
    (112, 11, 7, 7, @heute - INTERVAL 100 DAY, @heute - INTERVAL 100 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 104 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 103 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 100 DAY, '07:30'), 17600,
        TIMESTAMP(@heute - INTERVAL 100 DAY, '16:15'), 17760, NULL),
    -- Larissa, ID.3
    (113, 5, 5, 5, @heute - INTERVAL 105 DAY, @heute - INTERVAL 105 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 109 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 108 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 105 DAY, '07:30'), 30880,
        TIMESTAMP(@heute - INTERVAL 105 DAY, '16:15'), 31020, NULL),
    -- Larissa, Ioniq 5
    (114, 12, 5, 5, @heute - INTERVAL 115 DAY, @heute - INTERVAL 115 DAY, 6, 1, 4,
        TIMESTAMP(@heute - INTERVAL 119 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 118 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 115 DAY, '07:30'), 26490,
        TIMESTAMP(@heute - INTERVAL 115 DAY, '16:15'), 26680, NULL),
    -- Kenneth, Transit
    (115, 3, 4, 4, @heute - INTERVAL 118 DAY, @heute - INTERVAL 118 DAY, 5, 2, 4,
        TIMESTAMP(@heute - INTERVAL 122 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 121 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 118 DAY, '07:30'), 111380,
        TIMESTAMP(@heute - INTERVAL 118 DAY, '16:15'), 111540, NULL),
    -- Finn, Octavia
    (116, 2, 3, 3, @heute - INTERVAL 120 DAY, @heute - INTERVAL 120 DAY, 3, 1, 4,
        TIMESTAMP(@heute - INTERVAL 124 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 123 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 120 DAY, '07:30'), 9050,
        TIMESTAMP(@heute - INTERVAL 120 DAY, '16:15'), 9180, NULL);


-- Schäden. Offen ist ein Schaden, solange behoben_am NULL ist. gemeldet_am ist
-- bei der Rückgabe zurueckgegeben_am, bei der Übernahme begonnen_am.
INSERT INTO schaeden
    (id, buchung_id, anlass_id, schwere_id, beschreibung, gemeldet_am, behoben_am, behoben_von)
VALUES
    -- offen; der Sprinter steht deshalb in Wartung
    (1, 36, 2, 2, 'Delle an der Schiebetür rechts, Tür schließt schwer.',
        TIMESTAMP(@heute - INTERVAL 13 DAY, '16:45'), NULL, NULL),
    -- offen; sieht Lucie bei Fahrtbeginn mit dem ID.3 (Buchung 13)
    (2, 47, 1, 1, 'Kratzer an der Stoßstange hinten links, schon vorhanden.',
        TIMESTAMP(@heute - INTERVAL 20 DAY, '08:10'), NULL, NULL),
    -- offen; sieht Larissa bei Fahrtbeginn mit dem Transit (Buchung 26)
    (3, 18, 1, 1, 'Kratzer an der Heckklappe, war schon vorhanden.',
        TIMESTAMP(@heute, '07:15'), NULL, NULL),
    -- offen; sehen Larissa und Ella bei Fahrtbeginn mit Rad 2 (Buchungen 27, 31)
    (4, 53, 2, 1, 'Klingel abgebrochen.',
        TIMESTAMP(@heute - INTERVAL 7 DAY, '13:40'), NULL, NULL),
    (5, 44, 2, 1, 'Steinschlag in der Windschutzscheibe, unten rechts.',
        TIMESTAMP(@heute - INTERVAL 36 DAY, '16:00'), TIMESTAMP(@heute - INTERVAL 30 DAY, '10:00'), 6),
    (6, 46, 2, 2, 'Außenspiegel links abgebrochen.',
        TIMESTAMP(@heute - INTERVAL 50 DAY, '17:30'), TIMESTAMP(@heute - INTERVAL 47 DAY, '11:00'), 6),
    -- offen; sieht Lucie heute bei Fahrtbeginn mit Rad 1 (Buchung 11)
    (7, 48, 2, 1, 'Schutzblech hinten leicht verbogen, schleift nicht.',
        TIMESTAMP(@heute - INTERVAL 5 DAY, '15:10'), NULL, NULL),
    -- Kleinschaden am Tesla: Bei einem Luxusauto sperrt er, daher Wartung 4;
    -- mit der Freigabe behoben
    (8, 41, 2, 1, 'Felge hinten rechts zerkratzt, Bordstein.',
        TIMESTAMP(@heute - INTERVAL 25 DAY, '18:10'), TIMESTAMP(@heute - INTERVAL 23 DAY, '15:00'), 6);


-- Wartungen. Laufend ist eine Wartung ohne freigegeben_am; die übrigen stehen
-- als Verlauf im Fahrtenbuch.
INSERT INTO wartungen
    (id, fahrzeug_id, schaden_id, grund, begonnen_am, voraussichtlich_bis, angelegt_von,
     freigegeben_am, freigegeben_von)
VALUES
    -- Sprinter, läuft: nach der Rückgabe mit Schaden 1, das Ende hat der
    -- Fuhrparkleiter danach festgelegt; Buchung 54 wurde deshalb storniert
    (1, 4, 1,    'Delle an der Schiebetür rechts, Tür schließt schwer.',
        @heute - INTERVAL 13 DAY, @heute + INTERVAL 4 DAY,  NULL, NULL,                     NULL),
    -- Transit, abgeschlossen: Schaden 6, mit der Freigabe behoben
    (2, 3, 6,    'Außenspiegel links abgebrochen.',
        @heute - INTERVAL 50 DAY, @heute - INTERVAL 47 DAY, NULL, @heute - INTERVAL 47 DAY, 6),
    -- Passat, abgeschlossen: Inspektion ohne Schaden
    (3, 1, NULL, 'Inspektion',
        @heute - INTERVAL 35 DAY, @heute - INTERVAL 34 DAY, 6,    @heute - INTERVAL 34 DAY, 6),
    -- Tesla, abgeschlossen: Kleinschaden 8 an einem Luxusauto sperrt
    (4, 6, 8,    'Felge hinten rechts zerkratzt, Bordstein.',
        @heute - INTERVAL 25 DAY, @heute - INTERVAL 23 DAY, NULL, @heute - INTERVAL 23 DAY, 6);
