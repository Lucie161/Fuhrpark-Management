<?php
/**
 * Historie: Fahrtenbuch aller Fahrzeuge (Anwendungsfall 11), nur für den
 * Fuhrparkleiter. Bis 06.10.2026 auswertung.php, bis 10.10.2026 verlauf.php.
 *
 * Aufruf: historie.php, mit Zeitraum historie.php?von=2026-09-01&bis=2026-09-30,
 * die Fahrtenliste gefiltert mit &auswahl=art:auto (Kategorie) oder &auswahl=id:3
 * (ein Fahrzeug) und mit &fahrer=2 (ein Mitarbeiter); beides lässt sich
 * kombinieren. Wartungen stehen als eigene Zeilen zwischen den Fahrten, wenn
 * sie den Zeitraum berühren; &nur_fahrten=1 blendet sie aus, ebenso der
 * Filter nach einem Mitarbeiter (eine Wartung hat keinen Fahrer). Sie zählen
 * nicht zu den Kennzahlen.
 *
 * Es gibt kein eigenes Fahrtenbuch: Jede Buchung im Status „abgeschlossen“ ist
 * ein Eintrag. Er entsteht automatisch bei der Rückgabe (entschieden am
 * 05.10.2026, siehe docs/vorlesung-notizen.md, Frage C). Eine Fahrt zählt zu
 * dem Tag, an dem sie zurückgegeben wurde.
 *
 * Die Summen je Fahrzeug stehen nicht hier, sondern im Fahrtenbuch des
 * Steckbriefs (fahrzeug.php); jedes Fahrzeug in der Liste verlinkt dorthin.
 *
 * Prototyp: Die Daten kommen aus includes/beispieldaten.php. Mit Datenbank
 * (siehe docs/technisches-konzept.md, Regel 11):
 *
 *   SELECT b.*, n.vorname, n.nachname, b.km_ende - b.km_start AS km,
 *          s.schwere, s.beschreibung
 *     FROM buchungen b
 *     JOIN nutzer n ON n.id = b.fahrer_id
 *     LEFT JOIN schaeden s ON s.buchung_id = b.id AND s.anlass = 'rueckgabe'
 *    WHERE b.status = 'abgeschlossen'
 *      AND DATE(b.zurueckgegeben_am) BETWEEN :von AND :bis
 *    ORDER BY b.zurueckgegeben_am DESC
 *   SELECT * FROM wartungen
 *    WHERE begonnen_am <= :bis AND (freigegeben_am IS NULL OR freigegeben_am >= :von)
 *   SELECT * FROM fahrzeuge ORDER BY kennzeichen
 *
 * Die Kennzahlen rechnet die Seite aus derselben Liste, damit Liste und
 * Kennzahlen nicht voneinander abweichen können.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('fuhrparkleiter');

$pageTitle = 'Historie';

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

// Fahrzeugart => Beschriftung als Kategorie im Filter der Fahrtenliste.
$artText = [
    'auto'        => 'Autos',
    'transporter' => 'Transporter',
    'roller'      => 'Roller',
    'fahrrad'     => 'Fahrräder',
];

// Schwere eines Schadens => Beschriftung (schaeden.schwere).
$schwereText = [
    'klein'   => 'Kleinschaden',
    'wartung' => 'Schaden',
];

$fahrzeuge = beispiel_fahrzeuge();

// Mitarbeiter für den Filter „Mitarbeiter“, nach Nachname.
$mitarbeiter = [];

foreach (beispiel_nutzer() as $id => $person) {
    if ($person['rolle'] === 'mitarbeiter') {
        $mitarbeiter[$id] = $person['nachname'] . ', ' . $person['vorname'];
    }
}

asort($mitarbeiter);

// Schaden aus der Rückgabe je Buchung.
$schadenJeBuchung = [];

foreach (beispiel_schaeden() as $schaden) {
    if ($schaden['anlass'] === 'rueckgabe') {
        $schadenJeBuchung[$schaden['buchung_id']] = $schaden;
    }
}

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
// Fahrzeug: entweder eine Kategorie ('art:auto') oder ein Fahrzeug ('id:3'),
// leer für alle. Mitarbeiter: eine Nutzer-ID, leer für alle. Beide wirken nur
// auf die Fahrtenliste, die Kennzahlen zeigen weiter den ganzen Fuhrpark.

$auswahlKategorien = [];

foreach ($artText as $art => $text) {
    $auswahlKategorien['art:' . $art] = $text;
}

$auswahlFahrzeuge = [];

foreach ($fahrzeuge as $id => $fahrzeug) {
    $auswahlFahrzeuge['id:' . $id] = fahrzeug_name($id);
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

$imZeitraum = [];

$summe = ['fahrten' => 0, 'km' => 0, 'schaeden' => 0, 'bemerkungen' => 0];

if ($fehler === []) {
    foreach (beispiel_buchungen() as $fahrt) {
        if ($fahrt['status'] !== 'abgeschlossen'
            || $fahrt['zurueckgegeben_am'] < $von || $fahrt['zurueckgegeben_am'] > $bis) {
            continue;
        }

        // Gefahrene km für die Summen; null ohne km-Stand.
        $fahrt['km'] = $fahrt['km_ende'] !== null ? $fahrt['km_ende'] - $fahrt['km_start'] : null;
        $fahrt['schaden'] = $schadenJeBuchung[$fahrt['id']] ?? null;

        $imZeitraum[] = $fahrt;

        $summe['fahrten']++;
        $summe['km'] += $fahrt['km'] ?? 0;

        if ($fahrt['schaden'] !== null) {
            $summe['schaeden']++;
        }

        if ($fahrt['bemerkung'] !== '') {
            $summe['bemerkungen']++;
        }
    }

    usort($imZeitraum, fn (array $a, array $b): int => $b['zurueckgegeben_am'] <=> $a['zurueckgegeben_am']);
}

// Fahrtenliste: die Fahrten im Zeitraum, eingeschränkt auf die Auswahl.
$fahrtenliste = array_values(array_filter(
    $imZeitraum,
    fn (array $fahrt): bool => passt_zur_auswahl($fahrt, $eingabe['auswahl'], $fahrzeuge)
        && ($fahrerFilter === null || $fahrt['fahrer_id'] === $fahrerFilter),
));

// Wartungen im Zeitraum, eingeschränkt auf die Fahrzeugauswahl. Eine laufende
// Wartung reicht bis heute.
$wartungsliste = [];

if ($fehler === [] && !$nurFahrten && $fahrerFilter === null) {
    $wartungsliste = array_values(array_filter(
        beispiel_wartungen(),
        fn (array $w): bool => $w['begonnen_am'] <= $bis
            && ($w['freigegeben_am'] ?? $heute) >= $von
            && passt_zur_auswahl($w, $eingabe['auswahl'], $fahrzeuge),
    ));
}

// Fahrten und Wartungen in einer Liste, nach dem Ende sortiert (Rückgabe bzw.
// Freigabe), eine laufende Wartung ganz oben.
$eintraege = [];

foreach ($fahrtenliste as $fahrt) {
    $eintraege[] = ['art' => 'fahrt', 'datum' => $fahrt['zurueckgegeben_am'], 'daten' => $fahrt];
}

foreach ($wartungsliste as $wartung) {
    $eintraege[] = ['art' => 'wartung', 'datum' => $wartung['freigegeben_am'] ?? $heute->modify('+1 day'), 'daten' => $wartung];
}

usort($eintraege, fn (array $a, array $b): int => $b['datum'] <=> $a['datum']);

// km der Liste; null, wenn keine Fahrt einen km-Stand hat (nur Fahrräder).
$kmWerte = array_filter(array_column($fahrtenliste, 'km'), fn (?int $km): bool => $km !== null);
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
    $fahrerFilter !== null && isset($mitarbeiter[$fahrerFilter]) ? nutzer_name($fahrerFilter) : null,
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
 * Ob eine Fahrt zur Auswahl im Filter passt: '' (alle), 'art:…' oder 'id:…'.
 */
