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
 *
 * 'geloescht_am' ist null oder der Zeitpunkt, an dem der Nutzer ausgeschieden
 * ist. Paul (7) ist ausgeschieden: keine Anmeldung, nicht mehr als Fahrer
 * wählbar, im Fahrtenbuch bleibt sein Name. 'darf_luxus': darf Luxusautos
 * fahren (Ella und Kenneth).
 */
function beispiel_nutzer(): array
{
    $hash = '$2y$10$8qCeyZD9ZM7xi8OK3vqPueH5BLrjijS8U2ifSw5pZH3UlmcMKwTK.';
    $ausgeschieden = new DateTimeImmutable('today -30 day 17:00');

    return [
        1 => ['vorname' => 'Lucie',   'nachname' => 'Schneider', 'rolle_id' => ROLLE_MITARBEITER,    'passwort_hash' => $hash, 'darf_luxus' => false, 'geloescht_am' => null,           'fuehrerscheine' => ['B', 'A1']],
        2 => ['vorname' => 'Ella',    'nachname' => 'Luppold',   'rolle_id' => ROLLE_MITARBEITER,    'passwort_hash' => $hash, 'darf_luxus' => true,  'geloescht_am' => null,           'fuehrerscheine' => ['B']],
        3 => ['vorname' => 'Finn',    'nachname' => 'Clausen',   'rolle_id' => ROLLE_MITARBEITER,    'passwort_hash' => $hash, 'darf_luxus' => false, 'geloescht_am' => null,           'fuehrerscheine' => ['B', 'A1']],
        4 => ['vorname' => 'Kenneth', 'nachname' => 'Sander',    'rolle_id' => ROLLE_MITARBEITER,    'passwort_hash' => $hash, 'darf_luxus' => true,  'geloescht_am' => null,           'fuehrerscheine' => ['B', 'C1']],
        5 => ['vorname' => 'Larissa', 'nachname' => 'Wagner',    'rolle_id' => ROLLE_MITARBEITER,    'passwort_hash' => $hash, 'darf_luxus' => false, 'geloescht_am' => null,           'fuehrerscheine' => ['B', 'C1']],
        6 => ['vorname' => 'Jonas',   'nachname' => 'Weber',     'rolle_id' => ROLLE_FUHRPARKLEITER, 'passwort_hash' => $hash, 'darf_luxus' => false, 'geloescht_am' => null,           'fuehrerscheine' => []],
        7 => ['vorname' => 'Paul',    'nachname' => 'Becker',    'rolle_id' => ROLLE_MITARBEITER,    'passwort_hash' => $hash, 'darf_luxus' => false, 'geloescht_am' => $ausgeschieden, 'fuehrerscheine' => ['B']],
    ];
}

/**
 * Fahrzeuge (fahrzeuge). hu_au ist der Monatserste des fälligen Monats.
 *
 * 'geloescht_am' ist null oder der Zeitpunkt der Ausmusterung. Der alte
 * Passat (10) ist ausgemustert; sein Kennzeichen trägt heute der Passat (1).
 * Für Listen der aktuellen Fahrzeuge fahrzeuge_im_bestand() nehmen.
 *
 * 'in_wartung' und 'wartung_bis' stehen nicht in der Tabelle, sondern ergeben
 * sich aus der laufenden Wartung (wie „unterwegs“ aus den Buchungen):
 * 'wartung_bis' ist das voraussichtliche Ende (DateTimeImmutable), null ohne
 * Wartung oder solange das Ende offen ist.
 *   SELECT f.*, w.id IS NOT NULL AS in_wartung, w.voraussichtlich_bis AS wartung_bis
 *     FROM fahrzeuge f
 *     LEFT JOIN wartungen w ON w.fahrzeug_id = f.id AND w.freigegeben_am IS NULL
 */
