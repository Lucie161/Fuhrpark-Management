<?php
/**
 * Fahrzeug-Steckbrief (Anwendungsfall 2, Anforderung F-05).
 *
 * Aufruf: fahrzeug.php?id=1
 *
 * Prototyp ohne Funktion: Fahrzeuge, Buchungen und Fahrten sind feste
 * Beispieldaten. Mit Datenbank werden sie ersetzt durch:
 *
 *   SELECT * FROM fahrzeuge WHERE id = :id
 *   SELECT ... FROM buchungen
 *    WHERE fahrzeug_id = :id AND status IN ('offen', 'genehmigt')
 *      AND start <= :bis AND ende >= :von
 *   SELECT ... FROM buchungen
 *    WHERE fahrzeug_id = :id AND status = 'abgeschlossen' ORDER BY ende DESC
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

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

// Abgeschlossene Fahrten, neueste zuerst (Fahrtenbuch, T-01 und T-02).
$fahrten = [
    1 => [
        ['datum' => '29.09.2026', 'fahrer' => 'Kenneth Sander',  'zweck' => 'Kundentermin',           'km' => 84,  'bemerkung' => ''],
        ['datum' => '24.09.2026', 'fahrer' => 'Larissa Wagner',  'zweck' => 'Aufmaß Dachfläche',      'km' => 132, 'bemerkung' => 'Klappergeräusch hinten rechts bei Tempo über 100.'],
        ['datum' => '18.09.2026', 'fahrer' => 'Finn Clausen',    'zweck' => 'Lieferantenbesuch',      'km' => 210, 'bemerkung' => 'Innenraum könnte mal gereinigt werden.'],
    ],
    3 => [
        ['datum' => '26.09.2026', 'fahrer' => 'Ella Luppold',    'zweck' => 'Montage PV-Anlage',      'km' => 96,  'bemerkung' => ''],
        ['datum' => '22.09.2026', 'fahrer' => 'Kenneth Sander',  'zweck' => 'Materialtransport',      'km' => 58,  'bemerkung' => 'Ladefläche verschmutzt, Spanngurt fehlt.'],
    ],
    8 => [
        ['datum' => '30.09.2026', 'fahrer' => 'Lucie Schneider', 'zweck' => 'Aufmaß Dachfläche',      'km' => 14,  'bemerkung' => 'Akku nach Rückkehr wieder angeschlossen.'],
    ],
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
// Buchung ihn einschließt (von <= Tag <= bis).

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
                    $eintrag['hinweis'] = $buchung['fahrer'] . ': ' . $buchung['zweck'];
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

// --- Kennzahlen zu den bisherigen Fahrten -----------------------------------

$bisherigeFahrten = $fahrten[$id] ?? [];
$summeKm = array_sum(array_column($bisherigeFahrten, 'km'));

// Bild aus assets/img/, sonst Platzhalter (siehe docs/technisches-konzept.md).
$bildDatei = $fahrzeug['bild'] ?? null;
$hatBild = $bildDatei !== null && is_file(__DIR__ . '/assets/img/' . $bildDatei);

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fahrzeug === null): ?>

    <p class="alert">Zu dieser Angabe gibt es kein Fahrzeug.</p>
    <p><a href="<?= url('fahrzeuge.php') ?>">Zur Fahrzeugübersicht</a></p>

<?php else: ?>

    <p class="note">
        Prototyp &ndash; Beispieldaten, noch ohne Funktion.
    </p>

    <p><a href="<?= url('fahrzeuge.php') ?>">&larr; Alle Fahrzeuge</a></p>

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
                <a class="button" href="<?= url('buchen.php?fahrzeug=' . $id) ?>">Dieses Fahrzeug buchen</a>
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

    <section class="section">
        <h2>Bisherige Fahrten</h2>

        <?php if ($bisherigeFahrten !== []): ?>
            <p class="lead">
                <?= count($bisherigeFahrten) ?> <?= count($bisherigeFahrten) === 1 ? 'Fahrt' : 'Fahrten' ?>,
                <?= number_format($summeKm, 0, ',', '.') ?> km.
            </p>
        <?php endif; ?>

        <table class="table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Fahrer</th>
                    <th>Zweck</th>
                    <th class="table__num">km</th>
                    <th>Bemerkung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bisherigeFahrten as $fahrt): ?>
                    <tr>
                        <td><?= e($fahrt['datum']) ?></td>
                        <td><?= e($fahrt['fahrer']) ?></td>
                        <td><?= e($fahrt['zweck']) ?></td>
                        <td class="table__num"><?= number_format($fahrt['km'], 0, ',', '.') ?></td>
                        <td><?= $fahrt['bemerkung'] !== '' ? e($fahrt['bemerkung']) : '&ndash;' ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($bisherigeFahrten === []): ?>
                    <tr>
                        <td colspan="5" class="table__empty">Noch keine Fahrten erfasst.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
