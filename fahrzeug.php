<?php
/**
 * Fahrzeug-Steckbrief (Anwendungsfälle 2 und 11, Anforderung F-05), für
 * beide Rollen.
 *
 * Aufruf: fahrzeug.php?id=1
 *
 * Stammdaten, Belegung der nächsten 14 Tage und darunter das Fahrtenbuch des
 * Fahrzeugs mit Summe der km und Anzahl der Fahrten. Den Fahrer sieht nur der
 * Fuhrparkleiter, im Fahrtenbuch wie in der Belegung; für Mitarbeiter wird
 * der Name gar nicht ausgegeben (Datenschutz, entschieden am 06.10.2026).
 *
 * Prototyp ohne Funktion: Fahrzeuge, Buchungen und Fahrten sind feste
 * Beispieldaten. Mit Datenbank werden sie ersetzt durch:
 *
 *   SELECT * FROM fahrzeuge WHERE id = :id
 *   SELECT ... FROM buchungen
 *    WHERE fahrzeug_id = :id AND status IN ('offen', 'genehmigt')
 *      AND start <= :bis AND ende >= :von
 *   SELECT b.*, n.name AS fahrer
 *     FROM buchungen b JOIN nutzer n ON n.id = b.fahrer_id
 *    WHERE b.fahrzeug_id = :id AND b.status = 'abgeschlossen'
 *    ORDER BY b.zurueckgegeben_am DESC
 *
 * Für Mitarbeiter den Namen gar nicht erst abfragen (ohne JOIN).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$fuhrparkleiter = ist_fuhrparkleiter();

// Feste Liste der Zwecke (siehe docs/user-stories.md).
$zweckText = [
    'montage'           => 'Montage',
    'service'           => 'Wartung/Service',
    'kundentermin'      => 'Kundentermin',
    'aufmass'           => 'Aufmaß',
    'materialtransport' => 'Materialtransport',
    'lieferant'         => 'Lieferantenbesuch',
    'schulung'          => 'Schulung',
    'sonstiges'         => 'Sonstiges',
];

// Gespeicherter Fahrzeugstatus => Beschriftung (wie in fahrzeuge.php).
$statusText = [
    'verfuegbar' => 'verfügbar',
    'unterwegs'  => 'unterwegs',
    'wartung'    => 'in Wartung',
];

// Typabhängige Merkmale (F-01): Feld => Beschriftung, in Anzeigereihenfolge.
// Ein Fahrzeug führt nur die Felder, die für seine Art sinnvoll sind, z. B.
// kein TÜV beim Fahrrad und keine Reichweite beim Verbrenner.
$merkmalText = [
    'kennzeichen'   => 'Kennzeichen',
    'art'           => 'Fahrzeugart',
    'typ'           => 'Typ',
    'baujahr'       => 'Baujahr',
    'sitzplaetze'   => 'Sitzplätze',
    'antrieb'       => 'Antrieb',
    'reichweite'    => 'Reichweite',
    'kmstand'       => 'km-Stand',
    'fuehrerschein' => 'Führerschein',
    'tuev'          => 'Nächster TÜV',
];

// Beispiel-Fuhrpark (siehe docs/user-stories.md, „Ausgangslage“).
$fahrzeuge = [
    1 => ['hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'status' => 'verfuegbar', 'bild' => null,
          'kennzeichen' => 'M-HS 101',  'art' => 'Auto',    'typ' => 'Kombi',       'baujahr' => 2021, 'sitzplaetze' => 5, 'antrieb' => 'Diesel',  'kmstand' => 48250,  'fuehrerschein' => 'B',  'tuev' => '03/2027'],
    2 => ['hersteller' => 'Škoda',          'modell' => 'Octavia Combi',  'status' => 'verfuegbar', 'bild' => null,
          'kennzeichen' => 'M-HS 102',  'art' => 'Auto',    'typ' => 'Kombi',       'baujahr' => 2023, 'sitzplaetze' => 5, 'antrieb' => 'Benzin',  'kmstand' => 9870,   'fuehrerschein' => 'B',  'tuev' => '05/2026'],
    3 => ['hersteller' => 'Ford',           'modell' => 'Transit',        'status' => 'unterwegs',  'bild' => null,
          'kennzeichen' => 'M-HS 201',  'art' => 'Auto',    'typ' => 'Transporter', 'baujahr' => 2019, 'sitzplaetze' => 3, 'antrieb' => 'Diesel',  'kmstand' => 112400, 'fuehrerschein' => 'B',  'tuev' => '11/2026'],
    4 => ['hersteller' => 'Mercedes-Benz',  'modell' => 'Sprinter',       'status' => 'wartung',    'bild' => null,
          'kennzeichen' => 'M-HS 202',  'art' => 'Auto',    'typ' => 'Transporter', 'baujahr' => 2020, 'sitzplaetze' => 3, 'antrieb' => 'Diesel',  'kmstand' => 87310,  'fuehrerschein' => 'C1', 'tuev' => '10/2026'],
    5 => ['hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'status' => 'verfuegbar', 'bild' => null,
          'kennzeichen' => 'M-HS 301E', 'art' => 'Auto',    'typ' => 'E-Auto',      'baujahr' => 2022, 'sitzplaetze' => 5, 'antrieb' => 'Elektro', 'reichweite' => 420, 'kmstand' => 31540, 'fuehrerschein' => 'B', 'tuev' => '08/2027'],
    6 => ['hersteller' => 'Tesla',          'modell' => 'Model 3',        'status' => 'verfuegbar', 'bild' => null,
          'kennzeichen' => 'M-HS 302E', 'art' => 'Auto',    'typ' => 'E-Auto',      'baujahr' => 2024, 'sitzplaetze' => 5, 'antrieb' => 'Elektro', 'reichweite' => 510, 'kmstand' => 12020, 'fuehrerschein' => 'B', 'tuev' => '02/2027'],
    7 => ['hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'status' => 'verfuegbar', 'bild' => null,
          'kennzeichen' => 'M-HS 401',  'art' => 'Roller',  'typ' => 'Roller',      'baujahr' => 2022, 'sitzplaetze' => 2, 'antrieb' => 'Benzin',  'kmstand' => 6400,   'fuehrerschein' => 'A1', 'tuev' => '06/2027'],
    8 => ['hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'status' => 'verfuegbar', 'bild' => null,
          'kennzeichen' => 'Rad 1',     'art' => 'Fahrrad', 'typ' => 'E-Fahrrad',   'baujahr' => 2023, 'sitzplaetze' => 1, 'antrieb' => 'Elektro', 'reichweite' => 120],
    9 => ['hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'status' => 'verfuegbar', 'bild' => null,
          'kennzeichen' => 'Rad 2',     'art' => 'Fahrrad', 'typ' => 'E-Fahrrad',   'baujahr' => 2023, 'sitzplaetze' => 1, 'antrieb' => 'Elektro', 'reichweite' => 120],
];

// Belegende Buchungen (offen oder genehmigt). Tage relativ zu heute, damit
// der Prototyp immer etwas anzeigt.
$buchungen = [
    1 => [
        ['von' => 1, 'bis' => 1, 'fahrer' => 'Ella Luppold',   'zweck' => 'Kundentermin'],
        ['von' => 4, 'bis' => 6, 'fahrer' => 'Finn Clausen',   'zweck' => 'Wartung Wechselrichter'],
    ],
    3 => [
        ['von' => 0, 'bis' => 2, 'fahrer' => 'Kenneth Sander', 'zweck' => 'Montage PV-Anlage'],
        ['von' => 7, 'bis' => 8, 'fahrer' => 'Larissa Wagner', 'zweck' => 'Materialtransport'],
    ],
    5 => [
        ['von' => 2, 'bis' => 2, 'fahrer' => 'Lucie Schneider', 'zweck' => 'Kundentermin'],
    ],
];

// Abgeschlossene Fahrten aller Fahrzeuge, neueste zuerst (Fahrtenbuch, T-01
// und T-02), dieselben wie in verlauf.php. Der km-Stand Ende der letzten
// Fahrt ist der heutige km-Stand des Fahrzeugs. Ohne km-Stand (Fahrrad, siehe
// rueckgabe.php) sind beide null.
$fahrten = [
    ['start' => '2026-10-01', 'rueckgabe' => '2026-10-01', 'fahrzeug_id' => 5, 'fahrer' => 'Lucie Schneider', 'zweck' => 'kundentermin',      'km_start' => 31494,  'km_ende' => 31540,  'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-30', 'rueckgabe' => '2026-09-30', 'fahrzeug_id' => 8, 'fahrer' => 'Lucie Schneider', 'zweck' => 'aufmass',           'km_start' => null,   'km_ende' => null,   'schaden' => false, 'bemerkung' => 'Akku nach Rückkehr wieder angeschlossen.'],
    ['start' => '2026-09-29', 'rueckgabe' => '2026-09-29', 'fahrzeug_id' => 1, 'fahrer' => 'Kenneth Sander',  'zweck' => 'kundentermin',      'km_start' => 48166,  'km_ende' => 48250,  'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-25', 'rueckgabe' => '2026-09-27', 'fahrzeug_id' => 4, 'fahrer' => 'Larissa Wagner',  'zweck' => 'montage',           'km_start' => 87167,  'km_ende' => 87310,  'schaden' => true,  'bemerkung' => 'Delle an der Schiebetür rechts, Tür schließt schwer.'],
    ['start' => '2026-09-26', 'rueckgabe' => '2026-09-26', 'fahrzeug_id' => 3, 'fahrer' => 'Ella Luppold',    'zweck' => 'montage',           'km_start' => 112304, 'km_ende' => 112400, 'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-24', 'rueckgabe' => '2026-09-24', 'fahrzeug_id' => 1, 'fahrer' => 'Larissa Wagner',  'zweck' => 'aufmass',           'km_start' => 48034,  'km_ende' => 48166,  'schaden' => false, 'bemerkung' => 'Klappergeräusch hinten rechts bei Tempo über 100.'],
    ['start' => '2026-09-22', 'rueckgabe' => '2026-09-22', 'fahrzeug_id' => 3, 'fahrer' => 'Kenneth Sander',  'zweck' => 'materialtransport', 'km_start' => 112246, 'km_ende' => 112304, 'schaden' => false, 'bemerkung' => 'Ladefläche verschmutzt, Spanngurt fehlt.'],
    ['start' => '2026-09-18', 'rueckgabe' => '2026-09-18', 'fahrzeug_id' => 1, 'fahrer' => 'Finn Clausen',    'zweck' => 'lieferant',         'km_start' => 47824,  'km_ende' => 48034,  'schaden' => false, 'bemerkung' => 'Innenraum könnte mal gereinigt werden.'],
    ['start' => '2026-09-15', 'rueckgabe' => '2026-09-15', 'fahrzeug_id' => 6, 'fahrer' => 'Ella Luppold',    'zweck' => 'schulung',          'km_start' => 11832,  'km_ende' => 12020,  'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-12', 'rueckgabe' => '2026-09-12', 'fahrzeug_id' => 9, 'fahrer' => 'Larissa Wagner',  'zweck' => 'aufmass',           'km_start' => null,   'km_ende' => null,   'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-10', 'rueckgabe' => '2026-09-10', 'fahrzeug_id' => 7, 'fahrer' => 'Finn Clausen',    'zweck' => 'kundentermin',      'km_start' => 6378,   'km_ende' => 6400,   'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-03', 'rueckgabe' => '2026-09-04', 'fahrzeug_id' => 2, 'fahrer' => 'Kenneth Sander',  'zweck' => 'service',           'km_start' => 9605,   'km_ende' => 9870,   'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-08-27', 'rueckgabe' => '2026-08-27', 'fahrzeug_id' => 1, 'fahrer' => 'Ella Luppold',    'zweck' => 'kundentermin',      'km_start' => 47706,  'km_ende' => 47824,  'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-08-20', 'rueckgabe' => '2026-08-21', 'fahrzeug_id' => 3, 'fahrer' => 'Finn Clausen',    'zweck' => 'montage',           'km_start' => 112072, 'km_ende' => 112246, 'schaden' => false, 'bemerkung' => 'Rückfahrkamera zeigt zeitweise kein Bild.'],
    ['start' => '2026-08-14', 'rueckgabe' => '2026-08-14', 'fahrzeug_id' => 2, 'fahrer' => 'Lucie Schneider', 'zweck' => 'lieferant',         'km_start' => 9508,   'km_ende' => 9605,   'schaden' => false, 'bemerkung' => ''],
];

// --- Fahrzeug ermitteln -----------------------------------------------------

$id = (int) ($_GET['id'] ?? 0);
$fahrzeug = $fahrzeuge[$id] ?? null;

if ($fahrzeug === null) {
    http_response_code(404);
}

$pageTitle = $fahrzeug !== null
    ? $fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']
    : 'Fahrzeug nicht gefunden';

// --- Belegung der nächsten 14 Tage ------------------------------------------
// Je Tag: 'frei', 'vergeben' oder 'wartung'. Ein Tag ist vergeben, wenn eine
// Buchung ihn einschließt (von <= Tag <= bis). Fahrer und Zweck stehen nur
// für den Fuhrparkleiter im Hinweis (title-Attribut).

$belegung = [];

if ($fahrzeug !== null) {
    $heute = new DateTimeImmutable('today');

    for ($tag = 0; $tag < 14; $tag++) {
        $eintrag = [
            'datum'   => $heute->modify("+$tag day"),
            'zustand' => $fahrzeug['status'] === 'wartung' ? 'wartung' : 'frei',
            'hinweis' => $fahrzeug['status'] === 'wartung' ? 'in Wartung' : 'frei',
        ];

        if ($eintrag['zustand'] === 'frei') {
            foreach ($buchungen[$id] ?? [] as $buchung) {
                if ($buchung['von'] <= $tag && $tag <= $buchung['bis']) {
                    $eintrag['zustand'] = 'vergeben';
                    $eintrag['hinweis'] = $fuhrparkleiter
                        ? $buchung['fahrer'] . ': ' . $buchung['zweck']
                        : 'vergeben';
                    break;
                }
            }
        }

        $belegung[] = $eintrag;
    }
}

$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

$zustandText = [
    'frei'     => 'frei',
    'vergeben' => 'vergeben',
    'wartung'  => 'in Wartung',
];

// --- Fahrtenbuch des Fahrzeugs ----------------------------------------------
// Ersetzt die Summen „Je Fahrzeug“ der früheren Auswertung. Für Mitarbeiter
// fällt der Fahrer schon hier weg, damit ihn keine Stelle der Seite ausgeben
// kann.

$fahrtenbuch = [];

foreach ($fahrten as $fahrt) {
    if ($fahrt['fahrzeug_id'] !== $id) {
        continue;
    }

    $fahrt['start']     = new DateTimeImmutable($fahrt['start']);
    $fahrt['rueckgabe'] = new DateTimeImmutable($fahrt['rueckgabe']);
    $fahrt['km']        = $fahrt['km_ende'] !== null ? $fahrt['km_ende'] - $fahrt['km_start'] : null;

    if (!$fuhrparkleiter) {
        unset($fahrt['fahrer']);
    }

    $fahrtenbuch[] = $fahrt;
}

// Summe der km; null, wenn das Fahrzeug keinen km-Stand führt (Fahrrad).
$kmWerte = array_filter(array_column($fahrtenbuch, 'km'), fn (?int $km): bool => $km !== null);
$summeKm = $fahrtenbuch !== [] && $kmWerte === [] ? null : array_sum($kmWerte);

// Spalten des Fahrtenbuchs; „Fahrer“ nur für den Fuhrparkleiter.
$anzahlSpalten = $fuhrparkleiter ? 7 : 6;

// Bild aus assets/img/, sonst Platzhalter (siehe docs/technisches-konzept.md).
$bildDatei = $fahrzeug['bild'] ?? null;
$hatBild = $bildDatei !== null && is_file(__DIR__ . '/assets/img/' . $bildDatei);

// Zurück zur Fahrzeugliste der Rolle: fahrzeuge.php ist für Mitarbeiter
// gesperrt, sie wählen Fahrzeuge in buchen.php.
$zurueckZurListe = $fuhrparkleiter
    ? ['datei' => 'fahrzeuge.php', 'text' => 'Alle Fahrzeuge']
    : ['datei' => 'buchen.php',    'text' => 'Zur Fahrzeugauswahl'];

/**
 * Zeitraum einer Fahrt als Text, eintägig ohne „bis“ (wie in verlauf.php).
 */
