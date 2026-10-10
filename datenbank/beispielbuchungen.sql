-- Beispielbuchungen der Fuhrpark-Verwaltung.
--
-- Im Anschluss an schema.sql und stammdaten.sql einspielen. Alle Daten sind
-- relativ zum heutigen Tag, damit jede Seite zu jedem Zeitpunkt alle Zustände
-- zeigt: offen, genehmigt, unterwegs (davon eine überfällig), abgeschlossen,
-- abgelehnt und storniert. Dazu Schäden: offen und behoben, Kleinschäden und
-- Schäden mit Wartung, gemeldet bei Rückgabe oder Übernahme.
--
-- Die IDs 11–24 entsprechen den Beispieldaten der Prototyp-Seiten. Die
-- km-Stände ergeben je Fahrzeug eine lückenlose Kette bis zu fahrzeuge.kmstand
-- aus stammdaten.sql. Schadensfotos fehlen, bis der Upload umgesetzt ist.
--
-- Nutzer: 1 Lucie, 2 Ella, 3 Finn, 4 Kenneth, 5 Larissa (Mitarbeiter),
-- 6 Jonas (Fuhrparkleiter).

SET NAMES utf8mb4;

USE fuhrpark;

SET @heute = CURDATE();


-- Offen, genehmigt, abgelehnt und storniert (Fahrt nicht begonnen).
-- Roller und Fahrräder sind sofort genehmigt, ohne Entscheidung.
INSERT INTO buchungen
    (id, fahrzeug_id, antragsteller_id, fahrer_id, start, ende, zweck, personenanzahl, status,
     beantragt_am, entscheidung_von, entscheidung_am, entscheidung_kommentar)
