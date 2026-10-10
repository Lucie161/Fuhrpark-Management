<?php
/**
 * Beispieldaten des Prototyps, an einer Stelle für alle Seiten.
 *
 * Genaues Abbild von datenbank/stammdaten.sql und
 * datenbank/beispielbuchungen.sql: dieselben IDs, Tage relativ zu heute.
 * Die Schlüssel heißen wie die Spalten der Datenbank, Datumswerte sind
 * DateTimeImmutable.
 *
 * Beim Umstieg auf die Datenbank werden die Seiten auf Queries umgestellt
 * (die vorgesehenen stehen jeweils im Kopf der Seite); danach entfallen diese
 * Datei und ihre Einbindung in config.php.
 */

declare(strict_types=1);

/**
 * Nutzer mit Rolle, Passwort-Hash und Führerscheinklassen (nutzer,
 * nutzer_fuehrerscheine). Das Passwort aller Testnutzer ist „fuhrpark“.
 */
function beispiel_nutzer(): array
{
    $hash = '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.';

    return [
        1 => ['vorname' => 'Lucie',   'nachname' => 'Schneider', 'rolle' => 'mitarbeiter',    'passwort_hash' => $hash, 'fuehrerscheine' => ['B', 'A1']],
        2 => ['vorname' => 'Ella',    'nachname' => 'Luppold',   'rolle' => 'mitarbeiter',    'passwort_hash' => $hash, 'fuehrerscheine' => ['B']],
        3 => ['vorname' => 'Finn',    'nachname' => 'Clausen',   'rolle' => 'mitarbeiter',    'passwort_hash' => $hash, 'fuehrerscheine' => ['B', 'A1']],
        4 => ['vorname' => 'Kenneth', 'nachname' => 'Sander',    'rolle' => 'mitarbeiter',    'passwort_hash' => $hash, 'fuehrerscheine' => ['B', 'C1']],
        5 => ['vorname' => 'Larissa', 'nachname' => 'Wagner',    'rolle' => 'mitarbeiter',    'passwort_hash' => $hash, 'fuehrerscheine' => ['B', 'C1']],
        6 => ['vorname' => 'Jonas',   'nachname' => 'Weber',     'rolle' => 'fuhrparkleiter', 'passwort_hash' => $hash, 'fuehrerscheine' => []],
    ];
}

/**
 * Fahrzeuge (fahrzeuge). hu_au ist der Monatserste des fälligen Monats.
 *
 * 'status' und 'wartung_bis' stehen nicht in der Tabelle, sondern ergeben
 * sich aus der laufenden Wartung (wie „unterwegs“ aus den Buchungen):
 * 'status' ist 'wartung' oder 'verfuegbar', 'wartung_bis' das voraussichtliche
 * Ende (DateTimeImmutable), null ohne Wartung oder solange das Ende offen ist.
 *   SELECT f.*, w.voraussichtlich_bis AS wartung_bis,
 *          IF(w.id IS NULL, 'verfuegbar', 'wartung') AS status
 *     FROM fahrzeuge f
 *     LEFT JOIN wartungen w ON w.fahrzeug_id = f.id AND w.freigegeben_am IS NULL
 */
