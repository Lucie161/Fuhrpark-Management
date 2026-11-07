<?php
/**
 * Historie: Fahrtenbuch aller Fahrzeuge, nur für den Fuhrparkleiter.
 *
 * Aufruf: historie.php, mit Zeitraum historie.php?von=2026-09-01&bis=2026-09-30,
 * die Fahrtenliste gefiltert mit &auswahl=art:1 (Kategorie, ID der Fahrzeugart) oder &auswahl=id:3
 * (ein Fahrzeug) und mit &fahrer=2 (ein Mitarbeiter); beides lässt sich
 * kombinieren. Wartungen stehen als eigene Zeilen zwischen den Fahrten, wenn
 * sie den Zeitraum berühren; &nur_fahrten=1 blendet sie aus, ebenso der
 * Filter nach einem Mitarbeiter (eine Wartung hat keinen Fahrer). Sie zählen
 * nicht zu den Kennzahlen.
 *
 * Es gibt kein eigenes Fahrtenbuch: Jede Buchung im Status „abgeschlossen“ ist
 * ein Eintrag. Er entsteht automatisch bei der Rückgabe. Eine Fahrt zählt zu
 * dem Tag, an dem sie zurückgegeben wurde.
 *
 * Die Summen je Fahrzeug stehen nicht hier, sondern im Fahrtenbuch des
 * Steckbriefs (fahrzeug.php); jedes Fahrzeug in der Liste verlinkt dorthin.
 *
 * Ausgeschiedene Mitarbeiter und ausgemusterte Fahrzeuge bleiben in Liste und
 * Filter, mit einem Zusatz im Namen: Die Fahrten haben stattgefunden.
 *
 * Daten: die Fahrten aus CListeBuchungen::LadenHistorie(), ihre Schäden aus
 * CListeSchaeden::LadenRueckgabenImZeitraum(), die Wartungen aus
 * CListeWartungen::LadenImZeitraum(); für die Filter alle Fahrzeuge und Nutzer,
 * auch ausgemusterte und ausgeschiedene.
 *
 * Die Kennzahlen rechnet die Seite aus derselben Liste wie die Fahrten, damit
 * Liste und Kennzahlen nicht voneinander abweichen können. Die Filter wirken
 * nur auf die Liste, die Kennzahlen zeigen den ganzen Fuhrpark.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$pageTitle = 'Historie';

// Alle Fahrzeuge, auch ausgemusterte: Ihre Fahrten gehören zur Historie.
$Fahrzeuge = new CListeFahrzeuge();
$Fahrzeuge->LadenAlle();

// Mitarbeiter für den Filter „Mitarbeiter“, nach Nachname, auch
// ausgeschiedene.
$ListeNutzer = new CListeNutzer();
$ListeNutzer->LadenAlle();

$mitarbeiter = [];

foreach ($ListeNutzer->GetAlle() as $Nutzer) {
    if ($Nutzer->GetRolleID() === ROLLE_MITARBEITER) {
        $mitarbeiter[$Nutzer->GetID()] = $Nutzer->GetNachname() . ', ' . $Nutzer->GetVorname()
            . ($Nutzer->IstAusgeschieden() ? ' (ausgeschieden)' : '');
    }
}

$Fahrzeugarten = new CListeSchluessel('fahrzeugarten');
$Zwecke = new CListeSchluessel('zwecke');
$Schweregrade = new CListeSchluessel('schweregrade');

$heute = new DateTimeImmutable('today');

// --- Zeitraum ermitteln -----------------------------------------------------
// Ohne Angabe: vom Ersten des Vormonats bis heute. Das Formular schickt die
// Daten per GET, damit sich eine Auswertung als Link weitergeben lässt.

$fehler = [];

$eingabe = [];

foreach (['von', 'bis'] as $feld) {
    // Ein Array (?von[]=…) gilt als ungültiges Datum statt einer PHP-Warnung.
    $wert = $_GET[$feld] ?? '';
    $eingabe[$feld] = is_string($wert) ? trim($wert) : '-';
}

$von = $eingabe['von'] === '' ? $heute->modify('first day of last month') : datum_aus_eingabe($eingabe['von']);
$bis = $eingabe['bis'] === '' ? $heute : datum_aus_eingabe($eingabe['bis']);

if ($von === null) {
    $fehler[] = 'Bitte geben Sie für „Von“ ein gültiges Datum ein.';
}

if ($bis === null) {
    $fehler[] = 'Bitte geben Sie für „Bis“ ein gültiges Datum ein.';
}

if ($von !== null && $bis !== null && $von > $bis) {
    $fehler[] = '„Von“ darf nicht nach „Bis“ liegen.';
}

// Leere Felder zeigen den verwendeten Standardzeitraum an.
if ($eingabe['von'] === '' && $von !== null) {
    $eingabe['von'] = $von->format('Y-m-d');
}

if ($eingabe['bis'] === '' && $bis !== null) {
    $eingabe['bis'] = $bis->format('Y-m-d');
}

// --- Filter der Fahrtenliste ------------------------------------------------
// Fahrzeug: entweder eine Kategorie ('art:1') oder ein Fahrzeug ('id:3'),
// leer für alle. Mitarbeiter: eine Nutzer-ID, leer für alle. Beide wirken nur
// auf die Fahrtenliste, die Kennzahlen zeigen weiter den ganzen Fuhrpark.

$auswahlKategorien = [];

foreach ($Fahrzeugarten->GetAlle() as $Art) {
    $auswahlKategorien['art:' . $Art->GetID()] = $Art->GetPlural();
}

$auswahlFahrzeuge = [];

foreach ($Fahrzeuge->GetAlle() as $Fahrzeug) {
    $auswahlFahrzeuge['id:' . $Fahrzeug->GetID()] = $Fahrzeug->GetName() . ($Fahrzeug->IstAusgemustert() ? ', ausgemustert' : '');
}

$wert = $_GET['auswahl'] ?? '';
$eingabe['auswahl'] = is_string($wert) ? $wert : '-';

$auswahlText = $auswahlKategorien[$eingabe['auswahl']] ?? $auswahlFahrzeuge[$eingabe['auswahl']] ?? null;

if ($eingabe['auswahl'] !== '' && $auswahlText === null) {
    $fehler[] = 'Diese Fahrzeugauswahl gibt es nicht.';
}

$wert = $_GET['fahrer'] ?? '';
$eingabe['fahrer'] = is_string($wert) ? $wert : '-';

$fahrerFilter = $eingabe['fahrer'] === '' ? null : (int) $eingabe['fahrer'];

if ($fahrerFilter !== null && (!isset($mitarbeiter[$fahrerFilter]) || (string) $fahrerFilter !== $eingabe['fahrer'])) {
    $fehler[] = 'Diesen Mitarbeiter gibt es nicht.';
}

$nurFahrten = ($_GET['nur_fahrten'] ?? '') === '1';

// --- Auswerten --------------------------------------------------------------
// Alle Fahrten im Zeitraum mit ihren Schäden laden und daraus die Kennzahlen
// zählen. Bei einem Fehler in der Eingabe bleibt alles leer.

$ListeFahrten = new CListeBuchungen();
$ListeSchaeden = new CListeSchaeden();
$ListeWartungen = new CListeWartungen();

$summe = ['fahrten' => 0, 'km' => 0, 'schaeden' => 0, 'bemerkungen' => 0];

if ($fehler === []) {
    $ListeFahrten->LadenHistorie($von, $bis);
    $ListeSchaeden->LadenRueckgabenImZeitraum($von, $bis);

    foreach ($ListeFahrten->GetAlle() as $Fahrt) {
        $summe['fahrten']++;
        $summe['km'] += gefahrene_km($Fahrt) ?? 0;

        if ($ListeSchaeden->GetBeiRueckgabe($Fahrt->GetID()) !== null) {
            $summe['schaeden']++;
        }

        if ($Fahrt->GetBemerkung() !== '') {
            $summe['bemerkungen']++;
        }
    }
}

// Fahrtenliste: die Fahrten im Zeitraum, eingeschränkt auf die Auswahl.
$fahrtenliste = array_values(array_filter(
    $ListeFahrten->GetAlle(),
    fn (CBuchung $Fahrt): bool => passt_zur_auswahl($Fahrt->GetFahrzeugID(), $eingabe['auswahl'], $Fahrzeuge)
        && ($fahrerFilter === null || $Fahrt->GetFahrerID() === $fahrerFilter),
));

// Wartungen im Zeitraum, eingeschränkt auf die Fahrzeugauswahl. Eine laufende
// Wartung reicht bis heute.
$wartungsliste = [];

if ($fehler === [] && !$nurFahrten && $fahrerFilter === null) {
    $ListeWartungen->LadenImZeitraum($von, $bis);

    $wartungsliste = array_values(array_filter(
        $ListeWartungen->GetAlle(),
        fn (CWartung $Wartung): bool => passt_zur_auswahl($Wartung->GetFahrzeugID(), $eingabe['auswahl'], $Fahrzeuge),
    ));
}

// Fahrten und Wartungen in einer Liste, nach dem Ende sortiert (Rückgabe bzw.
// Freigabe), eine laufende Wartung ganz oben.
$eintraege = [];

foreach ($fahrtenliste as $Fahrt) {
    $eintraege[] = ['art' => 'fahrt', 'datum' => $Fahrt->GetZurueckgegebenAm(), 'daten' => $Fahrt];
}

foreach ($wartungsliste as $Wartung) {
    $eintraege[] = ['art' => 'wartung', 'datum' => $Wartung->GetFreigegebenAm() ?? $heute->modify('+1 day'), 'daten' => $Wartung];
}

usort($eintraege, fn (array $a, array $b): int => $b['datum'] <=> $a['datum']);

// km der Liste; null, wenn keine Fahrt einen km-Stand hat (nur Fahrräder).
$kmWerte = array_filter(array_map('gefahrene_km', $fahrtenliste), fn (?int $km): bool => $km !== null);
$kmListe = $fahrtenliste !== [] && $kmWerte === [] ? null : array_sum($kmWerte);

$kennzahlen = [
    ['wert' => $summe['fahrten'],                         'label' => 'Fahrten'],
    ['wert' => number_format($summe['km'], 0, ',', '.'), 'label' => 'Gefahrene km'],
    ['wert' => $summe['schaeden'],                        'label' => $summe['schaeden'] === 1 ? 'Schaden gemeldet' : 'Schäden gemeldet'],
    ['wert' => $summe['bemerkungen'],                     'label' => 'Bemerkungen zum Zustand'],
];

// Beschreibung der Auswahl für den Satz über der Liste.
$filterText = implode(', ', array_filter([
    $auswahlText ?? 'alle Fahrzeuge',
    $fahrerFilter !== null && isset($mitarbeiter[$fahrerFilter]) ? $ListeNutzer->GetByID($fahrerFilter)->GetName() : null,
]));

/**
 * Datum aus einem Formularfeld (JJJJ-MM-TT), null bei ungültiger Eingabe.
 *
 * Der Rückvergleich fängt Daten ab, die PHP sonst still umrechnet,
 * z. B. 2026-02-31 zum 03.03.
 */