function zeitraum(array $fahrt): string
{
    $von = $fahrt['start']->format('d.m.Y');
    $bis = $fahrt['rueckgabe']->format('d.m.Y');

    return $von === $bis ? $von : $von . ' bis ' . $bis;
}

/**
 * Kilometer mit Tausenderpunkt, Strich ohne km-Stand.
 */
function km_text(?int $km): string
{
    return $km === null ? '–' : number_format($km, 0, ',', '.');
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fahrzeug === null): ?>

    <p class="alert">Zu dieser Angabe gibt es kein Fahrzeug.</p>
    <p><a href="<?= url($zurueckZurListe['datei']) ?>"><?= e($zurueckZurListe['text']) ?></a></p>

<?php else: ?>

    <p class="note">
        Prototyp &ndash; Beispieldaten, noch ohne Funktion.
    </p>

    <p><a href="<?= url($zurueckZurListe['datei']) ?>">&larr; <?= e($zurueckZurListe['text']) ?></a></p>

    <section class="steckbrief">
        <?php if ($hatBild): ?>
            <img class="steckbrief__bild" src="<?= url('assets/img/' . $bildDatei) ?>"
                 alt="<?= e($pageTitle) ?>">
        <?php else: ?>
            <div class="steckbrief__bild steckbrief__bild--platzhalter"><?= e($fahrzeug['typ']) ?></div>
        <?php endif; ?>

        <div class="steckbrief__daten">
            <p>
                <span class="badge badge--<?= e($fahrzeug['status']) ?>">
                    <?= e($statusText[$fahrzeug['status']] ?? $fahrzeug['status']) ?>
                </span>
            </p>

            <dl class="merkmale">
                <?php foreach ($merkmalText as $feld => $beschriftung): ?>
                    <?php if (isset($fahrzeug[$feld])): ?>
                        <dt><?= e($beschriftung) ?></dt>
                        <dd>
                            <?php if ($feld === 'kmstand'): ?>
                                <?= number_format($fahrzeug['kmstand'], 0, ',', '.') ?> km
                            <?php elseif ($feld === 'reichweite'): ?>
                                <?= e((string) $fahrzeug['reichweite']) ?> km
                            <?php elseif ($feld === 'fuehrerschein'): ?>
                                Klasse <?= e($fahrzeug['fuehrerschein']) ?>
                            <?php else: ?>
                                <?= e((string) $fahrzeug[$feld]) ?>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dl>

            <?php if ($fahrzeug['status'] === 'wartung'): ?>
                <p class="note">Das Fahrzeug ist in Wartung und kann derzeit nicht gebucht werden.</p>
            <?php else: ?>
                <?php if (!$fuhrparkleiter): ?>
                    <a class="button" href="<?= url('buchen.php?fahrzeug=' . $id) ?>">Dieses Fahrzeug buchen</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Belegung der nächsten 14 Tage</h2>

        <ol class="belegung">
            <?php foreach ($belegung as $tag): ?>
                <li class="belegung__tag belegung__tag--<?= e($tag['zustand']) ?>"
                    title="<?= e($tag['hinweis']) ?>">
                    <span class="belegung__wochentag"><?= e($wochentage[(int) $tag['datum']->format('w')]) ?></span>
                    <span class="belegung__datum"><?= e($tag['datum']->format('d.m.')) ?></span>
                    <span class="belegung__zustand"><?= e($zustandText[$tag['zustand']]) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="section" id="fahrtenbuch">
        <h2>Fahrtenbuch</h2>

        <?php if ($fahrtenbuch !== []): ?>
            <p class="lead">
                <?= e((string) count($fahrtenbuch)) ?> <?= count($fahrtenbuch) === 1 ? 'Fahrt' : 'Fahrten' ?>,
                <?= e($summeKm === null ? 'ohne km-Stand' : km_text($summeKm) . ' km') ?>. Neueste zuerst.
            </p>
        <?php endif; ?>

        <table class="table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <?php if ($fuhrparkleiter): ?>
                        <th>Fahrer</th>
                    <?php endif; ?>
                    <th>Zweck</th>
                    <th class="table__num">km-Stand Anfang</th>
                    <th class="table__num">km-Stand Ende</th>
                    <th class="table__num">Gefahrene km</th>
                    <th>Zustand</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fahrtenbuch as $fahrt): ?>
                    <tr>
                        <td><?= e(zeitraum($fahrt)) ?></td>
                        <?php if ($fuhrparkleiter): ?>
                            <td><?= e($fahrt['fahrer']) ?></td>
                        <?php endif; ?>
                        <td><?= e($zweckText[$fahrt['zweck']] ?? $fahrt['zweck']) ?></td>
                        <td class="table__num"><?= e(km_text($fahrt['km_start'])) ?></td>
                        <td class="table__num"><?= e(km_text($fahrt['km_ende'])) ?></td>
                        <td class="table__num"><?= e(km_text($fahrt['km'])) ?></td>
                        <td>
                            <?php if ($fahrt['schaden']): ?>
                                <span class="badge badge--abgelehnt">Schaden</span>
                            <?php elseif ($fahrt['bemerkung'] !== ''): ?>
                                <span class="badge">Bemerkung</span>
                            <?php else: ?>
                                &ndash;
                            <?php endif; ?>

                            <?php if ($fahrt['bemerkung'] !== ''): ?>
                                <span class="table__zusatz"><?= e($fahrt['bemerkung']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($fahrtenbuch === []): ?>
                    <tr>
                        <td colspan="<?= e((string) $anzahlSpalten) ?>" class="table__empty">Noch keine Fahrten erfasst.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