function beispiel_fahrzeuge(): array
{
    $spalten = ['kennzeichen', 'art', 'typ', 'hersteller', 'modell', 'baujahr', 'bild',
                'sitzplaetze', 'antrieb', 'kmstand', 'fuehrerschein', 'hu_au'];

    $zeilen = [
        1 => ['M-HS 101',  'auto',        'Kombi',       'Volkswagen',     'Passat Variant', 2021, 'vw-passat-variant.jpg',     5, 'diesel',  48250,  'B',  '2027-03-01'],
        2 => ['M-HS 102',  'auto',        'Kombi',       'Škoda',          'Octavia Combi',  2023, 'skoda-octavia-combi.jpg',   5, 'benzin',  9870,   'B',  '2026-05-01'],
        3 => ['M-HS 201',  'transporter', 'Transporter', 'Ford',           'Transit',        2019, 'ford-transit.jpg',          3, 'diesel',  112400, 'B',  '2026-11-01'],
        4 => ['M-HS 202',  'transporter', 'Transporter', 'Mercedes-Benz',  'Sprinter',       2020, 'mercedes-sprinter.jpg',     3, 'diesel',  87310,  'C1', '2026-10-01'],
        5 => ['M-HS 301E', 'auto',        'E-Auto',      'Volkswagen',     'ID.3',           2022, 'vw-id3.jpg',                5, 'elektro', 31540,  'B',  '2027-08-01'],
        6 => ['M-HS 302E', 'auto',        'E-Auto',      'Tesla',          'Model 3',        2024, 'tesla-model-3.jpg',         5, 'elektro', 12020,  'B',  '2027-02-01'],
        7 => ['M-HS 401',  'roller',      'Roller',      'Vespa',          'Primavera 125',  2022, 'vespa-primavera.jpg',       2, 'benzin',  6400,   'A1', '2027-06-01'],
        8 => ['Rad 1',     'fahrrad',     'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, 'riese-mueller-charger.jpg', 1, 'elektro', null,   null, null],
        9 => ['Rad 2',     'fahrrad',     'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, 'riese-mueller-charger.jpg', 1, 'elektro', null,   null, null],
    ];

    $fahrzeuge = [];

    foreach ($zeilen as $id => $zeile) {
        $fahrzeuge[$id] = array_combine($spalten, $zeile) + ['status' => 'verfuegbar', 'wartung_bis' => null];
    }

    foreach (beispiel_wartungen() as $wartung) {
        if ($wartung['freigegeben_am'] === null) {
            $fahrzeuge[$wartung['fahrzeug_id']]['status']      = 'wartung';
            $fahrzeuge[$wartung['fahrzeug_id']]['wartung_bis'] = $wartung['voraussichtlich_bis'];
        }
    }

    return $fahrzeuge;
}

/**
 * Wartungen (wartungen), nach ID. Tage relativ zu heute. Laufend ist eine
 * Wartung ohne 'freigegeben_am'; die übrigen stehen als Verlauf im
 * Fahrtenbuch. 'voraussichtlich_bis' null heißt „Ende noch offen“.
 */
function beispiel_wartungen(): array
{
    $spalten = ['fahrzeug_id', 'schaden_id', 'grund', 'begonnen', 'bis', 'freigegeben'];

    $zeilen = [
        // Sprinter, läuft (Schaden 1); Buchung 54 wurde deshalb storniert
        1 => [4, 1,    'Delle an der Schiebetür rechts, Tür schließt schwer.', -13, 4,   null],
        // Transit, abgeschlossen (Schaden 6, mit der Freigabe behoben)
        2 => [3, 6,    'Außenspiegel links abgebrochen.',                      -50, -47, -47],
        // Passat, abgeschlossen, ohne Schaden
        3 => [1, null, 'Inspektion',                                           -35, -34, -34],
    ];

    $heute = new DateTimeImmutable('today');
    $wartungen = [];

    foreach ($zeilen as $id => $zeile) {
        $w = array_combine($spalten, $zeile);

        $wartungen[$id] = [
            'id'                  => $id,
            'fahrzeug_id'         => $w['fahrzeug_id'],
            'schaden_id'          => $w['schaden_id'],
            'grund'               => $w['grund'],
            'begonnen_am'         => $heute->modify($w['begonnen'] . ' day'),
            'voraussichtlich_bis' => $w['bis'] !== null ? $heute->modify($w['bis'] . ' day') : null,
            'freigegeben_am'      => $w['freigegeben'] !== null ? $heute->modify($w['freigegeben'] . ' day') : null,
        ];
    }

    return $wartungen;
}

/**
 * Alle Buchungen (buchungen), nach ID. 'start' und 'ende' sind Tage relativ
 * zu heute, 'beantragt' ist Tag und Uhrzeit des Antrags. Fahrt beginnen und
 * Rückgabe liegen im Prototyp am ersten bzw. letzten Tag der Buchung.
 */
function beispiel_buchungen(): array
{
    $spalten = ['fahrzeug_id', 'antragsteller_id', 'fahrer_id', 'start', 'ende', 'zweck',
                'personenanzahl', 'status', 'beantragt', 'entscheidung_kommentar',
                'km_start', 'km_ende', 'bemerkung'];

    $zeilen = [
        // Offen, genehmigt, abgelehnt, storniert
        11 => [8, 1, 1,   0,   0, 'aufmass',           1, 'genehmigt',     '-2 09:10'],
        13 => [5, 1, 1,   2,   2, 'kundentermin',      1, 'genehmigt',     '-4 11:20'],
        14 => [2, 4, 1,   5,   6, 'montage',           3, 'offen',         '-3 08:45'],
        15 => [1, 1, 1,  10,  11, 'lieferant',         2, 'offen',         '-1 10:05'],
        16 => [6, 1, 1,   3,   3, 'kundentermin',      1, 'abgelehnt',     '-4 09:30', 'Für Einzeltermine bitte den ID.3 oder ein E-Fahrrad nutzen.'],
        17 => [3, 1, 1,  -6,  -6, 'materialtransport', 2, 'storniert',     '-12 13:00'],
        19 => [3, 4, 4,   3,   4, 'materialtransport', 2, 'offen',         '-5 14:12'],
        20 => [6, 4, 4,   8,   9, 'aufmass',           1, 'offen',         '-2 16:30'],
        21 => [1, 2, 2,   0,   0, 'kundentermin',      2, 'genehmigt',     '-6 10:00'],
        25 => [1, 3, 3,   4,   6, 'service',           1, 'genehmigt',     '-8 08:15'],
        26 => [3, 5, 5,   7,   8, 'materialtransport', 2, 'genehmigt',     '-5 11:00'],
        27 => [9, 5, 5,   3,   4, 'aufmass',           1, 'genehmigt',     '-3 12:00'],
        31 => [9, 2, 2,   6,   6, 'kundentermin',      1, 'genehmigt',     '-1 09:00'],
        32 => [2, 5, 2,   9,   9, 'montage',           3, 'offen',         '-1 15:20'],
        33 => [9, 5, 5,   1,   1, 'aufmass',           1, 'storniert',     '-6 08:00'],
        34 => [5, 4, 4,  -4,  -4, 'kundentermin',      2, 'abgelehnt',     '-7 10:30', 'Der ID.3 ist an dem Tag zur Inspektion angemeldet.'],
        // automatisch storniert, als der Sprinter in Wartung ging
        54 => [4, 5, 5,   2,   3, 'materialtransport', 2, 'storniert',     '-15 09:30', 'Automatisch storniert: Das Fahrzeug ist in diesem Zeitraum in Wartung.'],

        // Unterwegs; Ella (30) ist überfällig
        12 => [7, 1, 1,  -2,   0, 'kundentermin',      1, 'unterwegs',     '-5 09:00',  null, 6400],
        18 => [3, 4, 4,   0,   2, 'montage',           3, 'unterwegs',     '-6 10:20',  null, 112400],
        30 => [6, 2, 2,  -3,  -2, 'kundentermin',      1, 'unterwegs',     '-9 14:00',  null, 12020],

        // Abgeschlossen: das Fahrtenbuch
        48 => [8, 2, 2,  -5,  -5, 'aufmass',           1, 'abgeschlossen', '-7 16:00'],
        53 => [9, 4, 4,  -7,  -7, 'kundentermin',      1, 'abgeschlossen', '-8 11:00'],
        22 => [5, 1, 1,  -9,  -9, 'kundentermin',      1, 'abgeschlossen', '-13 10:00', null, 31494,  31540],
        23 => [8, 1, 1, -10, -10, 'aufmass',           1, 'abgeschlossen', '-11 14:00', null, null,   null,   'Akku nach Rückkehr wieder angeschlossen.'],
        35 => [1, 4, 4, -11, -11, 'kundentermin',      2, 'abgeschlossen', '-15 10:00', null, 48166,  48250],
        36 => [4, 5, 5, -15, -13, 'montage',           3, 'abgeschlossen', '-19 10:00', null, 87167,  87310],
        37 => [3, 2, 2, -14, -14, 'montage',           2, 'abgeschlossen', '-18 10:00', null, 112304, 112400],
        38 => [1, 5, 5, -16, -16, 'aufmass',           1, 'abgeschlossen', '-20 10:00', null, 48034,  48166,  'Klappergeräusch hinten rechts bei Tempo über 100.'],
        39 => [3, 4, 4, -18, -18, 'materialtransport', 2, 'abgeschlossen', '-22 10:00', null, 112246, 112304, 'Ladefläche verschmutzt, Spanngurt fehlt.'],
        47 => [5, 4, 4, -20, -20, 'kundentermin',      2, 'abgeschlossen', '-24 10:00', null, 31402,  31494],
        40 => [1, 3, 3, -22, -22, 'lieferant',         1, 'abgeschlossen', '-26 10:00', null, 47824,  48034,  'Innenraum könnte mal gereinigt werden.'],
        41 => [6, 2, 2, -25, -25, 'schulung',          2, 'abgeschlossen', '-29 10:00', null, 11832,  12020],
        42 => [9, 5, 5, -28, -28, 'aufmass',           1, 'abgeschlossen', '-30 12:00'],
        49 => [4, 4, 4, -29, -29, 'materialtransport', 2, 'abgeschlossen', '-33 10:00', null, 86980,  87167],
        43 => [7, 3, 3, -30, -30, 'kundentermin',      1, 'abgeschlossen', '-31 15:00', null, 6378,   6400],
        44 => [2, 4, 4, -37, -36, 'service',           1, 'abgeschlossen', '-41 10:00', null, 9605,   9870],
        50 => [7, 1, 1, -40, -40, 'kundentermin',      1, 'abgeschlossen', '-42 09:00', null, 6341,   6378,   'Helmfach klemmt beim Öffnen.'],
        45 => [1, 2, 2, -44, -44, 'kundentermin',      2, 'abgeschlossen', '-48 10:00', null, 47706,  47824],
        46 => [3, 3, 3, -51, -50, 'montage',           3, 'abgeschlossen', '-55 10:00', null, 112072, 112246, 'Rückfahrkamera zeigt zeitweise kein Bild.'],
        24 => [2, 1, 1, -57, -57, 'lieferant',         1, 'abgeschlossen', '-61 10:00', null, 9508,   9605],
        51 => [1, 5, 5, -64, -64, 'montage',           3, 'abgeschlossen', '-68 10:00', null, 47588,  47706],
        52 => [2, 3, 3, -71, -70, 'schulung',          2, 'abgeschlossen', '-75 10:00', null, 9420,   9508],
    ];

    $heute = new DateTimeImmutable('today');
    $buchungen = [];

    foreach ($zeilen as $id => $zeile) {
        $b = array_combine($spalten, array_pad($zeile, count($spalten), null));

        [$tag, $uhrzeit] = explode(' ', $b['beantragt']);
        unset($b['beantragt']);

        $b['id']           = $id;
        $b['start']        = $heute->modify($b['start'] . ' day');
        $b['ende']         = $heute->modify($b['ende'] . ' day');
        $b['beantragt_am'] = new DateTimeImmutable($heute->modify($tag . ' day')->format('Y-m-d') . ' ' . $uhrzeit);
        $b['entscheidung_kommentar'] ??= '';
        $b['bemerkung'] ??= '';
        $b['begonnen_am'] = in_array($b['status'], ['unterwegs', 'abgeschlossen'], true) ? $b['start'] : null;
        $b['zurueckgegeben_am'] = $b['status'] === 'abgeschlossen' ? $b['ende'] : null;

        $buchungen[$id] = $b;
    }

    return $buchungen;
}

/**
 * Schäden (schaeden), nach ID. 'gemeldet' und 'behoben' sind Tage relativ zu
 * heute; 'fotos' sind Dateinamen in uploads/schaeden/ (im Prototyp ohne
 * Dateien, die Seite zeigt Platzhalter).
 */
function beispiel_schaeden(): array
{
    $spalten = ['buchung_id', 'anlass', 'schwere', 'beschreibung', 'gemeldet', 'behoben', 'fotos'];

    $zeilen = [
        1 => [36, 'rueckgabe',  'wartung', 'Delle an der Schiebetür rechts, Tür schließt schwer.',      -13, null,
              ['3f9c1a7e5b2d4086a1c7e9f03b6d2a58.jpg', 'b81e04d9c67a2f3e5d1b8a09c4f7e263.jpg']],
        2 => [47, 'uebernahme', 'klein',   'Kratzer an der Stoßstange hinten links, schon vorhanden.', -20, null, []],
        3 => [18, 'uebernahme', 'klein',   'Kratzer an der Heckklappe, war schon vorhanden.',          0,   null, []],
        4 => [53, 'rueckgabe',  'klein',   'Klingel abgebrochen.',                                     -7,  null, []],
        5 => [44, 'rueckgabe',  'klein',   'Steinschlag in der Windschutzscheibe, unten rechts.',      -36, -30,  []],
        6 => [46, 'rueckgabe',  'wartung', 'Außenspiegel links abgebrochen.',                          -50, -47,  []],
        // Lucie sieht ihn heute beim Fahrtbeginn mit Rad 1 (Buchung 11).
        7 => [48, 'rueckgabe',  'klein',   'Schutzblech hinten leicht verbogen, schleift nicht.',      -5,  null, []],
    ];

    $heute = new DateTimeImmutable('today');
    $buchungen = beispiel_buchungen();
    $schaeden = [];

    foreach ($zeilen as $id => $zeile) {
        $s = array_combine($spalten, $zeile);

        $s['id']          = $id;
        $s['fahrzeug_id'] = $buchungen[$s['buchung_id']]['fahrzeug_id'];
        $s['fahrer_id']   = $buchungen[$s['buchung_id']]['fahrer_id'];
        $s['gemeldet_am'] = $heute->modify($s['gemeldet'] . ' day');
        $s['behoben_am']  = $s['behoben'] !== null ? $heute->modify($s['behoben'] . ' day') : null;
        unset($s['gemeldet'], $s['behoben']);

        $schaeden[$id] = $s;
    }

    return $schaeden;
}

// --- Abfragen, die später Queries werden --------------------------------------

/**
 * Vor- und Nachname eines Nutzers.
 */
function nutzer_name(int $id): string
{
    $nutzer = beispiel_nutzer()[$id] ?? null;

    return $nutzer !== null ? $nutzer['vorname'] . ' ' . $nutzer['nachname'] : 'unbekannt';
}

/**
 * Alle Nutzer für eine Auswahlliste: ID => „Vorname Nachname“, nach
 * Nachname sortiert.
 *   SELECT id, vorname, nachname FROM nutzer ORDER BY nachname, vorname
 */
function nutzer_auswahl(): array
{
    $nutzer = beispiel_nutzer();
    uasort($nutzer, fn (array $a, array $b): int => [$a['nachname'], $a['vorname']] <=> [$b['nachname'], $b['vorname']]);

    return array_map(fn (array $n): string => $n['vorname'] . ' ' . $n['nachname'], $nutzer);
}

/**
 * „Hersteller Modell (Kennzeichen)“ eines Fahrzeugs.
 */
function fahrzeug_name(int $id): string
{
    $f = beispiel_fahrzeuge()[$id] ?? null;

    return $f !== null ? $f['hersteller'] . ' ' . $f['modell'] . ' (' . $f['kennzeichen'] . ')' : 'unbekannt';
}

/**
 * Überfällige Rückgaben: unterwegs, Ende vor heute.
 *   SELECT * FROM buchungen WHERE status = 'unterwegs' AND ende < CURDATE()
 */
function ueberfaellige_rueckgaben(): array
{
    $heute = new DateTimeImmutable('today');

    return array_filter(
        beispiel_buchungen(),
        fn (array $b): bool => $b['status'] === 'unterwegs' && $b['ende'] < $heute,
    );
}

/**
 * Hat der Nutzer als Fahrer eine überfällige Rückgabe? Dann darf er nichts
 * neu buchen und keine Fahrt beginnen (Regel 12 in docs/aenderungen-todo2.md).
 */
function hat_ueberfaellige_rueckgabe(int $nutzerId): bool
{
    foreach (ueberfaellige_rueckgaben() as $b) {
        if ($b['fahrer_id'] === $nutzerId) {
            return true;
        }
    }

    return false;
}

/**
 * Ist die Rückgabe des Fahrzeugs überfällig? Dann ist es nicht buchbar, keine
 * Fahrt damit kann beginnen und kein Antrag dafür genehmigt werden.
 */
function fahrzeug_ueberfaellig(int $fahrzeugId): bool
{
    foreach (ueberfaellige_rueckgaben() as $b) {
        if ($b['fahrzeug_id'] === $fahrzeugId) {
            return true;
        }
    }

    return false;
}

/**
 * Noch nicht behobene Schäden eines Fahrzeugs, neueste zuerst.
 *   SELECT s.* FROM schaeden s JOIN buchungen b ON b.id = s.buchung_id
 *    WHERE b.fahrzeug_id = :id AND s.behoben_am IS NULL
 *    ORDER BY s.gemeldet_am DESC
 */
function offene_schaeden(int $fahrzeugId): array
{
    $schaeden = array_filter(
        beispiel_schaeden(),
        fn (array $s): bool => $s['fahrzeug_id'] === $fahrzeugId && $s['behoben_am'] === null,
    );

    uasort($schaeden, fn (array $a, array $b): int => $b['gemeldet_am'] <=> $a['gemeldet_am']);

    return $schaeden;
}