VALUES
    -- Lucie: heute mit Rad 1, kann beginnen
    (11, 8, 1, 1, @heute,                    @heute,                    'aufmass',           1, 'genehmigt',
        TIMESTAMP(@heute - INTERVAL 2 DAY, '09:10'), NULL, NULL, NULL),
    (13, 5, 1, 1, @heute + INTERVAL 2 DAY,   @heute + INTERVAL 2 DAY,   'kundentermin',      1, 'genehmigt',
        TIMESTAMP(@heute - INTERVAL 4 DAY, '11:20'), 6, TIMESTAMP(@heute - INTERVAL 4 DAY, '15:00'), NULL),
    -- Kenneth bucht für Lucie
    (14, 2, 4, 1, @heute + INTERVAL 5 DAY,   @heute + INTERVAL 6 DAY,   'montage',           3, 'offen',
        TIMESTAMP(@heute - INTERVAL 3 DAY, '08:45'), NULL, NULL, NULL),
    (15, 1, 1, 1, @heute + INTERVAL 10 DAY,  @heute + INTERVAL 11 DAY,  'lieferant',         2, 'offen',
        TIMESTAMP(@heute - INTERVAL 1 DAY, '10:05'), NULL, NULL, NULL),
    (16, 6, 1, 1, @heute + INTERVAL 3 DAY,   @heute + INTERVAL 3 DAY,   'kundentermin',      1, 'abgelehnt',
        TIMESTAMP(@heute - INTERVAL 4 DAY, '09:30'), 6, TIMESTAMP(@heute - INTERVAL 3 DAY, '10:15'),
        'Für Einzeltermine bitte den ID.3 oder ein E-Fahrrad nutzen.'),
    -- war genehmigt, dann storniert
    (17, 3, 1, 1, @heute - INTERVAL 6 DAY,   @heute - INTERVAL 6 DAY,   'materialtransport', 2, 'storniert',
        TIMESTAMP(@heute - INTERVAL 12 DAY, '13:00'), 6, TIMESTAMP(@heute - INTERVAL 11 DAY, '09:00'), NULL),
    (19, 3, 4, 4, @heute + INTERVAL 3 DAY,   @heute + INTERVAL 4 DAY,   'materialtransport', 2, 'offen',
        TIMESTAMP(@heute - INTERVAL 5 DAY, '14:12'), NULL, NULL, NULL),
    (20, 6, 4, 4, @heute + INTERVAL 8 DAY,   @heute + INTERVAL 9 DAY,   'aufmass',           1, 'offen',
        TIMESTAMP(@heute - INTERVAL 2 DAY, '16:30'), NULL, NULL, NULL),
    -- Ella: heute, kann wegen ihrer überfälligen Tesla-Rückgabe nicht beginnen
    (21, 1, 2, 2, @heute,                    @heute,                    'kundentermin',      2, 'genehmigt',
        TIMESTAMP(@heute - INTERVAL 6 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 6 DAY, '14:30'), NULL),
    (25, 1, 3, 3, @heute + INTERVAL 4 DAY,   @heute + INTERVAL 6 DAY,   'service',           1, 'genehmigt',
        TIMESTAMP(@heute - INTERVAL 8 DAY, '08:15'), 6, TIMESTAMP(@heute - INTERVAL 7 DAY, '09:40'), NULL),
    (26, 3, 5, 5, @heute + INTERVAL 7 DAY,   @heute + INTERVAL 8 DAY,   'materialtransport', 2, 'genehmigt',
        TIMESTAMP(@heute - INTERVAL 5 DAY, '11:00'), 6, TIMESTAMP(@heute - INTERVAL 4 DAY, '08:30'), NULL),
    (27, 9, 5, 5, @heute + INTERVAL 3 DAY,   @heute + INTERVAL 4 DAY,   'aufmass',           1, 'genehmigt',
        TIMESTAMP(@heute - INTERVAL 3 DAY, '12:00'), NULL, NULL, NULL),
    -- Ella: genehmigt, aber ihre Tesla-Rückgabe ist überfällig
    (31, 9, 2, 2, @heute + INTERVAL 6 DAY,   @heute + INTERVAL 6 DAY,   'kundentermin',      1, 'genehmigt',
        TIMESTAMP(@heute - INTERVAL 1 DAY, '09:00'), NULL, NULL, NULL),
    -- Larissa bucht für Ella; fünfter offener Antrag
    (32, 2, 5, 2, @heute + INTERVAL 9 DAY,   @heute + INTERVAL 9 DAY,   'montage',           3, 'offen',
        TIMESTAMP(@heute - INTERVAL 1 DAY, '15:20'), NULL, NULL, NULL),
    (33, 9, 5, 5, @heute + INTERVAL 1 DAY,   @heute + INTERVAL 1 DAY,   'aufmass',           1, 'storniert',
        TIMESTAMP(@heute - INTERVAL 6 DAY, '08:00'), NULL, NULL, NULL),
    (34, 5, 4, 4, @heute - INTERVAL 4 DAY,   @heute - INTERVAL 4 DAY,   'kundentermin',      2, 'abgelehnt',
        TIMESTAMP(@heute - INTERVAL 7 DAY, '10:30'), 6, TIMESTAMP(@heute - INTERVAL 6 DAY, '08:50'),
        'Der ID.3 ist an dem Tag zur Inspektion angemeldet.'),
    -- automatisch storniert, als der Sprinter in Wartung ging
    (54, 4, 5, 5, @heute + INTERVAL 2 DAY,   @heute + INTERVAL 3 DAY,   'materialtransport', 2, 'storniert',
        TIMESTAMP(@heute - INTERVAL 15 DAY, '09:30'), 6, TIMESTAMP(@heute - INTERVAL 13 DAY, '16:50'),
        'Automatisch storniert: Das Fahrzeug ist in diesem Zeitraum in Wartung.');


-- Unterwegs. km_start ist der km-Stand des Fahrzeugs bei Fahrtbeginn.
INSERT INTO buchungen
    (id, fahrzeug_id, antragsteller_id, fahrer_id, start, ende, zweck, personenanzahl, status,
     beantragt_am, entscheidung_von, entscheidung_am, begonnen_am, km_start)
