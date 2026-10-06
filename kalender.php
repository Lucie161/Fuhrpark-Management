<?php
/**
 * Buchungskalender (Anwendungsfall 7, Anforderung B-01).
 *
 * Belegung aller Fahrzeuge für die nächsten 14 Tage: Zeilen sind Fahrzeuge,
 * Spalten Tage. Reine Anzeige ohne JavaScript und ohne Verschieben (siehe
 * docs/technisches-konzept.md, Regel 7). Gerechnet wird in ganzen Tagen.
 *
 * Datenschutz: Wer ein Fahrzeug gebucht hat und wozu, sieht nur der
 * Fuhrparkleiter. Mitarbeiter sehen bei fremden Buchungen nur den Stand
 * („vergeben“, „beantragt“), bei eigenen auch den Zweck. Name und Zweck werden
 * für sie gar nicht erst ausgegeben, auch nicht im title-Attribut, denn das
 * steht im Seitenquelltext.
 *
 * Prototyp ohne Funktion: Fahrzeuge und Buchungen sind feste Beispieldaten.
 * Mit Datenbank werden sie ersetzt durch:
 *
 *   SELECT id, kennzeichen, hersteller, modell, art, status
 *     FROM fahrzeuge ORDER BY art, kennzeichen
 *   SELECT b.fahrzeug_id, b.start, b.ende, b.zweck, b.status,
 *          b.antragsteller_id, b.fahrer_id, n.name AS fahrer
 *     FROM buchungen b JOIN nutzer n ON n.id = b.fahrer_id
 *    WHERE b.status IN ('offen', 'genehmigt', 'unterwegs')
 *      AND b.start <= :bis
 *      AND (b.ende >= :heute OR b.status = 'unterwegs')
 *
 * Die letzte Bedingung holt auch überfällige Fahrten: begonnen, Ende vorbei,
 * noch nicht zurückgegeben.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Buchungskalender';

// Angemeldeter Nutzer. Kommt später aus der Session.
$ich = 'Lucie Schneider';
$istLeiter = ist_fuhrparkleiter();

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

// Stand einer belegenden Buchung => Beschriftung im Kalender.
$standText = [
    'offen'     => 'beantragt',
    'genehmigt' => 'vergeben',
    'unterwegs' => 'unterwegs',
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
    ['fahrzeug_id' => 1, 'von' => 10, 'bis' => 11, 'fahrer' => $ich,              'antragsteller' => $ich,              'zweck' => 'lieferant',         'status' => 'offen'],
    ['fahrzeug_id' => 2, 'von' => 5,  'bis' => 6,  'fahrer' => $ich,              'antragsteller' => 'Kenneth Sander',  'zweck' => 'montage',           'status' => 'offen'],
    ['fahrzeug_id' => 3, 'von' => 0,  'bis' => 2,  'fahrer' => 'Kenneth Sander',  'antragsteller' => 'Kenneth Sander',  'zweck' => 'montage',           'status' => 'unterwegs'],
    ['fahrzeug_id' => 3, 'von' => 7,  'bis' => 8,  'fahrer' => 'Larissa Wagner',  'antragsteller' => 'Larissa Wagner',  'zweck' => 'materialtransport', 'status' => 'genehmigt'],
    ['fahrzeug_id' => 5, 'von' => 2,  'bis' => 2,  'fahrer' => $ich,              'antragsteller' => $ich,              'zweck' => 'kundentermin',      'status' => 'genehmigt'],
    ['fahrzeug_id' => 6, 'von' => 8,  'bis' => 9,  'fahrer' => 'Kenneth Sander',  'antragsteller' => 'Kenneth Sander',  'zweck' => 'aufmass',           'status' => 'offen'],
    ['fahrzeug_id' => 7, 'von' => -2, 'bis' => -1, 'fahrer' => $ich,              'antragsteller' => $ich,              'zweck' => 'kundentermin',      'status' => 'unterwegs'],
    ['fahrzeug_id' => 8, 'von' => 0,  'bis' => 0,  'fahrer' => $ich,              'antragsteller' => $ich,              'zweck' => 'aufmass',           'status' => 'genehmigt'],
    ['fahrzeug_id' => 9, 'von' => 3,  'bis' => 4,  'fahrer' => 'Finn Clausen',    'antragsteller' => 'Finn Clausen',    'zweck' => 'aufmass',           'status' => 'genehmigt'],
];

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
//   'zustaende' – CSS-Modifier, der erste ist frei, vergeben oder wartung
//   'text'      – sichtbarer Kurztext, oft leer
//   'hinweis'   – vollständige Angabe für title und Screenreader
//   'link'      – Ziel beim Anklicken oder null
// Zuerst ist jeder Tag frei bzw. in Wartung, danach werden die Buchungen
// eingetragen.

$zellen = [];

foreach ($fahrzeuge as $id => $fahrzeug) {
    foreach ($tage as $i => $tag) {
        if ($fahrzeug['status'] === 'wartung') {
            $zellen[$id][$i] = [
                'zustaende' => ['wartung'],
                'text'      => $i === 0 ? 'Wartung' : '',
                'hinweis'   => 'in Wartung',
                'link'      => null,
            ];
            continue;
        }

        // Mitarbeiter buchen einen freien Tag direkt aus dem Kalender. Der
        // Fuhrparkleiter bucht nicht selbst (siehe includes/header.php).
        $datum = $tag['datum']->format('Y-m-d');
        $zellen[$id][$i] = [
            'zustaende' => ['frei'],
            'text'      => '',
            'hinweis'   => $istLeiter ? 'frei' : 'frei, ab ' . $tag['datum']->format('d.m.') . ' buchen',
            'link'      => $istLeiter ? null : 'buchen.php?' . http_build_query([
                'fahrzeug' => $id,
                'beginn'   => $datum,
                'ende'     => $datum,
            ]),
        ];
    }
}

foreach ($buchungen as $buchung) {
    $id = $buchung['fahrzeug_id'];

    // Wartung geht vor: Das Fahrzeug ist gesperrt, egal was gebucht ist.
    if (!isset($fahrzeuge[$id]) || $fahrzeuge[$id]['status'] === 'wartung') {
        continue;
    }

    // Eine begonnene Fahrt, deren Ende vorbei ist, blockiert das Fahrzeug bis
    // zur Rückgabe. Wann die kommt, ist unbekannt, daher nur heute.
    $ueberfaellig = $buchung['status'] === 'unterwegs' && $buchung['bis'] < 0;

    $von = max($buchung['von'], 0);
    $bis = $ueberfaellig ? 0 : min($buchung['bis'], $anzahlTage - 1);

    $stand   = $standText[$buchung['status']];
    $zweck   = $zweckText[$buchung['zweck']] ?? $buchung['zweck'];
    $eigene  = !$istLeiter && ($buchung['fahrer'] === $ich || $buchung['antragsteller'] === $ich);

    // Hier entscheidet sich, was die Rolle sehen darf.
    if ($istLeiter) {
        $text    = explode(' ', $buchung['fahrer'])[0];
        $hinweis = $stand . ': ' . $buchung['fahrer'] . ', ' . $zweck;
        $link    = $buchung['status'] === 'offen' ? 'genehmigungen.php' : null;
    } elseif ($eigene) {
        $text    = 'ich';
        $hinweis = $stand . ': meine Buchung, ' . $zweck;
        $link    = 'meine-buchungen.php';
    } else {
        $text    = '';
        $hinweis = $stand;
        $link    = null;
    }

    if ($ueberfaellig) {
        $hinweis .= ', Rückgabe überfällig';
    }

    $zustaende = ['vergeben'];
    if ($buchung['status'] === 'offen') {
        $zustaende[] = 'beantragt';
    }
    if ($eigene) {
        $zustaende[] = 'eigene';
    }
    if ($ueberfaellig) {
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

$freiHeute = 0;
foreach ($zellen as $zeile) {
    if ($zeile[0]['zustaende'][0] === 'frei') {
        $freiHeute++;
    }
}

// Zustand => Beschriftung. „meine Buchung“ gibt es nur für Mitarbeiter.
$legende = [
    'frei'         => 'frei',
    'vergeben'     => 'vergeben',
    'beantragt'    => 'beantragt',
    'wartung'      => 'in Wartung',
    'ueberfaellig' => 'Rückgabe überfällig',
];
if (!$istLeiter) {
    $legende['eigene'] = 'meine Buchung';
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="note">
    Prototyp &ndash; Beispieldaten, noch ohne Funktion.
    <?php if (!$istLeiter): ?>
        Angemeldet als <?= e($ich) ?>.
    <?php endif; ?>
</p>

<p class="lead">
    Belegung vom <?= e($heute->format('d.m.')) ?> bis <?= e($letzterTag->format('d.m.Y')) ?>.
    Heute frei: <?= $freiHeute ?> von <?= count($fahrzeuge) ?> Fahrzeugen.
    <?php if ($istLeiter): ?>
        Beantragte Buchungen führen zu den offenen Anträgen.
    <?php else: ?>
        Einen freien Tag anklicken, um das Fahrzeug ab diesem Tag zu buchen.
        Wer ein Fahrzeug gebucht hat, sieht nur der Fuhrparkleiter.
    <?php endif; ?>
</p>

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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
