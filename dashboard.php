<?php
/**
 * Dashboard: Auswertung des Fuhrparks, nur für den Fuhrparkleiter.
 *
 * Oben die Filterleiste wie in der Historie (Zeitraum, Fahrzeug oder
 * Kategorie, Mitarbeiter), darunter die Kennzahlen der gefilterten Fahrten
 * und drei Auswertungen als Tabellen mit Balken: gefahrene km je Fahrzeug,
 * Fahrten und km je Monat, Wartungen je Fahrzeug. Die Balken zeigen den
 * Anteil am größten Wert der Tabelle.
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
// Fahrzeug-ID => [Name, Fahrten, km]; km null, wenn das Fahrzeug keinen
// km-Stand hat. Der Name wie im Filter, mit „ausgemustert“. Meiste km zuerst.

$jeFahrzeug = [];

foreach ($fahrten as $Fahrt) {
    $id = $Fahrt->GetFahrzeugID();
    $jeFahrzeug[$id] ??= ['name' => $auswahlFahrzeuge['id:' . $id], 'fahrten' => 0, 'km' => null];
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
// Anzahl und Tage in Wartung, nur der Teil im Zeitraum. Eine laufende Wartung
// zählt bis heute. Meiste Tage zuerst.

$wartungJeFahrzeug = [];

foreach ($wartungen as $Wartung) {
    $id = $Wartung->GetFahrzeugID();
    $beginn = max($Wartung->GetBegonnenAm(), $von);
    $ende = min($Wartung->GetFreigegebenAm() ?? $heute, $bis);
    $tage = $ende >= $beginn ? $beginn->diff($ende)->days + 1 : 0;

    $wartungJeFahrzeug[$id] ??= ['name' => $auswahlFahrzeuge['id:' . $id], 'anzahl' => 0, 'tage' => 0];
    $wartungJeFahrzeug[$id]['anzahl']++;
    $wartungJeFahrzeug[$id]['tage'] += $tage;
}

usort($wartungJeFahrzeug, fn (array $a, array $b): int => $b['tage'] <=> $a['tage']);

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

    <!-- Auswertung 1: gefahrene km je Fahrzeug -->
    <section class="dashboard__abschnitt" aria-labelledby="km-fahrzeug">
        <h2 class="abschnitt" id="km-fahrzeug">
            Gefahrene km je Fahrzeug
            <span class="abschnitt__zusatz">meiste zuerst</span>
        </h2>

        <?php $hoechster = max([0, ...array_map(fn (array $zeile): int => $zeile['km'] ?? 0, $jeFahrzeug)]); ?>
        <table class="table dashboard-tabelle">
            <thead>
                <tr>
                    <th>Fahrzeug</th>
                    <th class="table__num">Fahrten</th>
                    <th class="table__num">km</th>
                    <th class="dashboard-tabelle__balken">Anteil</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jeFahrzeug as $zeile): ?>
                    <tr>
                        <td><?= e($zeile['name']) ?></td>
                        <td class="table__num"><?= e((string) $zeile['fahrten']) ?></td>
                        <td class="table__num"><?= e(km_text($zeile['km'])) ?></td>
                        <td class="dashboard-tabelle__balken">
                            <span class="balken"><span class="balken__wert" style="width: <?= balken_anteil($zeile['km'], $hoechster) ?>%"></span></span>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($jeFahrzeug === []): ?>
                    <tr><td colspan="4" class="table__empty">Keine Fahrten für diese Auswahl im Zeitraum.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <!-- Auswertung 2: Fahrten und km je Monat -->
    <section class="dashboard__abschnitt" aria-labelledby="je-monat">
        <h2 class="abschnitt" id="je-monat">
            Fahrten und km je Monat
            <span class="abschnitt__zusatz">nach Tag der Rückgabe</span>
        </h2>

        <?php $hoechster = max([0, ...array_column($jeMonat, 'km')]); ?>
        <table class="table dashboard-tabelle">
            <thead>
                <tr>
                    <th>Monat</th>
                    <th class="table__num">Fahrten</th>
                    <th class="table__num">km</th>
                    <th class="dashboard-tabelle__balken">Anteil</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jeMonat as $zeile): ?>
                    <tr>
                        <td><?= e($zeile['name']) ?></td>
                        <td class="table__num"><?= e((string) $zeile['fahrten']) ?></td>
                        <td class="table__num"><?= e(km_text($zeile['km'])) ?></td>
                        <td class="dashboard-tabelle__balken">
                            <span class="balken"><span class="balken__wert" style="width: <?= balken_anteil($zeile['km'], $hoechster) ?>%"></span></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- Auswertung 3: Wartungen je Fahrzeug -->
    <section class="dashboard__abschnitt" aria-labelledby="wartungen">
        <h2 class="abschnitt" id="wartungen">
            Wartungen je Fahrzeug
            <span class="abschnitt__zusatz">Tage in Wartung im Zeitraum, meiste zuerst</span>
        </h2>

        <?php if ($fahrerFilter !== null): ?>
            <p class="zeile">Wartungen haben keinen Fahrer; mit dem Filter nach einem Mitarbeiter entfallen sie.</p>
        <?php else: ?>
            <?php $hoechster = max([0, ...array_column($wartungJeFahrzeug, 'tage')]); ?>
            <table class="table dashboard-tabelle">
                <thead>
                    <tr>
                        <th>Fahrzeug</th>
                        <th class="table__num">Wartungen</th>
                        <th class="table__num">Tage</th>
                        <th class="dashboard-tabelle__balken">Anteil</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wartungJeFahrzeug as $zeile): ?>
                        <tr>
                            <td><?= e($zeile['name']) ?></td>
                            <td class="table__num"><?= e((string) $zeile['anzahl']) ?></td>
                            <td class="table__num"><?= e((string) $zeile['tage']) ?></td>
                            <td class="dashboard-tabelle__balken">
                                <span class="balken"><span class="balken__wert" style="width: <?= balken_anteil($zeile['tage'], $hoechster) ?>%"></span></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($wartungJeFahrzeug === []): ?>
                        <tr><td colspan="4" class="table__empty">Keine Wartungen für diese Auswahl im Zeitraum.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