function beispiel_fahrzeuge(): array
{
    $spalten = ['kennzeichen', 'art_id', 'typ', 'hersteller', 'modell', 'baujahr', 'bild',
                'sitzplaetze', 'antrieb_id', 'kmstand', 'fuehrerschein', 'hu_au', 'geloescht_am'];

    $zeilen = [
        1  => ['M-HS 101',  ART_AUTO,        'Kombi',       'Volkswagen',     'Passat Variant', 2021, 'vw-passat-variant.jpg',     5, ANTRIEB_DIESEL,  48250,  'B',  '2027-03-01', null],
        2  => ['M-HS 102',  ART_AUTO,        'Kombi',       'Škoda',          'Octavia Combi',  2023, 'skoda-octavia-combi.jpg',   5, ANTRIEB_BENZIN,  9870,   'B',  '2026-05-01', null],
        3  => ['M-HS 201',  ART_TRANSPORTER, 'Transporter', 'Ford',           'Transit',        2019, 'ford-transit.jpg',          3, ANTRIEB_DIESEL,  112400, 'B',  '2026-11-01', null],
        4  => ['M-HS 202',  ART_TRANSPORTER, 'Transporter', 'Mercedes-Benz',  'Sprinter',       2020, 'mercedes-sprinter.jpg',     3, ANTRIEB_DIESEL,  87310,  'C1', '2026-10-01', null],
        5  => ['M-HS 301E', ART_AUTO,        'E-Auto',      'Volkswagen',     'ID.3',           2022, 'vw-id3.jpg',                5, ANTRIEB_ELEKTRO, 31540,  'B',  '2027-08-01', null],
        6  => ['M-HS 302E', ART_LUXUS,       'E-Auto',      'Tesla',          'Model 3',        2024, 'tesla-model-3.jpg',         5, ANTRIEB_ELEKTRO, 12020,  'B',  '2027-02-01', null],
        7  => ['M-HS 401',  ART_ROLLER,      'Roller',      'Vespa',          'Primavera 125',  2022, 'vespa-primavera.jpg',       2, ANTRIEB_BENZIN,  6400,   'A1', '2027-06-01', null],
        8  => ['Rad 1',     ART_FAHRRAD,     'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, 'riese-mueller-charger.jpg', 1, ANTRIEB_ELEKTRO, null,   null, null,         null],
        9  => ['Rad 2',     ART_FAHRRAD,     'E-Fahrrad',   'Riese & Müller', 'Charger4',       2023, 'riese-mueller-charger.jpg', 1, ANTRIEB_ELEKTRO, null,   null, null,         null],
        10 => ['M-HS 101',  ART_AUTO,        'Kombi',       'Volkswagen',     'Passat Variant', 2014, null,                        5, ANTRIEB_DIESEL,  187430, 'B',  '2026-03-01', '-90 day 12:00'],
    ];

    $fahrzeuge = [];

    foreach ($zeilen as $id => $zeile) {
        $f = array_combine($spalten, $zeile);
        $f['geloescht_am'] = $f['geloescht_am'] !== null ? new DateTimeImmutable('today ' . $f['geloescht_am']) : null;

        $fahrzeuge[$id] = $f + ['in_wartung' => false, 'wartung_bis' => null];
    }

    foreach (beispiel_wartungen() as $wartung) {
        if ($wartung['freigegeben_am'] === null) {
            $fahrzeuge[$wartung['fahrzeug_id']]['in_wartung']  = true;
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
        // Tesla, abgeschlossen: Kleinschaden 8 an einem Luxusauto sperrt
        4 => [6, 8,    'Felge hinten rechts zerkratzt, Bordstein.',            -25, -23, -23],
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
    $spalten = ['fahrzeug_id', 'antragsteller_id', 'fahrer_id', 'start', 'ende', 'zweck_id',
                'personenanzahl', 'status_id', 'beantragt', 'entscheidung_kommentar',
                'km_start', 'km_ende', 'bemerkung'];

    $zeilen = [
        // Offen, genehmigt, abgelehnt, storniert
        11 => [8, 1, 1,   0,   0, ZWECK_AUFMASS,           1, STATUS_GENEHMIGT,     '-2 09:10'],
        13 => [5, 1, 1,   2,   2, ZWECK_KUNDENTERMIN,      1, STATUS_GENEHMIGT,     '-4 11:20'],
        14 => [2, 4, 1,   5,   6, ZWECK_MONTAGE,           3, STATUS_OFFEN,         '-3 08:45'],
        15 => [1, 1, 1,  10,  11, ZWECK_LIEFERANT,         2, STATUS_OFFEN,         '-1 10:05'],
        16 => [6, 1, 1,   3,   3, ZWECK_KUNDENTERMIN,      1, STATUS_ABGELEHNT,     '-4 09:30', 'Für Einzeltermine bitte den ID.3 oder ein E-Fahrrad nutzen.'],
        17 => [3, 1, 1,  -6,  -6, ZWECK_MATERIALTRANSPORT, 2, STATUS_STORNIERT,     '-12 13:00'],
        19 => [3, 4, 4,   3,   4, ZWECK_MATERIALTRANSPORT, 2, STATUS_OFFEN,         '-5 14:12'],
        20 => [6, 4, 4,   8,   9, ZWECK_AUFMASS,           1, STATUS_OFFEN,         '-2 16:30'],
        21 => [1, 2, 2,   0,   0, ZWECK_KUNDENTERMIN,      2, STATUS_GENEHMIGT,     '-6 10:00'],
        25 => [1, 3, 3,   4,   6, ZWECK_SERVICE,           1, STATUS_GENEHMIGT,     '-8 08:15'],
        26 => [3, 5, 5,   7,   8, ZWECK_MATERIALTRANSPORT, 2, STATUS_GENEHMIGT,     '-5 11:00'],
        27 => [9, 5, 5,   3,   4, ZWECK_AUFMASS,           1, STATUS_GENEHMIGT,     '-3 12:00'],
        31 => [9, 2, 2,   6,   6, ZWECK_KUNDENTERMIN,      1, STATUS_GENEHMIGT,     '-1 09:00'],
        32 => [2, 5, 2,   9,   9, ZWECK_MONTAGE,           3, STATUS_OFFEN,         '-1 15:20'],
        33 => [9, 5, 5,   1,   1, ZWECK_AUFMASS,           1, STATUS_STORNIERT,     '-6 08:00'],
        34 => [5, 4, 4,  -4,  -4, ZWECK_KUNDENTERMIN,      2, STATUS_ABGELEHNT,     '-7 10:30', 'Der ID.3 ist an dem Tag zur Inspektion angemeldet.'],
        // automatisch storniert, als der Sprinter in Wartung ging
        54 => [4, 5, 5,   2,   3, ZWECK_MATERIALTRANSPORT, 2, STATUS_STORNIERT,     '-15 09:30', 'Automatisch storniert: Das Fahrzeug ist in diesem Zeitraum in Wartung.'],

        // Unterwegs; Ella (30) ist überfällig
        12 => [7, 1, 1,  -2,   0, ZWECK_KUNDENTERMIN,      1, STATUS_UNTERWEGS,     '-5 09:00',  null, 6400],
        18 => [3, 4, 4,   0,   2, ZWECK_MONTAGE,           3, STATUS_UNTERWEGS,     '-6 10:20',  null, 112400],
        30 => [6, 2, 2,  -3,  -2, ZWECK_KUNDENTERMIN,      1, STATUS_UNTERWEGS,     '-9 14:00',  null, 12020],

        // Abgeschlossen: das Fahrtenbuch
        48 => [8, 2, 2,  -5,  -5, ZWECK_AUFMASS,           1, STATUS_ABGESCHLOSSEN, '-7 16:00'],
        53 => [9, 4, 4,  -7,  -7, ZWECK_KUNDENTERMIN,      1, STATUS_ABGESCHLOSSEN, '-8 11:00'],
        22 => [5, 1, 1,  -9,  -9, ZWECK_KUNDENTERMIN,      1, STATUS_ABGESCHLOSSEN, '-13 10:00', null, 31494,  31540],
        23 => [8, 1, 1, -10, -10, ZWECK_AUFMASS,           1, STATUS_ABGESCHLOSSEN, '-11 14:00', null, null,   null,   'Akku nach Rückkehr wieder angeschlossen.'],
        35 => [1, 4, 4, -11, -11, ZWECK_KUNDENTERMIN,      2, STATUS_ABGESCHLOSSEN, '-15 10:00', null, 48166,  48250],
        36 => [4, 5, 5, -15, -13, ZWECK_MONTAGE,           3, STATUS_ABGESCHLOSSEN, '-19 10:00', null, 87167,  87310],
        37 => [3, 2, 2, -14, -14, ZWECK_MONTAGE,           2, STATUS_ABGESCHLOSSEN, '-18 10:00', null, 112304, 112400],
        38 => [1, 5, 5, -16, -16, ZWECK_AUFMASS,           1, STATUS_ABGESCHLOSSEN, '-20 10:00', null, 48034,  48166,  'Klappergeräusch hinten rechts bei Tempo über 100.'],
        39 => [3, 4, 4, -18, -18, ZWECK_MATERIALTRANSPORT, 2, STATUS_ABGESCHLOSSEN, '-22 10:00', null, 112246, 112304, 'Ladefläche verschmutzt, Spanngurt fehlt.'],
        47 => [5, 4, 4, -20, -20, ZWECK_KUNDENTERMIN,      2, STATUS_ABGESCHLOSSEN, '-24 10:00', null, 31402,  31494],
        40 => [1, 3, 3, -22, -22, ZWECK_LIEFERANT,         1, STATUS_ABGESCHLOSSEN, '-26 10:00', null, 47824,  48034,  'Innenraum könnte mal gereinigt werden.'],
        41 => [6, 2, 2, -25, -25, ZWECK_SCHULUNG,          2, STATUS_ABGESCHLOSSEN, '-29 10:00', null, 11832,  12020],
        42 => [9, 5, 5, -28, -28, ZWECK_AUFMASS,           1, STATUS_ABGESCHLOSSEN, '-30 12:00'],
        49 => [4, 4, 4, -29, -29, ZWECK_MATERIALTRANSPORT, 2, STATUS_ABGESCHLOSSEN, '-33 10:00', null, 86980,  87167],
        43 => [7, 3, 3, -30, -30, ZWECK_KUNDENTERMIN,      1, STATUS_ABGESCHLOSSEN, '-31 15:00', null, 6378,   6400],
        44 => [2, 4, 4, -37, -36, ZWECK_SERVICE,           1, STATUS_ABGESCHLOSSEN, '-41 10:00', null, 9605,   9870],
        50 => [7, 1, 1, -40, -40, ZWECK_KUNDENTERMIN,      1, STATUS_ABGESCHLOSSEN, '-42 09:00', null, 6341,   6378,   'Helmfach klemmt beim Öffnen.'],
        45 => [1, 2, 2, -44, -44, ZWECK_KUNDENTERMIN,      2, STATUS_ABGESCHLOSSEN, '-48 10:00', null, 47706,  47824],
        46 => [3, 3, 3, -51, -50, ZWECK_MONTAGE,           3, STATUS_ABGESCHLOSSEN, '-55 10:00', null, 112072, 112246, 'Rückfahrkamera zeigt zeitweise kein Bild.'],
        24 => [2, 1, 1, -57, -57, ZWECK_LIEFERANT,         1, STATUS_ABGESCHLOSSEN, '-61 10:00', null, 9508,   9605],
        51 => [1, 5, 5, -64, -64, ZWECK_MONTAGE,           3, STATUS_ABGESCHLOSSEN, '-68 10:00', null, 47588,  47706],
        // Paul, inzwischen ausgeschieden
        52 => [2, 7, 7, -71, -70, ZWECK_SCHULUNG,          2, STATUS_ABGESCHLOSSEN, '-75 10:00', null, 9420,   9508],
        // alter Passat (10), vor dem Ausmustern
        55 => [10, 1, 1, -95, -95, ZWECK_KUNDENTERMIN,     1, STATUS_ABGESCHLOSSEN, '-99 10:00', null, 187312, 187430],
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
        $b['begonnen_am'] = in_array($b['status_id'], [STATUS_UNTERWEGS, STATUS_ABGESCHLOSSEN], true) ? $b['start'] : null;
        $b['zurueckgegeben_am'] = $b['status_id'] === STATUS_ABGESCHLOSSEN ? $b['ende'] : null;

        $buchungen[$id] = $b;
    }

    return $buchungen;
}

/**
 * Schäden (schaeden), nach ID. 'gemeldet' und 'behoben' sind Tage relativ zu
 * heute; 'fotos' sind Dateinamen in uploads/schaeden/ (im Prototyp ohne
 * Dateien, die Seite zeigt Platzhalter). 'fahrzeug_id' und 'fahrer_id'
 * stehen nicht in der Tabelle, sie kommen aus der Buchung (JOIN).
 */
function beispiel_schaeden(): array
{
    $spalten = ['buchung_id', 'anlass_id', 'schwere_id', 'beschreibung', 'gemeldet', 'behoben', 'fotos'];

    $zeilen = [
        1 => [36, ANLASS_RUECKGABE,  SCHWERE_WARTUNG, 'Delle an der Schiebetür rechts, Tür schließt schwer.',      -13, null,
              ['3f9c1a7e5b2d4086a1c7e9f03b6d2a58.jpg', 'b81e04d9c67a2f3e5d1b8a09c4f7e263.jpg']],
        2 => [47, ANLASS_UEBERNAHME, SCHWERE_KLEIN,   'Kratzer an der Stoßstange hinten links, schon vorhanden.', -20, null, []],
        3 => [18, ANLASS_UEBERNAHME, SCHWERE_KLEIN,   'Kratzer an der Heckklappe, war schon vorhanden.',          0,   null, []],
        4 => [53, ANLASS_RUECKGABE,  SCHWERE_KLEIN,   'Klingel abgebrochen.',                                     -7,  null, []],
        5 => [44, ANLASS_RUECKGABE,  SCHWERE_KLEIN,   'Steinschlag in der Windschutzscheibe, unten rechts.',      -36, -30,  []],
        6 => [46, ANLASS_RUECKGABE,  SCHWERE_WARTUNG, 'Außenspiegel links abgebrochen.',                          -50, -47,  []],
        // Lucie sieht ihn heute beim Fahrtbeginn mit Rad 1 (Buchung 11).
        7 => [48, ANLASS_RUECKGABE,  SCHWERE_KLEIN,   'Schutzblech hinten leicht verbogen, schleift nicht.',      -5,  null, []],
        // Kleinschaden am Tesla: sperrt bei einem Luxusauto (Wartung 4), inzwischen behoben.
        8 => [41, ANLASS_RUECKGABE,  SCHWERE_KLEIN,   'Felge hinten rechts zerkratzt, Bordstein.',                -25, -23,  []],
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
 * Alle aktiven Nutzer für eine Auswahlliste (Anmeldung): ID => „Vorname
 * Nachname“, nach Nachname sortiert. Ausgeschiedene fehlen.
 *   SELECT id, vorname, nachname FROM nutzer
 *    WHERE geloescht_am IS NULL ORDER BY nachname, vorname
 */
function nutzer_auswahl(): array
{
    $nutzer = array_filter(beispiel_nutzer(), fn (array $n): bool => $n['geloescht_am'] === null);
    uasort($nutzer, fn (array $a, array $b): int => [$a['nachname'], $a['vorname']] <=> [$b['nachname'], $b['vorname']]);

    return array_map(fn (array $n): string => $n['vorname'] . ' ' . $n['nachname'], $nutzer);
}

/**
 * Fahrzeuge im Bestand, ohne ausgemusterte: für Buchen, Kalender und die
 * Fahrzeugliste. Steckbrief und Fahrtenbuch finden auch ausgemusterte.
 *   … WHERE f.geloescht_am IS NULL
 */
function fahrzeuge_im_bestand(): array
{
    return array_filter(beispiel_fahrzeuge(), fn (array $f): bool => $f['geloescht_am'] === null);
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
 *   SELECT * FROM buchungen WHERE status_id = STATUS_UNTERWEGS AND ende < CURDATE()
 *
 * In den SQL-Beispielen dieser Datei und der Seiten steht die Konstante; mit
 * PDO wird sie als Parameter gebunden.
 */
function ueberfaellige_rueckgaben(): array
{
    $heute = new DateTimeImmutable('today');

    return array_filter(
        beispiel_buchungen(),
        fn (array $b): bool => $b['status_id'] === STATUS_UNTERWEGS && $b['ende'] < $heute,
    );
}

/**
 * Hat der Nutzer als Fahrer eine überfällige Rückgabe? Dann darf er nichts
 * neu buchen und keine Fahrt beginnen (Regel 12 in docs/aenderungen/aenderungen-todo2.md).
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
