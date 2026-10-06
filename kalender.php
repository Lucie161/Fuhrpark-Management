<?php
/**
 * Buchungskalender (Anwendungsfall 7, Anforderung B-01), für beide Rollen
 * mit unterschiedlichem Inhalt.
 *
 * Belegung für die nächsten 14 Tage: Zeilen sind Fahrzeuge, Spalten Tage.
 * Reine Anzeige ohne JavaScript und ohne Verschieben (siehe
 * docs/technisches-konzept.md, Regel 7). Gerechnet wird in ganzen Tagen.
 *
 * Fuhrparkleiter: alle Fahrzeuge und alle Buchungen mit Fahrer und Zweck.
 * Beantragt und vergeben sind getrennte Zustände; ein Antrag führt zu den
 * offenen Anträgen.
 *
 * Mitarbeiter: nur die eigenen Buchungen (beantragt oder als Fahrer
 * eingetragen) und nur die Fahrzeuge, zu denen es eine gibt. Fremde
 * Buchungen fallen gleich nach dem Laden weg, damit keine Stelle der Seite
 * sie ausgeben kann (Datenschutz). Tage ohne eigene Buchung bleiben neutral,
 * nicht „frei“, denn dort kann jemand anderes gebucht haben. Ob ein Fahrzeug
 * frei ist, zeigt buchen.php.
 *
 * Prototyp ohne Funktion: Fahrzeuge und Buchungen sind feste Beispieldaten.
 * Mit Datenbank werden sie ersetzt durch:
 *
 *   SELECT id, kennzeichen, hersteller, modell, art, status
 *     FROM fahrzeuge ORDER BY art, kennzeichen
 *   SELECT b.fahrzeug_id, b.start, b.ende, b.zweck, b.status,
 *          f.name AS fahrer, a.name AS antragsteller
 *     FROM buchungen b
 *     JOIN nutzer f ON f.id = b.fahrer_id
 *     JOIN nutzer a ON a.id = b.antragsteller_id
 *    WHERE b.status IN ('offen', 'genehmigt', 'unterwegs')
 *      AND b.start <= :bis
 *      AND (b.ende >= :heute OR b.status = 'unterwegs')
 *      [AND (b.antragsteller_id = :ich OR b.fahrer_id = :ich)]  -- Mitarbeiter
 *
 * Die vorletzte Bedingung holt auch überfällige Fahrten: begonnen, Ende
 * vorbei, noch nicht zurückgegeben.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Buchungskalender';

$fuhrparkleiter = ist_fuhrparkleiter();

// Angemeldeter Nutzer. Kommt später aus der Session.
$ich = 'Lucie Schneider';

$anzahlTage = 14;

// Fahrzeugart => Überschrift der Gruppe, in Anzeigereihenfolge.
$artText = [
    'auto'    => 'Autos',
    'roller'  => 'Roller',
    'fahrrad' => 'Fahrräder',
];

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

// Stand einer belegenden Buchung => [Zustand der Zelle, Beschriftung]. Der
// Zustand dient zugleich als CSS-Modifier (.kalender__zelle--beantragt).
$standText = [
    'offen'     => ['beantragt', 'beantragt'],
    'genehmigt' => ['vergeben',  'vergeben'],
    'unterwegs' => ['vergeben',  'unterwegs'],
];

// Beispiel-Fuhrpark (wie in fahrzeug.php).
$fahrzeuge = [
    1 => ['kennzeichen' => 'M-HS 101',  'hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'art' => 'auto',    'status' => 'verfuegbar'],
    2 => ['kennzeichen' => 'M-HS 102',  'hersteller' => 'Škoda',          'modell' => 'Octavia Combi',  'art' => 'auto',    'status' => 'verfuegbar'],
    3 => ['kennzeichen' => 'M-HS 201',  'hersteller' => 'Ford',           'modell' => 'Transit',        'art' => 'auto',    'status' => 'verfuegbar'],
    4 => ['kennzeichen' => 'M-HS 202',  'hersteller' => 'Mercedes-Benz',  'modell' => 'Sprinter',       'art' => 'auto',    'status' => 'wartung'],
    5 => ['kennzeichen' => 'M-HS 301E', 'hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'art' => 'auto',    'status' => 'verfuegbar'],
    6 => ['kennzeichen' => 'M-HS 302E', 'hersteller' => 'Tesla',          'modell' => 'Model 3',        'art' => 'auto',    'status' => 'verfuegbar'],
    7 => ['kennzeichen' => 'M-HS 401',  'hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'art' => 'roller',  'status' => 'verfuegbar'],
    8 => ['kennzeichen' => 'Rad 1',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad', 'status' => 'verfuegbar'],
    9 => ['kennzeichen' => 'Rad 2',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad', 'status' => 'verfuegbar'],
];

// Belegende Buchungen (offen, genehmigt, unterwegs). Tage relativ zu heute,
// damit der Prototyp immer alle Zustände zeigt. Abgestimmt mit fahrzeug.php
// und meine-buchungen.php.
$buchungen = [
    ['fahrzeug_id' => 1, 'von' => 1,  'bis' => 1,  'fahrer' => 'Ella Luppold',    'antragsteller' => 'Ella Luppold',    'zweck' => 'kundentermin',      'status' => 'genehmigt'],
    ['fahrzeug_id' => 1, 'von' => 4,  'bis' => 6,  'fahrer' => 'Finn Clausen',    'antragsteller' => 'Finn Clausen',    'zweck' => 'service',           'status' => 'genehmigt'],
    ['fahrzeug_id' => 1, 'von' => 10, 'bis' => 11, 'fahrer' => 'Lucie Schneider', 'antragsteller' => 'Lucie Schneider', 'zweck' => 'lieferant',         'status' => 'offen'],
    ['fahrzeug_id' => 2, 'von' => 5,  'bis' => 6,  'fahrer' => 'Lucie Schneider', 'antragsteller' => 'Kenneth Sander',  'zweck' => 'montage',           'status' => 'offen'],
    ['fahrzeug_id' => 3, 'von' => 0,  'bis' => 2,  'fahrer' => 'Kenneth Sander',  'antragsteller' => 'Kenneth Sander',  'zweck' => 'montage',           'status' => 'unterwegs'],
    ['fahrzeug_id' => 3, 'von' => 7,  'bis' => 8,  'fahrer' => 'Larissa Wagner',  'antragsteller' => 'Larissa Wagner',  'zweck' => 'materialtransport', 'status' => 'genehmigt'],
    ['fahrzeug_id' => 4, 'von' => 3,  'bis' => 4,  'fahrer' => 'Kenneth Sander',  'antragsteller' => 'Kenneth Sander',  'zweck' => 'materialtransport', 'status' => 'offen'],
    ['fahrzeug_id' => 5, 'von' => 2,  'bis' => 2,  'fahrer' => 'Lucie Schneider', 'antragsteller' => 'Lucie Schneider', 'zweck' => 'kundentermin',      'status' => 'genehmigt'],
    ['fahrzeug_id' => 6, 'von' => 8,  'bis' => 9,  'fahrer' => 'Kenneth Sander',  'antragsteller' => 'Kenneth Sander',  'zweck' => 'aufmass',           'status' => 'offen'],
    ['fahrzeug_id' => 7, 'von' => -2, 'bis' => -1, 'fahrer' => 'Lucie Schneider', 'antragsteller' => 'Lucie Schneider', 'zweck' => 'kundentermin',      'status' => 'unterwegs'],
    ['fahrzeug_id' => 8, 'von' => 0,  'bis' => 0,  'fahrer' => 'Lucie Schneider', 'antragsteller' => 'Lucie Schneider', 'zweck' => 'aufmass',           'status' => 'genehmigt'],
    ['fahrzeug_id' => 9, 'von' => 3,  'bis' => 4,  'fahrer' => 'Finn Clausen',    'antragsteller' => 'Finn Clausen',    'zweck' => 'aufmass',           'status' => 'genehmigt'],
];

// --- Nur eigene Buchungen (Mitarbeiter) -------------------------------------
// Fremde Buchungen fallen hier weg, dazu die Fahrzeuge, zu denen es im
// Zeitraum keine eigene Buchung gibt. Überfällige Fahrten zählen mit, sie
// erscheinen am heutigen Tag.

if (!$fuhrparkleiter) {
    $buchungen = array_values(array_filter(
        $buchungen,
        fn (array $b): bool => ($b['fahrer'] === $ich || $b['antragsteller'] === $ich)
            && $b['von'] < $anzahlTage
            && ($b['bis'] >= 0 || $b['status'] === 'unterwegs'),
    ));

    $fahrzeuge = array_intersect_key($fahrzeuge, array_flip(array_column($buchungen, 'fahrzeug_id')));
}

// --- Tage -------------------------------------------------------------------

$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
$heute = new DateTimeImmutable('today');

$tage = [];
for ($i = 0; $i < $anzahlTage; $i++) {
    $datum = $heute->modify("+$i day");
    $tage[] = [
        'datum'      => $datum,
        'wochentag'  => $wochentage[(int) $datum->format('w')],
        'wochenende' => (int) $datum->format('N') >= 6,
    ];
}

$letzterTag = $tage[$anzahlTage - 1]['datum'];

// --- Zellen -----------------------------------------------------------------
// Je Fahrzeug und Tag eine Zelle mit
//   'zustaende' – CSS-Modifier, der erste ist frei, beantragt, vergeben,
//                 wartung oder (Mitarbeiter) leer
//   'text'      – sichtbarer Kurztext, oft leer
//   'hinweis'   – vollständige Angabe für title und Screenreader
//   'link'      – Ziel beim Anklicken oder null
// Zuerst ist jeder Tag frei bzw. in Wartung, für Mitarbeiter neutral; danach
// werden die Buchungen eingetragen.

$zellen = [];

foreach ($fahrzeuge as $id => $fahrzeug) {
    foreach ($tage as $i => $tag) {
        $zellen[$id][$i] = match (true) {
            !$fuhrparkleiter                  => ['zustaende' => ['leer'],    'text' => '',                         'hinweis' => 'keine eigene Buchung', 'link' => null],
            $fahrzeug['status'] === 'wartung' => ['zustaende' => ['wartung'], 'text' => $i === 0 ? 'Wartung' : '', 'hinweis' => 'in Wartung',           'link' => null],
            default                           => ['zustaende' => ['frei'],    'text' => '',                         'hinweis' => 'frei',                 'link' => null],
        };
    }
}

foreach ($buchungen as $buchung) {
    $id = $buchung['fahrzeug_id'];

    // Wartung geht vor: Das Fahrzeug ist gesperrt, egal was gebucht ist. Der
    // Mitarbeiter sieht seine Buchung trotzdem, mit Hinweis.
    if (!isset($fahrzeuge[$id]) || ($fuhrparkleiter && $fahrzeuge[$id]['status'] === 'wartung')) {
        continue;
    }

    // Eine begonnene Fahrt, deren Ende vorbei ist, blockiert das Fahrzeug bis
    // zur Rückgabe. Wann die kommt, ist unbekannt, daher nur heute.
    $ueberfaellig = $buchung['status'] === 'unterwegs' && $buchung['bis'] < 0;

    $von = max($buchung['von'], 0);
    $bis = $ueberfaellig ? 0 : min($buchung['bis'], $anzahlTage - 1);

    [$zustand, $stand] = $standText[$buchung['status']];
    $zweck = $zweckText[$buchung['zweck']] ?? $buchung['zweck'];

    // Hier entscheidet sich, was die Rolle sieht.
    if ($fuhrparkleiter) {
        // Hat jemand anderes für den Fahrer gebucht, steht das dabei.
        $hinweis = $stand . ': ' . $buchung['fahrer']
            . ($buchung['antragsteller'] !== $buchung['fahrer'] ? ' (gebucht von ' . $buchung['antragsteller'] . ')' : '')
            . ', ' . $zweck;
        $text = explode(' ', $buchung['fahrer'])[0];
        $link = $buchung['status'] === 'offen' ? 'genehmigungen.php' : null;
    } else {
        // Eigene Buchung: andere Namen nur, wenn ich nicht selbst fahre oder
        // nicht selbst gebucht habe.
        $hinweis = ($buchung['status'] === 'genehmigt' ? 'genehmigt' : $stand) . ': ' . $zweck
            . ($buchung['fahrer'] !== $ich ? ', Fahrer: ' . $buchung['fahrer'] : '')
            . ($buchung['antragsteller'] !== $ich ? ', gebucht von ' . $buchung['antragsteller'] : '')
            . ($fahrzeuge[$id]['status'] === 'wartung' ? ', Fahrzeug in Wartung' : '');
        $text = '';
        $link = $ueberfaellig ? 'rueckgabe.php' : 'meine-buchungen.php';
    }

    $zustaende = [$zustand];

    if ($ueberfaellig) {
        $hinweis .= ', Rückgabe überfällig';
        $zustaende[] = 'ueberfaellig';
    }

    for ($i = $von; $i <= $bis; $i++) {
        $zellen[$id][$i] = [
            'zustaende' => $zustaende,
            // Kurztext nur am ersten Tag, sonst wiederholt er sich über die
            // ganze Buchung.
            'text'      => $i === $von ? $text : '',
            'hinweis'   => $hinweis,
            'link'      => $link,
        ];
    }
}

// CSS-Klassen je Zelle, damit das Template sie nur noch ausgibt.
foreach ($zellen as $id => $zeile) {
    foreach ($zeile as $i => $zelle) {
        $zellen[$id][$i]['klasse'] = 'kalender__zelle kalender__zelle--'
            . implode(' kalender__zelle--', $zelle['zustaende']);
    }
}

// --- Gruppen, Kennzahl, Legende ---------------------------------------------

$gruppen = [];
foreach ($fahrzeuge as $id => $fahrzeug) {
    $gruppen[$fahrzeug['art']][$id] = $fahrzeug;
}

// Nur für den Fuhrparkleiter: Mitarbeiter sehen nicht alle Fahrzeuge.
$freiHeute = 0;
foreach ($zellen as $zeile) {
    if ($zeile[0]['zustaende'][0] === 'frei') {
        $freiHeute++;
    }
}

// Zustand => Beschriftung.
$legende = $fuhrparkleiter
    ? [
        'frei'         => 'frei',
        'beantragt'    => 'beantragt',
        'vergeben'     => 'vergeben',
        'wartung'      => 'in Wartung',
        'ueberfaellig' => 'Rückgabe überfällig',
    ]
    : [
        'beantragt'    => 'beantragt',
        'vergeben'     => 'genehmigt oder unterwegs',
        'ueberfaellig' => 'Rückgabe überfällig',
        'leer'         => 'keine eigene Buchung',
    ];

require_once __DIR__ . '/includes/header.php';
?>

<p class="note">
    Prototyp &ndash; Beispieldaten, noch ohne Funktion.
    <?php if (!$fuhrparkleiter): ?>
        Angemeldet als <?= e($ich) ?>.
    <?php endif; ?>
</p>

<p class="lead">
    <?php if ($fuhrparkleiter): ?>
        Belegung vom <?= e($heute->format('d.m.')) ?> bis <?= e($letzterTag->format('d.m.Y')) ?>.
        Heute frei: <?= e((string) $freiHeute) ?> von <?= e((string) count($fahrzeuge)) ?> Fahrzeugen.
        Fahrer und Zweck erscheinen beim Zeigen auf eine Buchung; beantragte Buchungen führen zu den
        offenen Anträgen.
    <?php else: ?>
        Ihre Buchungen vom <?= e($heute->format('d.m.')) ?> bis <?= e($letzterTag->format('d.m.Y')) ?>,
        nur die Fahrzeuge, die Sie gebucht haben. Buchungen anderer sehen Sie hier nicht; ob ein
        Fahrzeug frei ist, zeigt <a href="<?= url('buchen.php') ?>">Fahrzeug buchen</a>.
    <?php endif; ?>
</p>

<?php if ($fahrzeuge === []): ?>

    <p class="note">Sie haben in den nächsten <?= e((string) $anzahlTage) ?> Tagen keine Buchungen.</p>

<?php else: ?>

    <ul class="legende">
        <?php foreach ($legende as $zustand => $beschriftung): ?>
            <li class="legende__eintrag">
                <span class="legende__muster kalender__zelle--<?= e($zustand) ?>"></span>
                <?= e($beschriftung) ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="kalender">
        <table class="kalender__tabelle">
            <thead>
                <tr>
                    <th class="kalender__fahrzeug" scope="col">Fahrzeug</th>
                    <?php foreach ($tage as $i => $tag): ?>
                        <th class="kalender__kopf<?= $tag['wochenende'] ? ' kalender__kopf--wochenende' : '' ?><?= $i === 0 ? ' kalender__kopf--heute' : '' ?>"
                            scope="col">
                            <span class="kalender__wochentag"><?= e($tag['wochentag']) ?></span>
                            <?= e($tag['datum']->format('d.m.')) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>

            <?php foreach ($artText as $art => $artBeschriftung): ?>
                <?php if (isset($gruppen[$art])): ?>
                    <tbody>
                        <tr class="kalender__gruppe">
                            <th colspan="<?= $anzahlTage + 1 ?>" scope="colgroup"><?= e($artBeschriftung) ?></th>
                        </tr>

                        <?php foreach ($gruppen[$art] as $id => $fahrzeug): ?>
                            <tr>
                                <th class="kalender__fahrzeug" scope="row">
                                    <a href="<?= url('fahrzeug.php?id=' . $id) ?>"><?= e($fahrzeug['kennzeichen']) ?></a>
                                    <span class="kalender__modell"><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></span>
                                </th>

                                <?php foreach ($zellen[$id] as $zelle): ?>
                                    <td class="<?= e($zelle['klasse']) ?>" title="<?= e($zelle['hinweis']) ?>">
                                        <?php if ($zelle['link'] !== null): ?>
                                            <a class="kalender__inhalt" href="<?= e(url($zelle['link'])) ?>">
                                                <span aria-hidden="true"><?= e($zelle['text']) ?></span>
                                                <span class="nur-vorlesen"><?= e($zelle['hinweis']) ?></span>
                                            </a>
                                        <?php else: ?>
                                            <span class="kalender__inhalt">
                                                <span aria-hidden="true"><?= e($zelle['text']) ?></span>
                                                <span class="nur-vorlesen"><?= e($zelle['hinweis']) ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endif; ?>
            <?php endforeach; ?>
        </table>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
