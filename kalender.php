<?php
/**
 * Buchungskalender für beide Rollen
 * mit unterschiedlichem Inhalt.
 *
 * Belegung über 14 Tage: Zeilen sind Fahrzeuge, Spalten Tage. Jede Buchung
 * und jede Wartung ist ein Balken über ihre Tage (colspan), freie Tage sind
 * einzelne Zellen. Wochenweise blättern, höchstens 4 Wochen über die heutige
 * Ansicht hinaus, nicht in die Vergangenheit: kalender.php?woche=0 bis 4.
 * Reine Anzeige ohne JavaScript und ohne Verschieben.
 * Gerechnet wird in ganzen Tagen. Auf dem Handy ist die Fahrzeugspalte
 * schmaler und die Tage sind kompakter, der Kalender scrollt seitlich.
 *
 * Fuhrparkleiter: alle Fahrzeuge und alle Buchungen, im Balken der Vorname
 * des Fahrers, Fahrer und Zweck im title. Ein Antrag führt zu den offenen
 * Anträgen.
 *
 * Mitarbeiter: nur die eigenen Buchungen (beantragt oder als Fahrer
 * eingetragen) und nur die Fahrzeuge, zu denen es eine gibt. Fremde
 * Buchungen werden gar nicht erst abgefragt (Datenschutz). Tage ohne eigene
 * Buchung bleiben neutral, nicht „frei“, denn dort kann jemand anderes gebucht
 * haben. Ob ein Fahrzeug frei ist, zeigt buchen.php.
 *
 * Jeder Tag gehört genau einem Balken: Wartung geht vor, sonst behält die
 * früher beginnende Buchung den Tag. Beide Balken nennen die Überschneidung
 * im title, der volle Zeitraum steht dort ohnehin. Reicht ein Balken über den Rand des
 * Zeitraums, ist er dort gerade abgeschnitten.
 *
 * Die Buchungen laden CListeBuchungen::LadenBelegung() und
 * LadenEigeneBelegung(); überfällige Fahrten sind in der Ansicht ab heute dabei.
 * Die Fahrzeuge mit ihrer laufenden Wartung lädt
 * CListeFahrzeuge::LadenImBestand(), den Beginn der Wartung
 * CWartung::LoadLaufende(), die Gruppenüberschriften kommen aus
 * CListeSchluessel::GetPlural().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_angemeldet();

$pageTitle = 'Buchungskalender';

$fuhrparkleiter = ist_fuhrparkleiter();

$ich = aktueller_nutzer();

$anzahlTage = 14;
$maxWochen  = 4;

// --- Woche (GET) ------------------------------------------------------------
// 0 ist die Ansicht ab heute; Werte außerhalb werden auf den Bereich begrenzt.

$woche = filter_var($_GET['woche'] ?? 0, FILTER_VALIDATE_INT);
$woche = $woche === false ? 0 : max(0, min($maxWochen, $woche));

$heute = new DateTimeImmutable('today');

// Erster und letzter angezeigter Tag
$ansichtVon = $heute->modify('+' . ($woche * 7) . ' day');
$ansichtBis = $ansichtVon->modify('+' . ($anzahlTage - 1) . ' day');

// Fahrzeuge im Bestand, nach ID: Fahrzeug-ID => CFahrzeug
$ListeFahrzeuge = new CListeFahrzeuge();
$ListeFahrzeuge->LadenImBestand();

$fahrzeuge = [];
foreach ($ListeFahrzeuge->GetAlle() as $Fahrzeug) {
    $fahrzeuge[$Fahrzeug->GetID()] = $Fahrzeug;
}

$Zwecke = new CListeSchluessel('zwecke');
$Fahrzeugarten = new CListeSchluessel('fahrzeugarten');

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
    $datum = $ansichtVon->modify('+' . $i . ' day');
    $tage[] = [
        'datum'      => $datum,
        'wochentag'  => $wochentage[(int) $datum->format('w')],
        'wochenende' => (int) $datum->format('N') >= 6,
        'heute'      => $datum == $heute,
    ];
}

// --- Balken -----------------------------------------------------------------
// $belegung[Fahrzeug-ID][Tag] ist der Schlüssel des Balkens, der den Tag
// belegt, oder null (frei). Tag 0 ist der erste angezeigte Tag.
// $balken[Schlüssel] beschreibt einen Balken:
//   'text'    – Kurztext im Balken
//   'hinweis' – vollständige Angabe für title und Screenreader
//   'link'    – Ziel beim Anklicken oder null
//   'farbe'   – Ampelfarbe des Stands: gruen, gelb, rot oder grau
//   'antrag'  – noch nicht entschieden, wird gestrichelt gezeigt
//   'davor', 'danach'  – beginnt vor bzw. endet nach dem Zeitraum
//   'ueberschneidung'  – ein anderer Balken wollte einen seiner Tage

$belegung = [];
$balken = [];

// Wartung zuerst, sie geht vor. Sie gilt ab heute bis einschließlich zum
// voraussichtlichen Ende. Ist das Ende offen (noch nicht festgelegt oder
// überschritten), gilt sie für alle Tage und ist rot wie eine überfällige
// Rückgabe.
foreach ($fahrzeuge as $id => $Fahrzeug) {
    $belegung[$id] = array_fill(0, $anzahlTage, null);

    if (!$Fahrzeug->IstInWartung()) {
        continue;
    }

    $endeOffen = $Fahrzeug->IstWartungEndeOffen();

    // Letzter Wartungstag als Tag im Zeitraum
    $bis = $endeOffen
        ? $anzahlTage - 1
        : min((int) $ansichtVon->diff($Fahrzeug->GetWartungBis())->format('%r%a'), $anzahlTage - 1);

    // Endet die Wartung vor dem Zeitraum, belegt sie keinen Tag.
    if ($bis < 0) {
        continue;
    }

    $schluessel = 'w' . $id;
    $balken[$schluessel] = [
        'text'            => 'Wartung',
        'hinweis'         => 'In Wartung, ' . $Fahrzeug->GetWartungText(),
        'link'            => null,
        'farbe'           => $endeOffen ? 'rot' : 'gelb',
        'antrag'          => false,
        'davor'           => CWartung::LoadLaufende($id)->GetBegonnenAm() < $ansichtVon,
        'danach'          => $endeOffen || $Fahrzeug->GetWartungBis() > $ansichtBis,
        'ueberschneidung' => false,
    ];

    for ($tag = 0; $tag <= $bis; $tag++) {
        $belegung[$id][$tag] = $schluessel;
    }
}

// Dann die Buchungen, nach Beginn sortiert.
foreach ($ListeBuchungen->GetAlle() as $Buchung) {
    $id = $Buchung->GetFahrzeugID();

    if (!isset($fahrzeuge[$id])) {
        continue;
    }

    // Eine begonnene Fahrt, deren Ende vorbei ist, blockiert das Fahrzeug bis
    // zur Rückgabe. Wann die kommt, ist unbekannt, daher nur heute.
    $ende = $Buchung->IstUeberfaellig() ? $heute : $Buchung->GetEnde();

    // Erster und letzter Tag im Zeitraum
    $von = max((int) $ansichtVon->diff($Buchung->GetStart())->format('%r%a'), 0);
    $bis = min((int) $ansichtVon->diff($ende)->format('%r%a'), $anzahlTage - 1);

    // Stand wie überall; der Fuhrparkleiter sieht einen offenen Antrag als „Antrag“.
    $marke = buchung_stand($Buchung, $ich);
    $antrag = $Buchung->GetStatusID() === STATUS_OFFEN;
    if ($fuhrparkleiter && $antrag) {
        $marke = ['Antrag', 'gelb'];
    }

    $zweck = $Zwecke->GetBezeichnung($Buchung->GetZweckID());
    $zeitraum = zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde());

    // Hier entscheidet sich, was die Rolle sieht.
    if ($fuhrparkleiter) {
        // Vorname im Balken; hat jemand anderes für den Fahrer gebucht, steht das dabei.
        $text = $Buchung->GetFahrerVorname();
        $zeile = $Buchung->GetFahrerName()
            . ($Buchung->GetAntragstellerID() !== $Buchung->GetFahrerID() ? ' (gebucht von ' . $Buchung->GetAntragstellerName() . ')' : '')
            . ', ' . $zweck;
        $hinweis = $marke[0] . ': ' . $zeile . ', ' . $zeitraum;
        $link = $antrag ? 'genehmigungen.php' : null;
    } else {
        // Eigene Buchung: der Stand im Balken; andere Namen nur, wenn ich nicht
        // selbst fahre oder nicht selbst gebucht habe.
        $text = $marke[0];
        $zeile = $zweck
            . ($Buchung->GetFahrerID() !== $ich ? ', Fahrer: ' . $Buchung->GetFahrerName() : '')
            . ($Buchung->GetAntragstellerID() !== $ich ? ', gebucht von ' . $Buchung->GetAntragstellerName() : '');
        $hinweis = $marke[0] . ': ' . $zeile . ', ' . $zeitraum;
        $link = $Buchung->IstUeberfaellig() ? 'rueckgabe.php?buchung=' . $Buchung->GetID() : 'meine-buchungen.php';
    }

    $schluessel = 'b' . $Buchung->GetID();
    $balken[$schluessel] = [
        'text'            => $text,
        'hinweis'         => $hinweis,
        'link'            => $link,
        'farbe'           => $marke[1],
        'antrag'          => $antrag,
        'davor'           => $Buchung->GetStart() < $ansichtVon,
        'danach'          => $ende > $ansichtBis,
        'ueberschneidung' => false,
    ];

    // Tage belegen. Ein schon belegter Tag bleibt beim ersten Balken; beide
    // nennen dann die Überschneidung.
    for ($tag = $von; $tag <= $bis; $tag++) {
        $erster = $belegung[$id][$tag];

        if ($erster === null) {
            $belegung[$id][$tag] = $schluessel;
        } else {
            $balken[$erster]['ueberschneidung'] = true;
            $balken[$schluessel]['ueberschneidung'] = true;
        }
    }
}

// --- Spannen je Fahrzeug ----------------------------------------------------
// Eine Spanne ist eine Tabellenzelle: ['balken' => Schlüssel oder null,
// 'tag' => erster Tag, 'tage' => colspan]. Gehört ein Tag zum selben Balken
// wie der Vortag, wird die letzte Spanne um einen Tag länger; sonst beginnt
// eine neue. Freie Tage bleiben einzelne Zellen.

$spannen = [];

foreach ($belegung as $id => $tageBelegt) {
    $spannen[$id] = [];

    foreach ($tageBelegt as $tag => $schluessel) {
        $letzte = count($spannen[$id]) - 1;

        if ($schluessel !== null && $letzte >= 0 && $spannen[$id][$letzte]['balken'] === $schluessel) {
            $spannen[$id][$letzte]['tage']++;
        } else {
            $spannen[$id][] = ['balken' => $schluessel, 'tag' => $tag, 'tage' => 1];
        }
    }
}

// --- Gruppen, Kennzahl, Legende ---------------------------------------------

// Art-ID => [Fahrzeug-ID => CFahrzeug]; die Reihenfolge der Gruppen gibt
// fahrzeugarten vor.
$gruppen = [];
foreach ($fahrzeuge as $id => $Fahrzeug) {
    $gruppen[$Fahrzeug->GetArtID()][$id] = $Fahrzeug;
}

// Freier Tag: Text für den Screenreader
$freiText = $fuhrparkleiter ? 'frei' : 'keine eigene Buchung';

// Legende: [CSS-Modifier des Musters, Beschriftung]; leer heißt freier Tag.
// Mitarbeiter sehen nur ihre eigenen Buchungen, daher nur deren Stände.
$legende = $fuhrparkleiter
    ? [
        ['gelb kalender__balken--antrag', 'Antrag'],
        ['gruen', 'Genehmigt'],
        ['grau',  'Unterwegs'],
        ['gelb',  'In Wartung, Rückgabe heute fällig'],
        ['rot',   'Überfällig (Rückgabe oder Wartung)'],
        ['',      'frei'],
    ]
    : [
        ['gelb kalender__balken--antrag', 'Offen'],
        ['gruen', 'Genehmigt'],
        ['grau',  'Unterwegs'],
        ['gelb',  'Rückgabe heute'],
        ['rot',   'Überfällig'],
    ];

// Rote Meldung über dem Titel: meine überfälligen Fahrten als Fahrer
if (!$fuhrparkleiter) {
    $ListeLaufende = new CListeBuchungen();
    $ListeLaufende->LadenLaufendeFahrten($ich);
    $bannerFahrten = array_filter($ListeLaufende->GetAlle(), fn (CBuchung $Buchung): bool => $Buchung->IstUeberfaellig());
    $bannerMitNamen = false;
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    <?php if ($fuhrparkleiter): ?>
        Belegung aller Fahrzeuge über 14 Tage. Fahrer und Zweck erscheinen beim Zeigen auf eine
        Buchung. Anträge führen zu den offenen Anträgen.
    <?php else: ?>
        Ihre Buchungen über 14 Tage, nur die Fahrzeuge, die Sie gebucht haben. Buchungen anderer
        sehen Sie hier nicht. Ob ein Fahrzeug frei ist, sehen Sie beim Buchen.
    <?php endif; ?>
</p>

<section aria-labelledby="belegung">
    <div class="kalender-kopf">
        <h2 class="abschnitt" id="belegung">
            <?= $fuhrparkleiter ? 'Belegung' : 'Ihre Buchungen' ?>
            <span class="abschnitt__wert"><?= e(zeitraum_kurz($ansichtVon, $ansichtBis)) ?></span>
        </h2>

        <!-- Wochenweise blättern, ohne JavaScript -->
        <nav class="blaettern" aria-label="Zeitraum wechseln">
            <?php if ($woche > 0): ?>
                <a class="button button--klein button--zweitrangig"
                   href="<?= url('kalender.php' . ($woche > 1 ? '?woche=' . ($woche - 1) : '')) ?>"><span class="icon icon--links" aria-hidden="true"></span>Vorige Woche</a>
            <?php endif; ?>
            <?php if ($woche > 1): ?>
                <a class="button button--klein button--zweitrangig" href="<?= url('kalender.php') ?>">Ab heute</a>
            <?php endif; ?>
            <?php if ($woche < $maxWochen): ?>
                <a class="button button--klein button--zweitrangig"
                   href="<?= url('kalender.php?woche=' . ($woche + 1)) ?>">Nächste Woche<span class="icon icon--rechts" aria-hidden="true"></span></a>
            <?php endif; ?>
        </nav>
    </div>

    <?php if ($fahrzeuge === []): ?>

        <div class="schild schild--leer">
            <p>Sie haben in diesem Zeitraum keine Buchungen.</p>
            <a class="button button--zweitrangig" href="<?= url('buchen.php') ?>">Fahrzeug buchen</a>
        </div>

    <?php else: ?>

        <ul class="legende">
            <?php foreach ($legende as [$muster, $beschriftung]): ?>
                <li class="legende__eintrag">
                    <span class="legende__muster<?= $muster !== '' ? ' kalender__balken--' . e($muster) : '' ?>"></span>
                    <?= e($beschriftung) ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <!-- Der Rahmen scrollt auf schmalen Bildschirmen seitlich -->
        <div class="kalender">
            <table class="kalender__tabelle">
                <thead>
                    <tr>
                        <th class="kalender__fahrzeug" scope="col">Fahrzeug</th>
                        <?php foreach ($tage as $tag): ?>
                            <th class="kalender__kopf<?= $tag['wochenende'] ? ' kalender__kopf--wochenende' : '' ?><?= $tag['heute'] ? ' kalender__kopf--heute' : '' ?>"
                                scope="col">
                                <span class="kalender__wochentag"><?= e($tag['wochentag']) ?></span>
                                <?= e($tag['datum']->format('d.')) ?><span class="kalender__monat"><?= e($tag['datum']->format('m.')) ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <?php
                // Gleiche Modelle zählen, sie werden unten durchnummeriert, z. B. „Charger4 (1)“
                $modellAnzahl = [];
                foreach ($gruppen as $liste) {
                    foreach ($liste as $Fahrzeug) {
                        $modell = $Fahrzeug->GetModell();
                        $modellAnzahl[$modell] = ($modellAnzahl[$modell] ?? 0) + 1;
                    }
                }
                $modellNr = [];
                ?>

                <?php foreach ($Fahrzeugarten->GetAlle() as $Art): ?>
                    <?php if (isset($gruppen[$Art->GetID()])): ?>
                        <tbody>
                            <tr class="kalender__gruppe">
                                <th colspan="<?= $anzahlTage + 1 ?>" scope="colgroup">
                                    <span><?= e($Art->GetPlural() ?? $Art->GetBezeichnung()) ?></span>
                                </th>
                            </tr>

                            <?php foreach ($gruppen[$Art->GetID()] as $id => $Fahrzeug): ?>
                                <tr>
                                    <th class="kalender__fahrzeug table__fahrzeug" scope="row">
                                        <?php
                                        // Modell, bei mehreren gleichen mit Nummer
                                        $modell = $Fahrzeug->GetModell();
                                        if ($modellAnzahl[$modell] > 1) {
                                            $modellNr[$modell] = ($modellNr[$modell] ?? 0) + 1;
                                            $modell .= ' (' . $modellNr[$modell] . ')';
                                        }
                                        $kalenderName = $Fahrzeug->GetHersteller() . ' ' . $modell;
                                        ?>
                                        <!-- Hersteller fällt auf dem Handy weg, ganz im title -->
                                        <a href="<?= url('fahrzeug.php?id=' . $id) ?>" title="<?= e($kalenderName) ?>"><span class="kalender__hersteller"><?= e($Fahrzeug->GetHersteller()) ?> </span><?= e($modell) ?></a>
                                        <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                                    </th>

                                    <?php foreach ($spannen[$id] as $spanne): ?>
                                        <?php if ($spanne['balken'] === null): ?>
                                            <!-- Freier Tag -->
                                            <td class="kalender__tag<?= $tage[$spanne['tag']]['wochenende'] ? ' kalender__tag--wochenende' : '' ?>">
                                                <span class="nur-vorlesen"><?= e($freiText) ?></span>
                                            </td>
                                        <?php else: ?>
                                            <?php
                                            // Balken über seine Tage; am Rand des Zeitraums gerade abgeschnitten
                                            $b = $balken[$spanne['balken']];
                                            $hinweis = $b['hinweis'] . ($b['ueberschneidung'] ? ', überschneidet sich mit einer weiteren Buchung' : '');
                                            $zelleKlasse = 'kalender__belegt'
                                                . ($b['davor'] && $spanne['tag'] === 0 ? ' kalender__belegt--davor' : '')
                                                . ($b['danach'] && $spanne['tag'] + $spanne['tage'] === $anzahlTage ? ' kalender__belegt--danach' : '');
                                            $balkenKlasse = 'kalender__balken kalender__balken--' . $b['farbe']
                                                . ($b['antrag'] ? ' kalender__balken--antrag' : '');
                                            ?>
                                            <td class="<?= e($zelleKlasse) ?>" colspan="<?= e((string) $spanne['tage']) ?>">
                                                <?php if ($b['link'] !== null): ?>
                                                    <a class="<?= e($balkenKlasse) ?>" href="<?= e(url($b['link'])) ?>" title="<?= e($hinweis) ?>">
                                                        <span aria-hidden="true"><?= e($b['text']) ?></span>
                                                        <span class="nur-vorlesen"><?= e($hinweis) ?></span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="<?= e($balkenKlasse) ?>" title="<?= e($hinweis) ?>">
                                                        <span aria-hidden="true"><?= e($b['text']) ?></span>
                                                        <span class="nur-vorlesen"><?= e($hinweis) ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endif; ?>
                <?php endforeach; ?>
            </table>
        </div>

    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
