<?php
/**
 * Buchungskalender (Anwendungsfall 7, Anforderung B-01), für beide Rollen
 * mit unterschiedlichem Inhalt.
 *
 * Belegung über 14 Tage: Zeilen sind Fahrzeuge, Spalten Tage. Wochenweise
 * blättern, höchstens 4 Wochen über die heutige Ansicht hinaus, nicht in die
 * Vergangenheit: kalender.php?woche=0 bis 4. Reine Anzeige ohne JavaScript
 * und ohne Verschieben (siehe docs/technisches-konzept.md, Regel 7).
 * Gerechnet wird in ganzen Tagen.
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
 * Prototyp: Die Daten kommen aus includes/beispieldaten.php. Mit Datenbank:
 *
 *   SELECT id, kennzeichen, hersteller, modell, art, status
 *     FROM fahrzeuge ORDER BY art, kennzeichen
 *   SELECT b.fahrzeug_id, b.start, b.ende, b.zweck, b.status,
 *          b.fahrer_id, b.antragsteller_id
 *     FROM buchungen b
 *    WHERE b.status IN ('offen', 'genehmigt', 'unterwegs')
 *      AND b.start <= :bis
 *      AND (b.ende >= :von OR b.status = 'unterwegs')
 *      [AND (b.antragsteller_id = :ich OR b.fahrer_id = :ich)]  -- Mitarbeiter
 *
 * Die vorletzte Bedingung holt auch überfällige Fahrten: begonnen, Ende
 * vorbei, noch nicht zurückgegeben.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Buchungskalender';

$fuhrparkleiter = ist_fuhrparkleiter();

$ich = aktueller_nutzer();

$anzahlTage = 14;
$maxWochen  = 4;

// Fahrzeugart => Überschrift der Gruppe, in Anzeigereihenfolge.
$artText = [
    'auto'        => 'Autos',
    'transporter' => 'Transporter',
    'roller'      => 'Roller',
    'fahrrad'     => 'Fahrräder',
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

// --- Woche (GET) ------------------------------------------------------------
// 0 ist die Ansicht ab heute; Werte außerhalb werden auf den Bereich begrenzt.

$woche = filter_var($_GET['woche'] ?? 0, FILTER_VALIDATE_INT);
$woche = $woche === false ? 0 : max(0, min($maxWochen, $woche));

// Erster angezeigter Tag, relativ zu heute.
$ersterTag = $woche * 7;

$heute = new DateTimeImmutable('today');
$fahrzeuge = beispiel_fahrzeuge();

// Belegende Buchungen (offen, genehmigt, unterwegs) mit Tagen relativ zu
// heute.
$buchungen = [];

foreach (beispiel_buchungen() as $b) {
    if (isset($standText[$b['status']])) {
        $b['von'] = (int) $heute->diff($b['start'])->format('%r%a');
        $b['bis'] = (int) $heute->diff($b['ende'])->format('%r%a');
        $buchungen[] = $b;
    }
}

// --- Nur eigene Buchungen (Mitarbeiter) -------------------------------------
// Fremde Buchungen fallen hier weg, dazu die Fahrzeuge, zu denen es im
// Zeitraum keine eigene Buchung gibt. Überfällige Fahrten zählen mit, sie
// erscheinen am heutigen Tag.

if (!$fuhrparkleiter) {
    $buchungen = array_values(array_filter(
        $buchungen,
        fn (array $b): bool => ($b['fahrer_id'] === $ich || $b['antragsteller_id'] === $ich)
            && $b['von'] < $ersterTag + $anzahlTage
            && ($b['bis'] >= $ersterTag || ($b['status'] === 'unterwegs' && $woche === 0)),
    ));

    $fahrzeuge = array_intersect_key($fahrzeuge, array_flip(array_column($buchungen, 'fahrzeug_id')));
}

// --- Tage -------------------------------------------------------------------

$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

$tage = [];
for ($i = 0; $i < $anzahlTage; $i++) {
    $datum = $heute->modify('+' . ($ersterTag + $i) . ' day');
    $tage[] = [
        'datum'      => $datum,
        'wochentag'  => $wochentage[(int) $datum->format('w')],
        'wochenende' => (int) $datum->format('N') >= 6,
        'heute'      => $ersterTag + $i === 0,
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
// Zuerst ist jeder Tag frei, für Mitarbeiter neutral; dann kommen die
// Wartungstage (für beide Rollen), danach die Buchungen. $i ist der Index der
// Spalte, $tag der Tag relativ zu heute.
//
// Wartung: bis einschließlich zum voraussichtlichen Ende. Ist das Ende offen
// (noch nicht festgelegt oder überschritten), alle Tage, und der erste ist
// markiert wie eine überfällige Rückgabe.

$zellen = [];

foreach ($fahrzeuge as $id => $fahrzeug) {
    $wartungBis = null;

    if ($fahrzeug['status'] === 'wartung') {
        $wartungBis = wartung_ende_offen($fahrzeug)
            ? PHP_INT_MAX
            : (int) $heute->diff($fahrzeug['wartung_bis'])->format('%r%a');
        $wartungHinweis = 'in Wartung, ' . wartung_text($fahrzeug);
    }

    foreach ($tage as $i => $tagDaten) {
        $tag = $ersterTag + $i;

        if ($wartungBis !== null && $tag <= $wartungBis) {
            $zellen[$id][$i] = [
                'zustaende' => wartung_ende_offen($fahrzeug) && $i === 0 ? ['wartung', 'ueberfaellig'] : ['wartung'],
                'text'      => $i === 0 ? 'Wartung' : '',
                'hinweis'   => $wartungHinweis,
                'link'      => null,
                'wartung'   => true,
            ];
        } else {
            $zellen[$id][$i] = $fuhrparkleiter
                ? ['zustaende' => ['frei'], 'text' => '', 'hinweis' => 'frei',                 'link' => null, 'wartung' => false]
                : ['zustaende' => ['leer'], 'text' => '', 'hinweis' => 'keine eigene Buchung', 'link' => null, 'wartung' => false];
        }
    }
}

foreach ($buchungen as $buchung) {
    $id = $buchung['fahrzeug_id'];

    if (!isset($fahrzeuge[$id])) {
        continue;
    }

    // Eine begonnene Fahrt, deren Ende vorbei ist, blockiert das Fahrzeug bis
    // zur Rückgabe. Wann die kommt, ist unbekannt, daher nur heute.
    $ueberfaellig = $buchung['status'] === 'unterwegs' && $buchung['bis'] < 0;

    $von = $ueberfaellig ? 0 : max($buchung['von'], $ersterTag);
    $bis = $ueberfaellig ? 0 : min($buchung['bis'], $ersterTag + $anzahlTage - 1);

    if ($von > $bis || $von < $ersterTag) {
        continue;
    }

    [$zustand, $stand] = $standText[$buchung['status']];
    $zweck = $zweckText[$buchung['zweck']] ?? $buchung['zweck'];

    // Hier entscheidet sich, was die Rolle sieht.
    if ($fuhrparkleiter) {
        // Hat jemand anderes für den Fahrer gebucht, steht das dabei.
        $hinweis = $stand . ': ' . nutzer_name($buchung['fahrer_id'])
            . ($buchung['antragsteller_id'] !== $buchung['fahrer_id'] ? ' (gebucht von ' . nutzer_name($buchung['antragsteller_id']) . ')' : '')
            . ', ' . $zweck;
        $text = beispiel_nutzer()[$buchung['fahrer_id']]['vorname'];
        $link = $buchung['status'] === 'offen' ? 'genehmigungen.php' : null;
    } else {
        // Eigene Buchung: andere Namen nur, wenn ich nicht selbst fahre oder
        // nicht selbst gebucht habe.
        $hinweis = ($buchung['status'] === 'genehmigt' ? 'genehmigt' : $stand) . ': ' . $zweck
            . ($buchung['fahrer_id'] !== $ich ? ', Fahrer: ' . nutzer_name($buchung['fahrer_id']) : '')
            . ($buchung['antragsteller_id'] !== $ich ? ', gebucht von ' . nutzer_name($buchung['antragsteller_id']) : '');
        $text = '';
        $link = $ueberfaellig ? 'rueckgabe.php?buchung=' . $buchung['id'] : 'meine-buchungen.php';
    }

    $zustaende = [$zustand];

    if ($ueberfaellig) {
        $hinweis .= ', Rückgabe überfällig';
        $zustaende[] = 'ueberfaellig';
    }

    for ($tag = $von; $tag <= $bis; $tag++) {
        // Wartung geht vor; Buchungen in der Wartung werden storniert.
        if ($zellen[$id][$tag - $ersterTag]['wartung']) {
            continue;
        }

        $zellen[$id][$tag - $ersterTag] = [
            'zustaende' => $zustaende,
            // Kurztext nur am ersten Tag, sonst wiederholt er sich über die
            // ganze Buchung.
            'text'      => $tag === $von ? $text : '',
            'hinweis'   => $hinweis,
            'link'      => $link,
            'wartung'   => false,
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

// Nur für den Fuhrparkleiter und nur in der Ansicht ab heute: Mitarbeiter
// sehen nicht alle Fahrzeuge.
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
        'ueberfaellig' => 'überfällig (Rückgabe oder Wartung)',
    ]
    : [
        'beantragt'    => 'beantragt',
        'vergeben'     => 'genehmigt oder unterwegs',
        'wartung'      => 'in Wartung',
        'ueberfaellig' => 'überfällig (Rückgabe oder Wartung)',
        'leer'         => 'keine eigene Buchung',
    ];

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    <?php if ($fuhrparkleiter): ?>
        Belegung vom <?= e($tage[0]['datum']->format('d.m.')) ?> bis <?= e($letzterTag->format('d.m.Y')) ?>.
        <?php if ($woche === 0): ?>
            Heute frei: <?= e((string) $freiHeute) ?> von <?= e((string) count($fahrzeuge)) ?> Fahrzeugen.
        <?php endif; ?>
        Fahrer und Zweck erscheinen beim Zeigen auf eine Buchung; beantragte Buchungen führen zu den
        offenen Anträgen.
    <?php else: ?>
        Ihre Buchungen vom <?= e($tage[0]['datum']->format('d.m.')) ?> bis <?= e($letzterTag->format('d.m.Y')) ?>,
        nur die Fahrzeuge, die Sie gebucht haben. Buchungen anderer sehen Sie hier nicht; ob ein
        Fahrzeug frei ist, zeigt <a href="<?= url('buchen.php') ?>">Fahrzeug buchen</a>.
    <?php endif; ?>
</p>

<!-- Wochenweise blättern, ohne JavaScript. -->
<nav class="blaettern" aria-label="Zeitraum wechseln">
    <?php if ($woche > 0): ?>
        <a class="button button--klein button--zweitrangig"
           href="<?= url('kalender.php' . ($woche > 1 ? '?woche=' . ($woche - 1) : '')) ?>">&larr; Vorige Woche</a>
    <?php endif; ?>
    <?php if ($woche > 1): ?>
        <a class="button button--klein button--zweitrangig" href="<?= url('kalender.php') ?>">Ab heute</a>
    <?php endif; ?>
    <?php if ($woche < $maxWochen): ?>
        <a class="button button--klein button--zweitrangig"
           href="<?= url('kalender.php?woche=' . ($woche + 1)) ?>">Nächste Woche &rarr;</a>
    <?php endif; ?>
</nav>

<?php if ($fahrzeuge === []): ?>

    <p class="note">Sie haben in diesem Zeitraum keine Buchungen.</p>

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
                    <?php foreach ($tage as $tag): ?>
                        <th class="kalender__kopf<?= $tag['wochenende'] ? ' kalender__kopf--wochenende' : '' ?><?= $tag['heute'] ? ' kalender__kopf--heute' : '' ?>"
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
