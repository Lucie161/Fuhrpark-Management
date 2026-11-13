<?php
/**
 * Dashboard: Auswertung des Fuhrparks, nur für den Fuhrparkleiter.
 *
 * Oben die Filterleiste wie in der Historie (Zeitraum, Fahrzeug oder
 * Kategorie, Mitarbeiter), darunter die Kennzahlen der gefilterten Fahrten
 * und drei Diagramme: Fahrten und km je Monat als Säulen, gefahrene km je
 * Fahrzeug und Anzahl der Wartungen je Fahrzeug als liegende Balken. Jede
 * Säule bzw. jeder Balken zeigt den Anteil am größten Wert seines Diagramms.
 *
 * Darunter der Ausblick ab heute, unabhängig vom Zeitraum (Fahrzeug- und
 * Mitarbeiterfilter gelten): gebuchte Fahrzeuge je Tag der nächsten 14 Tage
 * und die HU/AU-Termine der nächsten zwölf Monate.
 *
 * Aufruf wie historie.php, z. B. dashboard.php?von=2026-07-01&auswahl=art:3.
 * Ohne Angabe gilt das laufende und die fünf vorigen Monate.
 *
 * Gezählt werden die abgeschlossenen Fahrten, die im Zeitraum zurückgegeben
 * wurden, wie in der Historie. Wartungen haben keinen Fahrer; mit dem Filter
 * nach einem Mitarbeiter entfallen sie.
 *
 * Daten: Zeitraum und Filter aus includes/auswertung-filter.php, die Fahrten
 * aus CListeBuchungen::LadenHistorie(), ihre Schäden aus
 * CListeSchaeden::LadenRueckgabenImZeitraum(), die Wartungen aus
 * CListeWartungen::LadenImZeitraum().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$pageTitle = 'Dashboard';

// Zeitraum und Filter; ohne Angabe ein halbes Jahr bis heute
$filterStandardVon = (new DateTimeImmutable('today'))->modify('first day of -5 month');
require __DIR__ . '/includes/auswertung-filter.php';

$gefiltert = $listeGefiltert
    || $eingabe['von'] !== $filterStandardVon->format('Y-m-d')
    || $eingabe['bis'] !== $heute->format('Y-m-d');

// --- Laden und filtern ------------------------------------------------------

$ListeFahrten = new CListeBuchungen();
$ListeSchaeden = new CListeSchaeden();
$ListeWartungen = new CListeWartungen();

if ($fehler === []) {
    $ListeFahrten->LadenHistorie($von, $bis);
    $ListeSchaeden->LadenRueckgabenImZeitraum($von, $bis);

    if ($fahrerFilter === null) {
        $ListeWartungen->LadenImZeitraum($von, $bis);
    }
}

$fahrten = array_values(array_filter(
    $ListeFahrten->GetAlle(),
    fn (CBuchung $Fahrt): bool => passt_zur_auswahl($Fahrt->GetFahrzeugID(), $eingabe['auswahl'], $Fahrzeuge)
        && ($fahrerFilter === null || $Fahrt->GetFahrerID() === $fahrerFilter),
));

$wartungen = array_values(array_filter(
    $ListeWartungen->GetAlle(),
    fn (CWartung $Wartung): bool => passt_zur_auswahl($Wartung->GetFahrzeugID(), $eingabe['auswahl'], $Fahrzeuge),
));

// --- Kennzahlen -------------------------------------------------------------

$summe = ['km' => 0, 'schaeden' => 0, 'bemerkungen' => 0];
$mitKm = false;

foreach ($fahrten as $Fahrt) {
    if ($Fahrt->GetGefahreneKm() !== null) {
        $summe['km'] += $Fahrt->GetGefahreneKm();
        $mitKm = true;
    }

    if ($ListeSchaeden->GetBeiRueckgabe($Fahrt->GetID()) !== null) {
        $summe['schaeden']++;
    }

    if ($Fahrt->GetBemerkung() !== '') {
        $summe['bemerkungen']++;
    }
}

// Ohne km-Stand (nur Fahrräder) steht bei den km ein Strich, ebenso bei den
// Wartungen, wenn nach einem Mitarbeiter gefiltert ist.
$kennzahlen = [
    ['wert' => (string) count($fahrten),                                     'label' => 'Fahrten'],
    ['wert' => $mitKm ? km_text($summe['km']) : '–',                         'label' => 'Gefahrene km'],
    ['wert' => (string) $summe['schaeden'],                                  'label' => $summe['schaeden'] === 1 ? 'Schaden gemeldet' : 'Schäden gemeldet'],
    ['wert' => (string) $summe['bemerkungen'],                               'label' => 'Bemerkungen zum Zustand'],
    ['wert' => $fahrerFilter === null ? (string) count($wartungen) : '–',    'label' => 'Wartungen'],
];

// --- Auswertung 1: gefahrene km je Fahrzeug ---------------------------------
// Fahrzeug-ID => [Fahrzeug, Fahrten, km]; km null, wenn das Fahrzeug keinen
// km-Stand hat. Meiste km zuerst.

$jeFahrzeug = [];

foreach ($fahrten as $Fahrt) {
    $id = $Fahrt->GetFahrzeugID();
    $jeFahrzeug[$id] ??= ['fahrzeug' => $Fahrzeuge->GetByID($id), 'fahrten' => 0, 'km' => null];
    $jeFahrzeug[$id]['fahrten']++;

    if ($Fahrt->GetGefahreneKm() !== null) {
        $jeFahrzeug[$id]['km'] = ($jeFahrzeug[$id]['km'] ?? 0) + $Fahrt->GetGefahreneKm();
    }
}

usort($jeFahrzeug, fn (array $a, array $b): int => [$b['km'] ?? -1, $b['fahrten']] <=> [$a['km'] ?? -1, $a['fahrten']]);

// --- Auswertung 2: Fahrten und km je Monat ----------------------------------
// Jeder Monat im Zeitraum, auch ohne Fahrten. Schlüssel 'JJJJ-MM'.

$monatsnamen = ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'];
$jeMonat = [];

if ($fehler === []) {
    for ($monat = $von->modify('first day of this month'); $monat <= $bis; $monat = $monat->modify('+1 month')) {
        $jeMonat[$monat->format('Y-m')] = [
            'name'    => $monatsnamen[(int) $monat->format('n') - 1] . ' ' . $monat->format('Y'),
            'fahrten' => 0,
            'km'      => 0,
        ];
    }
}

foreach ($fahrten as $Fahrt) {
    $schluessel = $Fahrt->GetZurueckgegebenAm()->format('Y-m');
    $jeMonat[$schluessel]['fahrten']++;
    $jeMonat[$schluessel]['km'] += $Fahrt->GetGefahreneKm() ?? 0;
}

// --- Auswertung 3: Wartungen je Fahrzeug ------------------------------------
// Anzahl der Wartungen und Tage in Wartung, nur der Teil im Zeitraum. Eine
// laufende Wartung zählt bis heute. Meiste Wartungen zuerst, dann meiste Tage.

$wartungJeFahrzeug = [];

foreach ($wartungen as $Wartung) {
    $id = $Wartung->GetFahrzeugID();
    $beginn = max($Wartung->GetBegonnenAm(), $von);
    $ende = min($Wartung->GetFreigegebenAm() ?? $heute, $bis);
    $tage = $ende >= $beginn ? $beginn->diff($ende)->days + 1 : 0;

    $wartungJeFahrzeug[$id] ??= ['fahrzeug' => $Fahrzeuge->GetByID($id), 'anzahl' => 0, 'tage' => 0];
    $wartungJeFahrzeug[$id]['anzahl']++;
    $wartungJeFahrzeug[$id]['tage'] += $tage;
}

usort($wartungJeFahrzeug, fn (array $a, array $b): int => [$b['anzahl'], $b['tage']] <=> [$a['anzahl'], $a['tage']]);

// --- Ausblick 1: gebuchte Fahrzeuge je Tag, die nächsten 14 Tage -------------
// Belegende Buchungen (offen, genehmigt, unterwegs). Je Tag zählt jedes
// Fahrzeug einmal: bestätigt (genehmigt, unterwegs) geht vor Antrag. Eine
// überfällige Fahrt belegt ihr Fahrzeug bis heute.

$ausblickBis = $heute->modify('+13 day');
$ListeAnstehende = new CListeBuchungen();
$ListeAnstehende->LadenBelegung($heute, $ausblickBis);

$jeTag = [];
for ($tag = $heute; $tag <= $ausblickBis; $tag = $tag->modify('+1 day')) {
    $jeTag[$tag->format('Y-m-d')] = ['datum' => $tag, 'fest' => [], 'antrag' => []];
}

foreach ($ListeAnstehende->GetAlle() as $Buchung) {
    if (!passt_zur_auswahl($Buchung->GetFahrzeugID(), $eingabe['auswahl'], $Fahrzeuge)
        || ($fahrerFilter !== null && $Buchung->GetFahrerID() !== $fahrerFilter)) {
        continue;
    }

    $art = $Buchung->GetStatusID() === STATUS_OFFEN ? 'antrag' : 'fest';
    $ende = $Buchung->IstUeberfaellig() ? $heute : min($Buchung->GetEnde(), $ausblickBis);

    for ($tag = max($Buchung->GetStart(), $heute); $tag <= $ende; $tag = $tag->modify('+1 day')) {
        $jeTag[$tag->format('Y-m-d')][$art][$Buchung->GetFahrzeugID()] = true;
    }
}

// Maßstab: alle Fahrzeuge der Auswahl, die noch im Bestand sind
$imBestand = array_filter(
    $Fahrzeuge->GetAlle(),
    fn (CFahrzeug $Fahrzeug): bool => !$Fahrzeug->IstAusgemustert()
        && passt_zur_auswahl($Fahrzeug->GetID(), $eingabe['auswahl'], $Fahrzeuge),
);
$anzahlBestand = count($imBestand);

// --- Ausblick 2: HU/AU je Monat, die nächsten zwölf Monate -------------------
// Überfällige zuerst als eigene Säule. Fahrzeuge ohne HU/AU (Fahrräder)
// zählen nicht.

$dieserMonat = $heute->modify('first day of this month');
$huAu = ['ueberfaellig' => ['name' => 'Überfällig', 'fahrzeuge' => []]];

for ($i = 0; $i < 12; $i++) {
    $monat = $dieserMonat->modify('+' . $i . ' month');
    $huAu[$monat->format('Y-m')] = [
        'name'      => $monatsnamen[(int) $monat->format('n') - 1] . ' ' . $monat->format('y'),
        'fahrzeuge' => [],
    ];
}

foreach ($imBestand as $Fahrzeug) {
    $termin = $Fahrzeug->GetHuAu();

    if ($termin === null) {
        continue;
    }

    $schluessel = $termin < $dieserMonat ? 'ueberfaellig' : $termin->format('Y-m');

    if (isset($huAu[$schluessel])) {
        $huAu[$schluessel]['fahrzeuge'][] = $Fahrzeug;
    }
}

/**
 * Name eines Fahrzeugs für die Diagramme: [Hersteller und Modell,
 * Kennzeichen mit „ausgemustert“].
 */
