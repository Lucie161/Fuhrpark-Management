<?php
/**
 * Buchungskalender (Anwendungsfall 7, Anforderung B-01), für beide Rollen
 * mit unterschiedlichem Inhalt.
 *
 * Belegung über 14 Tage: Zeilen sind Fahrzeuge, Spalten Tage. Wochenweise
 * blättern, höchstens 4 Wochen über die heutige Ansicht hinaus, nicht in die
 * Vergangenheit: kalender.php?woche=0 bis 4. Reine Anzeige ohne JavaScript
 * und ohne Verschieben (siehe docs/konzept/technisches-konzept.md, Regel 7).
 * Gerechnet wird in ganzen Tagen.
 *
 * Fuhrparkleiter: alle Fahrzeuge und alle Buchungen mit Fahrer und Zweck.
 * Beantragt und vergeben sind getrennte Zustände; ein Antrag führt zu den
 * offenen Anträgen.
 *
 * Mitarbeiter: nur die eigenen Buchungen (beantragt oder als Fahrer
 * eingetragen) und nur die Fahrzeuge, zu denen es eine gibt. Fremde
 * Buchungen werden gar nicht erst abgefragt (Datenschutz). Tage ohne eigene
 * Buchung bleiben neutral, nicht „frei“, denn dort kann jemand anderes gebucht
 * haben. Ob ein Fahrzeug frei ist, zeigt buchen.php.
 *
 * Die Buchungen laden CListeBuchungen::LadenBelegung() und
 * LadenEigeneBelegung(); überfällige Fahrten sind in der Ansicht ab heute dabei.
 * Fahrzeuge und Wartungen kommen noch aus includes/beispieldaten.php, die
 * Überschriften aus FAHRZEUGARTEN_MEHRZAHL, bis Paket B CFahrzeug, CWartung
 * und die Spalte fahrzeugarten.plural liefert.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_angemeldet();

$pageTitle = 'Buchungskalender';

$fuhrparkleiter = ist_fuhrparkleiter();

$ich = aktueller_nutzer();

$anzahlTage = 14;
$maxWochen  = 4;

// Stand einer belegenden Buchung => [Zustand der Zelle, Beschriftung]. Der
// Zustand dient zugleich als CSS-Modifier (.kalender__zelle--beantragt).
// Gruppenüberschriften: FAHRZEUGARTEN_MEHRZAHL, in der Reihenfolge der IDs.
$standText = [
    STATUS_OFFEN     => ['beantragt', 'beantragt'],
    STATUS_GENEHMIGT => ['vergeben',  'vergeben'],
    STATUS_UNTERWEGS => ['vergeben',  'unterwegs'],
];

// --- Woche (GET) ------------------------------------------------------------
// 0 ist die Ansicht ab heute; Werte außerhalb werden auf den Bereich begrenzt.

$woche = filter_var($_GET['woche'] ?? 0, FILTER_VALIDATE_INT);
$woche = $woche === false ? 0 : max(0, min($maxWochen, $woche));

// Erster angezeigter Tag, relativ zu heute.
$ersterTag = $woche * 7;

$heute = new DateTimeImmutable('today');
$fahrzeuge = fahrzeuge_im_bestand();

$Zwecke = new CListeSchluessel('zwecke');

$ansichtVon = $heute->modify('+' . $ersterTag . ' day');
$ansichtBis = $heute->modify('+' . ($ersterTag + $anzahlTage - 1) . ' day');

// --- Buchungen --------------------------------------------------------------
// Belegende Buchungen im Zeitraum (offen, genehmigt, unterwegs). Mitarbeiter
// bekommen nur die eigenen und nur die Fahrzeuge dazu.

$ListeBuchungen = new CListeBuchungen();

if ($fuhrparkleiter) {
    $ListeBuchungen->LadenBelegung($ansichtVon, $ansichtBis, true);
} else {
    $ListeBuchungen->LadenEigeneBelegung($ich, $ansichtVon, $ansichtBis);
    $fahrzeuge = array_intersect_key($fahrzeuge, array_flip($ListeBuchungen->GetFahrzeugIDs()));
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

    if ($fahrzeug['in_wartung']) {
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

foreach ($ListeBuchungen->GetAlle() as $Buchung) {
    $id = $Buchung->GetFahrzeugID();

    if (!isset($fahrzeuge[$id])) {
        continue;
    }

    // Eine begonnene Fahrt, deren Ende vorbei ist, blockiert das Fahrzeug bis
    // zur Rückgabe. Wann die kommt, ist unbekannt, daher nur heute.
    $ueberfaellig = $Buchung->IstUeberfaellig();

    $von = $ueberfaellig ? 0 : max((int) $heute->diff($Buchung->GetStart())->format('%r%a'), $ersterTag);
    $bis = $ueberfaellig ? 0 : min((int) $heute->diff($Buchung->GetEnde())->format('%r%a'), $ersterTag + $anzahlTage - 1);

    [$zustand, $stand] = $standText[$Buchung->GetStatusID()];
    $zweck = $Zwecke->GetBezeichnung($Buchung->GetZweckID());

    // Hier entscheidet sich, was die Rolle sieht.
    if ($fuhrparkleiter) {
        // Hat jemand anderes für den Fahrer gebucht, steht das dabei.
        $hinweis = $stand . ': ' . $Buchung->GetFahrerName()
            . ($Buchung->GetAntragstellerID() !== $Buchung->GetFahrerID() ? ' (gebucht von ' . $Buchung->GetAntragstellerName() . ')' : '')
            . ', ' . $zweck;
        $text = $Buchung->GetFahrerVorname();
        $link = $Buchung->GetStatusID() === STATUS_OFFEN ? 'genehmigungen.php' : null;
    } else {
        // Eigene Buchung: andere Namen nur, wenn ich nicht selbst fahre oder
        // nicht selbst gebucht habe.
        $hinweis = ($Buchung->GetStatusID() === STATUS_GENEHMIGT ? 'genehmigt' : $stand) . ': ' . $zweck
            . ($Buchung->GetFahrerID() !== $ich ? ', Fahrer: ' . $Buchung->GetFahrerName() : '')
            . ($Buchung->GetAntragstellerID() !== $ich ? ', gebucht von ' . $Buchung->GetAntragstellerName() : '');
        $text = '';
        $link = $ueberfaellig ? 'rueckgabe.php?buchung=' . $Buchung->GetID() : 'meine-buchungen.php';
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
    $gruppen[$fahrzeug['art_id']][$id] = $fahrzeug;
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

            <?php foreach (FAHRZEUGARTEN_MEHRZAHL as $art => $artBeschriftung): ?>
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