VALUES
    -- Lucie: heute fällig, nicht überfällig
    (12, 7, 1, 1, @heute - INTERVAL 2 DAY,   @heute,                    'kundentermin',      1, 'unterwegs',
        TIMESTAMP(@heute - INTERVAL 5 DAY, '09:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 2 DAY, '08:00'), 6400),
    -- fristgerecht unterwegs; Kratzer bei Übernahme notiert (Schaden 3)
    (18, 3, 4, 4, @heute,                    @heute + INTERVAL 2 DAY,   'montage',           3, 'unterwegs',
        TIMESTAMP(@heute - INTERVAL 6 DAY, '10:20'), 6, TIMESTAMP(@heute - INTERVAL 5 DAY, '09:15'),
        TIMESTAMP(@heute, '07:15'), 112400),
    -- Ella: überfällig seit vorgestern
    (30, 6, 2, 2, @heute - INTERVAL 3 DAY,   @heute - INTERVAL 2 DAY,   'kundentermin',      1, 'unterwegs',
        TIMESTAMP(@heute - INTERVAL 9 DAY, '14:00'), 6, TIMESTAMP(@heute - INTERVAL 8 DAY, '08:20'),
        TIMESTAMP(@heute - INTERVAL 3 DAY, '07:45'), 12020);


-- Abgeschlossen: das Fahrtenbuch. Neueste zuerst. Fahrräder ohne km.
-- Autos und Transporter sind vom Fuhrparkleiter genehmigt (entscheidung_von = 6).
INSERT INTO buchungen
    (id, fahrzeug_id, antragsteller_id, fahrer_id, start, ende, zweck, personenanzahl, status,
     beantragt_am, entscheidung_von, entscheidung_am, begonnen_am, km_start,
     zurueckgegeben_am, km_ende, bemerkung)
VALUES
    -- Kleinschaden (Schaden 7)
    (48, 8, 2, 2, @heute - INTERVAL 5 DAY,   @heute - INTERVAL 5 DAY,   'aufmass',           1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 7 DAY, '16:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '08:30'), NULL,
        TIMESTAMP(@heute - INTERVAL 5 DAY, '15:10'), NULL, NULL),
    -- Kleinschaden (Schaden 4)
    (53, 9, 4, 4, @heute - INTERVAL 7 DAY,   @heute - INTERVAL 7 DAY,   'kundentermin',      1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 8 DAY, '11:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '09:00'), NULL,
        TIMESTAMP(@heute - INTERVAL 7 DAY, '13:40'), NULL, NULL),
    (22, 5, 1, 1, @heute - INTERVAL 9 DAY,   @heute - INTERVAL 9 DAY,   'kundentermin',      1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 13 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 12 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 9 DAY, '08:00'), 31494,
        TIMESTAMP(@heute - INTERVAL 9 DAY, '16:20'), 31540, NULL),
    (23, 8, 1, 1, @heute - INTERVAL 10 DAY,  @heute - INTERVAL 10 DAY,  'aufmass',           1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 11 DAY, '14:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 10 DAY, '08:15'), NULL,
        TIMESTAMP(@heute - INTERVAL 10 DAY, '14:30'), NULL, 'Akku nach Rückkehr wieder angeschlossen.'),
    (35, 1, 4, 4, @heute - INTERVAL 11 DAY,  @heute - INTERVAL 11 DAY,  'kundentermin',      2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 15 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 14 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 11 DAY, '07:45'), 48166,
        TIMESTAMP(@heute - INTERVAL 11 DAY, '17:05'), 48250, NULL),
    -- Schaden mit Wartung (Schaden 1): Sprinter steht deshalb in Wartung (stammdaten.sql)
    (36, 4, 5, 5, @heute - INTERVAL 15 DAY,  @heute - INTERVAL 13 DAY,  'montage',           3, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 19 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 18 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 15 DAY, '07:00'), 87167,
        TIMESTAMP(@heute - INTERVAL 13 DAY, '16:45'), 87310, NULL),
    (37, 3, 2, 2, @heute - INTERVAL 14 DAY,  @heute - INTERVAL 14 DAY,  'montage',           2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 18 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 17 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 14 DAY, '07:30'), 112304,
        TIMESTAMP(@heute - INTERVAL 14 DAY, '16:00'), 112400, NULL),
    (38, 1, 5, 5, @heute - INTERVAL 16 DAY,  @heute - INTERVAL 16 DAY,  'aufmass',           1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 20 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 19 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 16 DAY, '08:00'), 48034,
        TIMESTAMP(@heute - INTERVAL 16 DAY, '15:30'), 48166, 'Klappergeräusch hinten rechts bei Tempo über 100.'),
    (39, 3, 4, 4, @heute - INTERVAL 18 DAY,  @heute - INTERVAL 18 DAY,  'materialtransport', 2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 22 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 21 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 18 DAY, '07:15'), 112246,
        TIMESTAMP(@heute - INTERVAL 18 DAY, '14:50'), 112304, 'Ladefläche verschmutzt, Spanngurt fehlt.'),
    -- vorhandener Kratzer bei Übernahme notiert (Schaden 2)
    (47, 5, 4, 4, @heute - INTERVAL 20 DAY,  @heute - INTERVAL 20 DAY,  'kundentermin',      2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 24 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 23 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 20 DAY, '08:10'), 31402,
        TIMESTAMP(@heute - INTERVAL 20 DAY, '15:00'), 31494, NULL),
    (40, 1, 3, 3, @heute - INTERVAL 22 DAY,  @heute - INTERVAL 22 DAY,  'lieferant',         1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 26 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 25 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 22 DAY, '07:30'), 47824,
        TIMESTAMP(@heute - INTERVAL 22 DAY, '17:20'), 48034, 'Innenraum könnte mal gereinigt werden.'),
    (41, 6, 2, 2, @heute - INTERVAL 25 DAY,  @heute - INTERVAL 25 DAY,  'schulung',          2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 29 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 28 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 25 DAY, '07:00'), 11832,
        TIMESTAMP(@heute - INTERVAL 25 DAY, '18:10'), 12020, NULL),
    (42, 9, 5, 5, @heute - INTERVAL 28 DAY,  @heute - INTERVAL 28 DAY,  'aufmass',           1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 30 DAY, '12:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 28 DAY, '09:00'), NULL,
        TIMESTAMP(@heute - INTERVAL 28 DAY, '12:30'), NULL, NULL),
    (49, 4, 4, 4, @heute - INTERVAL 29 DAY,  @heute - INTERVAL 29 DAY,  'materialtransport', 2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 33 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 32 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 29 DAY, '07:00'), 86980,
        TIMESTAMP(@heute - INTERVAL 29 DAY, '16:30'), 87167, NULL),
    (43, 7, 3, 3, @heute - INTERVAL 30 DAY,  @heute - INTERVAL 30 DAY,  'kundentermin',      1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 31 DAY, '15:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 30 DAY, '09:30'), 6378,
        TIMESTAMP(@heute - INTERVAL 30 DAY, '12:15'), 6400, NULL),
    -- Kleinschaden, inzwischen behoben (Schaden 5)
    (44, 2, 4, 4, @heute - INTERVAL 37 DAY,  @heute - INTERVAL 36 DAY,  'service',           1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 41 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 40 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 37 DAY, '07:30'), 9605,
        TIMESTAMP(@heute - INTERVAL 36 DAY, '16:00'), 9870, NULL),
    (50, 7, 1, 1, @heute - INTERVAL 40 DAY,  @heute - INTERVAL 40 DAY,  'kundentermin',      1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 42 DAY, '09:00'), NULL, NULL,
        TIMESTAMP(@heute - INTERVAL 40 DAY, '10:00'), 6341,
        TIMESTAMP(@heute - INTERVAL 40 DAY, '13:00'), 6378, 'Helmfach klemmt beim Öffnen.'),
    (45, 1, 2, 2, @heute - INTERVAL 44 DAY,  @heute - INTERVAL 44 DAY,  'kundentermin',      2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 48 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 47 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 44 DAY, '08:00'), 47706,
        TIMESTAMP(@heute - INTERVAL 44 DAY, '16:40'), 47824, NULL),
    -- Schaden mit Wartung, nach der Reparatur freigegeben (Schaden 6)
    (46, 3, 3, 3, @heute - INTERVAL 51 DAY,  @heute - INTERVAL 50 DAY,  'montage',           3, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 55 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 54 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 51 DAY, '07:00'), 112072,
        TIMESTAMP(@heute - INTERVAL 50 DAY, '17:30'), 112246, 'Rückfahrkamera zeigt zeitweise kein Bild.'),
    (24, 2, 1, 1, @heute - INTERVAL 57 DAY,  @heute - INTERVAL 57 DAY,  'lieferant',         1, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 61 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 60 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 57 DAY, '08:00'), 9508,
        TIMESTAMP(@heute - INTERVAL 57 DAY, '15:45'), 9605, NULL),
    (51, 1, 5, 5, @heute - INTERVAL 64 DAY,  @heute - INTERVAL 64 DAY,  'montage',           3, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 68 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 67 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 64 DAY, '07:00'), 47588,
        TIMESTAMP(@heute - INTERVAL 64 DAY, '16:15'), 47706, NULL),
    (52, 2, 3, 3, @heute - INTERVAL 71 DAY,  @heute - INTERVAL 70 DAY,  'schulung',          2, 'abgeschlossen',
        TIMESTAMP(@heute - INTERVAL 75 DAY, '10:00'), 6, TIMESTAMP(@heute - INTERVAL 74 DAY, '09:00'),
        TIMESTAMP(@heute - INTERVAL 71 DAY, '07:30'), 9420,
        TIMESTAMP(@heute - INTERVAL 70 DAY, '18:00'), 9508, NULL);