function diagramm_name(CFahrzeug $Fahrzeug): array
{
    return [
        $Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell(),
        $Fahrzeug->GetKennzeichen() . ($Fahrzeug->IstAusgemustert() ? ', ausgemustert' : ''),
    ];
}

/**
 * Breite eines Balkens in Prozent: Anteil am größten Wert, 0 ohne Wert.
 */
function balken_anteil(?int $wert, int $hoechster): int
{
    return $wert === null || $hoechster === 0 ? 0 : (int) round($wert / $hoechster * 100);
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Auswertung der abgeschlossenen Fahrten und Wartungen. Eine Fahrt zählt zum Tag ihrer Rückgabe.
    Die einzelnen Fahrten stehen in der <a href="<?= url('historie.php') ?>">Historie</a>.
</p>

<?php
$filterZiel = 'dashboard.php';
require __DIR__ . '/includes/auswertung-filterleiste.php';
?>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php else: ?>

    <!-- Kennzahlen der gefilterten Fahrten im Zeitraum -->
    <section class="dashboard__abschnitt dashboard-kennwerte" aria-labelledby="kennzahlen">
        <h2 class="abschnitt" id="kennzahlen">
            <?= $listeGefiltert ? 'Gefiltert' : 'Ganzer Fuhrpark' ?>
            <span class="abschnitt__wert"><?= e(zeitraum_kurz($von, $bis)) ?></span>
            <?php if ($auswahlText !== null): ?>
                <span class="abschnitt__zusatz"><?= e($auswahlText) ?></span>
            <?php endif; ?>
            <?php if ($fahrerFilter !== null): ?>
                <span class="abschnitt__zusatz"><?= e($mitarbeiter[$fahrerFilter]) ?></span>
            <?php endif; ?>
        </h2>

        <article class="schild">
            <dl class="felder">
                <?php foreach ($kennzahlen as $kennzahl): ?>
                    <div class="feld">
                        <dt><?= e($kennzahl['label']) ?></dt>
                        <dd class="feld__zahl"><?= e($kennzahl['wert']) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </article>
    </section>

    <!-- Auswertung 2 zuerst: Säulen je Monat über die ganze Breite -->
    <section class="dashboard__abschnitt" aria-labelledby="je-monat">
        <h2 class="abschnitt" id="je-monat">
            Fahrten und km je Monat
            <span class="abschnitt__zusatz">nach Tag der Rückgabe</span>
        </h2>

        <?php $hoechster = max([0, ...array_column($jeMonat, 'km')]); ?>
        <article class="schild diagramm">
            <ol class="saeulen">
                <?php foreach ($jeMonat as $zeile): ?>
                    <li class="saeule" title="<?= e($zeile['name'] . ': ' . $zeile['fahrten'] . ' Fahrten, ' . km_text($zeile['km']) . ' km') ?>">
                        <span class="saeule__flaeche">
                            <span class="saeule__wert"><?= e(km_text($zeile['km'])) ?><span class="saeule__einheit"> km</span></span>
                            <span class="saeule__balken" style="height: <?= balken_anteil($zeile['km'], $hoechster) * 0.82 ?>%"></span>
                        </span>
                        <span class="saeule__monat"><?= e($zeile['name']) ?></span>
                        <span class="saeule__zusatz"><?= e((string) $zeile['fahrten']) ?> <?= $zeile['fahrten'] === 1 ? 'Fahrt' : 'Fahrten' ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </article>
    </section>

    <div class="dashboard__paar">
        <!-- Auswertung 1: gefahrene km je Fahrzeug, liegende Balken -->
        <section class="dashboard__abschnitt" aria-labelledby="km-fahrzeug">
            <h2 class="abschnitt" id="km-fahrzeug">
                Gefahrene km je Fahrzeug
                <span class="abschnitt__zusatz">meiste zuerst</span>
            </h2>

            <?php $hoechster = max([0, ...array_map(fn (array $zeile): int => $zeile['km'] ?? 0, $jeFahrzeug)]); ?>
            <article class="schild diagramm">
                <?php if ($jeFahrzeug === []): ?>
                    <p class="diagramm__leer">Keine Fahrten für diese Auswahl im Zeitraum.</p>
                <?php else: ?>
                    <ol class="balkenliste">
                        <?php foreach ($jeFahrzeug as $zeile): ?>
                            <li class="balkenliste__zeile">
                                <?php [$name, $kennzeichen] = diagramm_name($zeile['fahrzeug']); ?>
                                <span class="balkenliste__name">
                                    <?= e($name) ?>
                                    <small><?= e($kennzeichen) ?> &middot; <?= e((string) $zeile['fahrten']) ?> <?= $zeile['fahrten'] === 1 ? 'Fahrt' : 'Fahrten' ?></small>
                                </span>
                                <span class="balkenliste__spur">
                                    <span class="balkenliste__balken" style="--anteil: <?= balken_anteil($zeile['km'], $hoechster) / 100 ?>"></span>
                                    <?php if ($zeile['km'] === null): ?>
                                        <span class="balkenliste__wert balkenliste__wert--leer">kein km-Stand</span>
                                    <?php else: ?>
                                        <span class="balkenliste__wert"><?= e(km_text($zeile['km'])) ?> km</span>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </article>
        </section>

        <!-- Auswertung 3: Wartungen je Fahrzeug, Tage als liegende Balken -->
        <section class="dashboard__abschnitt" aria-labelledby="wartungen">
            <h2 class="abschnitt" id="wartungen">
                Wartungen je Fahrzeug
                <span class="abschnitt__zusatz">Anzahl im Zeitraum</span>
            </h2>

            <?php $hoechster = max([0, ...array_column($wartungJeFahrzeug, 'anzahl')]); ?>
            <article class="schild diagramm">
                <?php if ($fahrerFilter !== null): ?>
                    <p class="diagramm__leer">Wartungen haben keinen Fahrer; mit dem Filter nach einem Mitarbeiter entfallen sie.</p>
                <?php elseif ($wartungJeFahrzeug === []): ?>
                    <p class="diagramm__leer">Keine Wartungen für diese Auswahl im Zeitraum.</p>
                <?php else: ?>
                    <ol class="balkenliste">
                        <?php foreach ($wartungJeFahrzeug as $zeile): ?>
                            <li class="balkenliste__zeile">
                                <?php [$name, $kennzeichen] = diagramm_name($zeile['fahrzeug']); ?>
                                <span class="balkenliste__name">
                                    <?= e($name) ?>
                                    <small><?= e($kennzeichen) ?> &middot; <?= e((string) $zeile['tage']) ?> <?= $zeile['tage'] === 1 ? 'Tag' : 'Tage' ?> in Wartung</small>
                                </span>
                                <span class="balkenliste__spur">
                                    <span class="balkenliste__balken" style="--anteil: <?= balken_anteil($zeile['anzahl'], $hoechster) / 100 ?>"></span>
                                    <span class="balkenliste__wert"><?= e((string) $zeile['anzahl']) ?> <?= $zeile['anzahl'] === 1 ? 'Wartung' : 'Wartungen' ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </article>
        </section>
    </div>

    <!-- Ausblick ab heute: gebuchte Fahrzeuge je Tag und HU/AU-Termine -->
    <section class="dashboard__abschnitt" aria-labelledby="anstehend">
        <h2 class="abschnitt" id="anstehend">
            Anstehende Buchungen
            <span class="abschnitt__wert">gebuchte Fahrzeuge je Tag</span>
            <span class="abschnitt__zusatz">ab heute, 14 Tage, unabhängig vom Zeitraum</span>
        </h2>

        <article class="schild diagramm">
            <ul class="diagramm__legende">
                <li><span class="diagramm__muster"></span>Bestätigt oder unterwegs</li>
                <li><span class="diagramm__muster diagramm__muster--antrag"></span>Antrag</li>
            </ul>
            <ol class="saeulen saeulen--tage">
                <?php foreach ($jeTag as $zeile): ?>
                    <?php
                    $fest = count($zeile['fest']);
                    $antrag = count(array_diff_key($zeile['antrag'], $zeile['fest']));
                    $datum = $zeile['datum'];
                    ?>
                    <li class="saeule<?= (int) $datum->format('N') >= 6 ? ' saeule--wochenende' : '' ?>"
                        title="<?= e($datum->format('d.m.Y') . ': ' . $fest . ' bestätigt, ' . $antrag . ' Antrag, von ' . $anzahlBestand . ' Fahrzeugen') ?>">
                        <span class="saeule__flaeche">
                            <span class="saeule__wert"><?= e((string) ($fest + $antrag)) ?></span>
                            <?php if ($antrag > 0): ?>
                                <span class="saeule__balken saeule__balken--antrag" style="height: <?= balken_anteil($antrag, $anzahlBestand) * 0.82 ?>%"></span>
                            <?php endif; ?>
                            <span class="saeule__balken<?= $antrag > 0 ? ' saeule__balken--unten' : '' ?>" style="height: <?= balken_anteil($fest, $anzahlBestand) * 0.82 ?>%"></span>
                        </span>
                        <span class="saeule__monat"><?= $datum == $heute ? 'heute' : e($datum->format('d.m.')) ?></span>
                        <span class="saeule__zusatz"><?= e(['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) $datum->format('w')]) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="diagramm__fuss">Höhe: Anteil an den <?= e((string) $anzahlBestand) ?> Fahrzeugen der Auswahl.</p>
        </article>
    </section>

    <section class="dashboard__abschnitt" aria-labelledby="hu-au">
        <h2 class="abschnitt" id="hu-au">
            HU/AU-Termine
            <span class="abschnitt__wert">Fahrzeuge je Monat</span>
            <span class="abschnitt__zusatz">die nächsten zwölf Monate</span>
        </h2>

        <?php $hoechster = max([1, ...array_map(fn (array $zeile): int => count($zeile['fahrzeuge']), $huAu)]); ?>
        <article class="schild diagramm">
            <ol class="saeulen saeulen--monate">
                <?php foreach ($huAu as $schluessel => $zeile): ?>
                    <?php
                    $anzahl = count($zeile['fahrzeuge']);
                    $namen = array_map(fn (CFahrzeug $Fahrzeug): string => $Fahrzeug->GetName(), $zeile['fahrzeuge']);
                    ?>
                    <li class="saeule<?= $schluessel === 'ueberfaellig' ? ' saeule--ueberfaellig' : '' ?>"
                        title="<?= e($zeile['name'] . ': ' . ($namen === [] ? 'keine' : implode(', ', $namen))) ?>">
                        <span class="saeule__flaeche">
                            <span class="saeule__wert"><?= e((string) $anzahl) ?></span>
                            <span class="saeule__balken" style="height: <?= balken_anteil($anzahl, $hoechster) * 0.82 ?>%"></span>
                        </span>
                        <span class="saeule__monat"><?= e($zeile['name']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <!-- Die nächsten Termine mit Namen: überfällige und die nächsten drei Monate -->
            <?php
            $baldFaellig = array_merge(...array_column(array_slice($huAu, 0, 4), 'fahrzeuge'));
            ?>
            <?php if ($baldFaellig !== []): ?>
                <ul class="diagramm__liste">
                    <?php foreach ($baldFaellig as $Fahrzeug): ?>
                        <?php $stand = $Fahrzeug->GetHuAuStand(); ?>
                        <li>
                            <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                            <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?> &middot; <?= e($Fahrzeug->GetHuAu()->format('m/Y')) ?></span>
                            <?php if ($stand !== null): ?>
                                <span class="marke marke--<?= $stand === 'ueberfaellig' ? 'rot' : 'gelb' ?>"><?= $stand === 'ueberfaellig' ? 'Überfällig' : 'Bald fällig' ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