function passt_zur_auswahl(array $fahrt, string $auswahl, array $fahrzeuge): bool
{
    if ($auswahl === '') {
        return true;
    }

    [$feld, $wert] = explode(':', $auswahl, 2);

    return $feld === 'art'
        ? $fahrzeuge[$fahrt['fahrzeug_id']]['art'] === $wert
        : (string) $fahrt['fahrzeug_id'] === $wert;
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
                        $wartung = $eintrag['daten'];
                        $fahrzeug = $fahrzeuge[$wartung['fahrzeug_id']];
                        ?>
                        <tr class="table__zeile--wartung">
                            <td>
                                <?php if ($wartung['freigegeben_am'] !== null): ?>
                                    <?= e(zeitraum_text($wartung['begonnen_am'], $wartung['freigegeben_am'])) ?>
                                <?php else: ?>
                                    seit <?= e($wartung['begonnen_am']->format('d.m.Y')) ?>
                                <?php endif; ?>
                            </td>
                            <td>&ndash;</td>
                            <td>
                                <a href="<?= url('fahrzeug.php?id=' . $wartung['fahrzeug_id']) ?>#fahrtenbuch"><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></a>
                                <span class="table__zusatz"><?= e($fahrzeug['kennzeichen']) ?></span>
                            </td>
                            <td>Wartung</td>
                            <td class="table__num">&ndash;</td>
                            <td class="table__num">&ndash;</td>
                            <td>
                                <?= e($wartung['grund']) ?>
                                <?php if ($wartung['freigegeben_am'] === null): ?>
                                    <span class="table__zusatz">läuft, <?= e(wartung_text($fahrzeug)) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php continue; ?>
                    <?php endif; ?>

                    <?php
                    $fahrt = $eintrag['daten'];
                    $fahrzeug = $fahrzeuge[$fahrt['fahrzeug_id']];
                    ?>
                    <tr>
                        <td><?= e(zeitraum_text($fahrt['start'], $fahrt['zurueckgegeben_am'])) ?></td>
                        <td><?= e(nutzer_name($fahrt['fahrer_id'])) ?></td>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $fahrt['fahrzeug_id']) ?>#fahrtenbuch"><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></a>
                            <span class="table__zusatz"><?= e($fahrzeug['kennzeichen']) ?></span>
                        </td>
                        <td><?= e($zweckText[$fahrt['zweck']] ?? $fahrt['zweck']) ?></td>
                        <td class="table__num"><?= e(km_text($fahrt['km_start'])) ?></td>
                        <td class="table__num"><?= e(km_text($fahrt['km_ende'])) ?></td>
                        <td>
                            <?php if ($fahrt['schaden'] !== null): ?>
                                <span class="badge<?= $fahrt['schaden']['schwere'] === 'wartung' ? ' badge--abgelehnt' : '' ?>"><?= e($schwereText[$fahrt['schaden']['schwere']]) ?></span>
                                <span class="table__zusatz"><?= e($fahrt['schaden']['beschreibung']) ?></span>
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