-- Schäden. Offen ist ein Schaden, solange behoben_am NULL ist. gemeldet_am ist
-- bei der Rückgabe zurueckgegeben_am, bei der Übernahme begonnen_am.
INSERT INTO schaeden
    (id, buchung_id, anlass, schwere, beschreibung, gemeldet_am, behoben_am, behoben_von)
VALUES
    -- offen; der Sprinter steht deshalb in Wartung
    (1, 36, 'rueckgabe',  'wartung', 'Delle an der Schiebetür rechts, Tür schließt schwer.',
        TIMESTAMP(@heute - INTERVAL 13 DAY, '16:45'), NULL, NULL),
    -- offen; sieht Lucie bei Fahrtbeginn mit dem ID.3 (Buchung 13)
    (2, 47, 'uebernahme', 'klein',   'Kratzer an der Stoßstange hinten links, schon vorhanden.',
        TIMESTAMP(@heute - INTERVAL 20 DAY, '08:10'), NULL, NULL),
    -- offen; sieht Larissa bei Fahrtbeginn mit dem Transit (Buchung 26)
    (3, 18, 'uebernahme', 'klein',   'Kratzer an der Heckklappe, war schon vorhanden.',
        TIMESTAMP(@heute, '07:15'), NULL, NULL),
    -- offen; sehen Larissa und Ella bei Fahrtbeginn mit Rad 2 (Buchungen 27, 31)
    (4, 53, 'rueckgabe',  'klein',   'Klingel abgebrochen.',
        TIMESTAMP(@heute - INTERVAL 7 DAY, '13:40'), NULL, NULL),
    (5, 44, 'rueckgabe',  'klein',   'Steinschlag in der Windschutzscheibe, unten rechts.',
        TIMESTAMP(@heute - INTERVAL 36 DAY, '16:00'), TIMESTAMP(@heute - INTERVAL 30 DAY, '10:00'), 6),
    (6, 46, 'rueckgabe',  'wartung', 'Außenspiegel links abgebrochen.',
        TIMESTAMP(@heute - INTERVAL 50 DAY, '17:30'), TIMESTAMP(@heute - INTERVAL 47 DAY, '11:00'), 6),
    -- offen; sieht Lucie heute bei Fahrtbeginn mit Rad 1 (Buchung 11)
    (7, 48, 'rueckgabe',  'klein',   'Schutzblech hinten leicht verbogen, schleift nicht.',
        TIMESTAMP(@heute - INTERVAL 5 DAY, '15:10'), NULL, NULL);


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
        @heute - INTERVAL 35 DAY, @heute - INTERVAL 34 DAY, 6,    @heute - INTERVAL 34 DAY, 6);