function datum_aus_eingabe(string $wert): ?DateTimeImmutable
{
    $datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

    return $datum !== false && $datum->format('Y-m-d') === $wert ? $datum : null;
}

/**
 * Ob ein Fahrzeug zur Auswahl im Filter passt: '' (alle), 'art:…' oder 'id:…'.
 */
function passt_zur_auswahl(int $fahrzeugId, string $auswahl, CListeFahrzeuge $Fahrzeuge): bool
{
    if ($auswahl === '') {
        return true;
    }

    [$feld, $wert] = explode(':', $auswahl, 2);

    return $feld === 'art'
        ? (string) $Fahrzeuge->GetByID($fahrzeugId)->GetArtID() === $wert
        : (string) $fahrzeugId === $wert;
}

/**
 * Gefahrene km einer Fahrt, null ohne km-Stand.
 */
function gefahrene_km(CBuchung $Fahrt): ?int
{
    return $Fahrt->GetKmEnde() !== null ? $Fahrt->GetKmEnde() - $Fahrt->GetKmStart() : null;
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

<p class="lead">
    Fahrtenbuch aller Fahrzeuge: abgeschlossene Fahrten eines Zeitraums mit km-Ständen, gemeldeten
    Schäden und Bemerkungen. Die Summen eines Fahrzeugs stehen in seinem Steckbrief.
</p>

<p class="note">Eine Fahrt zählt zu dem Tag, an dem sie zurückgegeben wurde.
</p>

<form class="form form--breit" method="get" action="<?= url('historie.php') ?>">
    <h2 class="form__titel">Zeitraum</h2>

    <?php if ($auswahlText !== null): ?>
        <input type="hidden" name="auswahl" value="<?= e($eingabe['auswahl']) ?>">
    <?php endif; ?>
    <?php if ($fahrerFilter !== null): ?>
        <input type="hidden" name="fahrer" value="<?= e($eingabe['fahrer']) ?>">
    <?php endif; ?>
    <?php if ($nurFahrten): ?>
        <input type="hidden" name="nur_fahrten" value="1">
    <?php endif; ?>

    <div class="form__raster">
        <div class="form__row">
            <label class="form__label" for="von">Von</label>
            <input class="form__input" type="date" id="von" name="von" required
                   value="<?= e($eingabe['von']) ?>">
        </div>

        <div class="form__row">
            <label class="form__label" for="bis">Bis</label>
            <input class="form__input" type="date" id="bis" name="bis" required
                   value="<?= e($eingabe['bis']) ?>">
        </div>
    </div>

    <button class="button" type="submit">Auswerten</button>
</form>

<?php if ($fehler !== []): ?>

    <div class="alert section">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>

<?php else: ?>

    <div class="cards">
        <?php foreach ($kennzahlen as $kennzahl): ?>
            <div class="card">
                <p class="card__value"><?= e((string) $kennzahl['wert']) ?></p>
                <p class="card__label"><?= e($kennzahl['label']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="section" id="fahrten">
        <h2>Fahrten</h2>

        <form class="filter" method="get" action="<?= url('historie.php') ?>#fahrten">
            <input type="hidden" name="von" value="<?= e($eingabe['von']) ?>">
            <input type="hidden" name="bis" value="<?= e($eingabe['bis']) ?>">

            <div class="filter__feld">
                <label class="form__label" for="auswahl">Fahrzeug oder Kategorie</label>
                <select class="form__input" id="auswahl" name="auswahl">
                    <option value="">Alle Fahrzeuge</option>
                    <optgroup label="Kategorie">
                        <?php foreach ($auswahlKategorien as $wert => $text): ?>
                            <option value="<?= e($wert) ?>"<?= $wert === $eingabe['auswahl'] ? ' selected' : '' ?>><?= e($text) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Fahrzeug">
                        <?php foreach ($auswahlFahrzeuge as $wert => $text): ?>
                            <option value="<?= e($wert) ?>"<?= $wert === $eingabe['auswahl'] ? ' selected' : '' ?>><?= e($text) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div class="filter__feld filter__feld--schmal">
                <label class="form__label" for="fahrer">Mitarbeiter</label>
                <select class="form__input" id="fahrer" name="fahrer">
                    <option value="">Alle</option>
                    <?php foreach ($mitarbeiter as $id => $name): ?>
                        <option value="<?= e((string) $id) ?>"<?= $id === $fahrerFilter ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="filter__haken">
                <input type="checkbox" name="nur_fahrten" value="1"<?= $nurFahrten ? ' checked' : '' ?>>
                nur Fahrten
            </label>

            <button class="button" type="submit">Filtern</button>
        </form>

        <p class="lead">
            <?= e($von->format('d.m.Y')) ?> bis <?= e($bis->format('d.m.Y')) ?>,
            <?= e($filterText) ?>:
            <?= e((string) count($fahrtenliste)) ?> <?= count($fahrtenliste) === 1 ? 'Fahrt' : 'Fahrten' ?><?php if ($fahrtenliste !== []): ?>,
                <?= e($kmListe === null ? 'ohne km-Stand' : km_text($kmListe) . ' km') ?><?php endif; ?><?php if ($wartungsliste !== []): ?>,
                dazu <?= e((string) count($wartungsliste)) ?> <?= count($wartungsliste) === 1 ? 'Wartung' : 'Wartungen' ?><?php endif; ?>.
            <?php if ($eintraege !== []): ?>Neueste zuerst.<?php endif; ?>
        </p>

        <table class="table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Fahrer</th>
                    <th>Fahrzeug</th>
                    <th>Zweck</th>
                    <th class="table__num">km-Stand Anfang</th>
                    <th class="table__num">km-Stand Ende</th>
                    <th>Zustand</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eintraege as $eintrag): ?>
                    <?php if ($eintrag['art'] === 'wartung'): ?>
                        <?php
                        $Wartung = $eintrag['daten'];
                        $Fahrzeug = $Fahrzeuge->GetByID($Wartung->GetFahrzeugID());
                        ?>
                        <tr class="table__zeile--wartung">
                            <td>
                                <?php if (!$Wartung->IstLaufend()): ?>
                                    <?= e(zeitraum_text($Wartung->GetBegonnenAm(), $Wartung->GetFreigegebenAm())) ?>
                                <?php else: ?>
                                    seit <?= e($Wartung->GetBegonnenAm()->format('d.m.Y')) ?>
                                <?php endif; ?>
                            </td>
                            <td>&ndash;</td>
                            <td>
                                <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>#fahrtenbuch"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                                <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                            </td>
                            <td>Wartung</td>
                            <td class="table__num">&ndash;</td>
                            <td class="table__num">&ndash;</td>
                            <td>
                                <?= e($Wartung->GetGrund()) ?>
                                <?php if ($Wartung->IstLaufend()): ?>
                                    <span class="table__zusatz">läuft, <?= e($Fahrzeug->GetWartungText()) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php continue; ?>
                    <?php endif; ?>

                    <?php
                    $Fahrt = $eintrag['daten'];
                    $Fahrzeug = $Fahrzeuge->GetByID($Fahrt->GetFahrzeugID());
                    $Schaden = $ListeSchaeden->GetBeiRueckgabe($Fahrt->GetID());
                    ?>
                    <tr>
                        <td><?= e(zeitraum_text($Fahrt->GetStart(), $Fahrt->GetZurueckgegebenAm())) ?></td>
                        <td><?= e($Fahrt->GetFahrerName()) ?></td>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>#fahrtenbuch"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                            <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                        </td>
                        <td><?= e($Zwecke->GetBezeichnung($Fahrt->GetZweckID())) ?></td>
                        <td class="table__num"><?= e(km_text($Fahrt->GetKmStart())) ?></td>
                        <td class="table__num"><?= e(km_text($Fahrt->GetKmEnde())) ?></td>
                        <td>
                            <?php if ($Schaden !== null): ?>
                                <span class="badge<?= $Schaden->GetSchwereID() === SCHWERE_WARTUNG ? ' badge--abgelehnt' : '' ?>"><?= e($Schweregrade->GetBezeichnung($Schaden->GetSchwereID())) ?></span>
                                <span class="table__zusatz"><?= e($Schaden->GetBeschreibung()) ?></span>
                            <?php elseif ($Fahrt->GetBemerkung() !== ''): ?>
                                <span class="badge">Bemerkung</span>
                            <?php else: ?>
                                &ndash;
                            <?php endif; ?>

                            <?php if ($Fahrt->GetBemerkung() !== ''): ?>
                                <span class="table__zusatz"><?= e($Fahrt->GetBemerkung()) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($eintraege === []): ?>
                    <tr>
                        <td colspan="7" class="table__empty">Keine abgeschlossenen Fahrten für diese Auswahl im Zeitraum.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
