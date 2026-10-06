<?php
/**
 * Fahrtenbuch auswerten (Anwendungsfall 11) — nur für den Fuhrparkleiter.
 *
 * Aufruf: auswertung.php, mit Zeitraum auswertung.php?von=2026-09-01&bis=2026-09-30,
 * die Fahrtenliste gefiltert mit &auswahl=art:auto (Kategorie) oder &auswahl=id:3
 * (ein Fahrzeug).
 *
 * Es gibt kein eigenes Fahrtenbuch: Jede Buchung im Status „abgeschlossen“ ist
 * ein Eintrag. Er entsteht automatisch bei der Rückgabe (entschieden am
 * 05.10.2026, siehe docs/vorlesung-notizen.md, Frage C). Eine Fahrt zählt zu
 * dem Tag, an dem sie zurückgegeben wurde.
 *
 * Prototyp: Die Fahrten sind feste Beispieldaten. Mit Datenbank (siehe
 * docs/technisches-konzept.md, Regel 11):
 *
 *   SELECT b.*, n.name AS fahrer, b.km_ende - b.km_start AS km
 *     FROM buchungen b JOIN nutzer n ON n.id = b.fahrer_id
 *    WHERE b.status = 'abgeschlossen'
 *      AND DATE(b.zurueckgegeben_am) BETWEEN :von AND :bis
 *    ORDER BY b.zurueckgegeben_am DESC
 *   SELECT * FROM fahrzeuge ORDER BY kennzeichen
 *
 * Die Summen je Fahrzeug rechnet die Seite aus derselben Liste, damit Liste
 * und Summen nicht voneinander abweichen können.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Fahrtenbuch';

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

// Beispiel-Fuhrpark, dieselben Fahrzeuge wie in fahrzeug.php. Fahrräder
// führen keinen km-Stand.
$fahrzeuge = [
    1 => ['kennzeichen' => 'M-HS 101',  'hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'art' => 'auto'],
    2 => ['kennzeichen' => 'M-HS 102',  'hersteller' => 'Škoda',          'modell' => 'Octavia Combi',  'art' => 'auto'],
    3 => ['kennzeichen' => 'M-HS 201',  'hersteller' => 'Ford',           'modell' => 'Transit',        'art' => 'auto'],
    4 => ['kennzeichen' => 'M-HS 202',  'hersteller' => 'Mercedes-Benz',  'modell' => 'Sprinter',       'art' => 'auto'],
    5 => ['kennzeichen' => 'M-HS 301E', 'hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'art' => 'auto'],
    6 => ['kennzeichen' => 'M-HS 302E', 'hersteller' => 'Tesla',          'modell' => 'Model 3',        'art' => 'auto'],
    7 => ['kennzeichen' => 'M-HS 401',  'hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'art' => 'roller'],
    8 => ['kennzeichen' => 'Rad 1',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad'],
    9 => ['kennzeichen' => 'Rad 2',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad'],
];

// Abgeschlossene Fahrten, neueste zuerst. Dieselben Fahrten und Bemerkungen
// wie in fahrzeug.php und fahrzeuge.php. km ist null bei
// Fahrzeugen ohne km-Stand (Fahrrad, siehe rueckgabe.php).
$fahrten = [
    ['start' => '2026-10-01', 'rueckgabe' => '2026-10-01', 'fahrzeug_id' => 5, 'fahrer' => 'Lucie Schneider', 'zweck' => 'kundentermin',      'km' => 46,   'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-30', 'rueckgabe' => '2026-09-30', 'fahrzeug_id' => 8, 'fahrer' => 'Lucie Schneider', 'zweck' => 'aufmass',           'km' => null, 'schaden' => false, 'bemerkung' => 'Akku nach Rückkehr wieder angeschlossen.'],
    ['start' => '2026-09-29', 'rueckgabe' => '2026-09-29', 'fahrzeug_id' => 1, 'fahrer' => 'Kenneth Sander',  'zweck' => 'kundentermin',      'km' => 84,   'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-25', 'rueckgabe' => '2026-09-27', 'fahrzeug_id' => 4, 'fahrer' => 'Larissa Wagner',  'zweck' => 'montage',           'km' => 143,  'schaden' => true,  'bemerkung' => 'Delle an der Schiebetür rechts, Tür schließt schwer.'],
    ['start' => '2026-09-26', 'rueckgabe' => '2026-09-26', 'fahrzeug_id' => 3, 'fahrer' => 'Ella Luppold',    'zweck' => 'montage',           'km' => 96,   'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-24', 'rueckgabe' => '2026-09-24', 'fahrzeug_id' => 1, 'fahrer' => 'Larissa Wagner',  'zweck' => 'aufmass',           'km' => 132,  'schaden' => false, 'bemerkung' => 'Klappergeräusch hinten rechts bei Tempo über 100.'],
    ['start' => '2026-09-22', 'rueckgabe' => '2026-09-22', 'fahrzeug_id' => 3, 'fahrer' => 'Kenneth Sander',  'zweck' => 'materialtransport', 'km' => 58,   'schaden' => false, 'bemerkung' => 'Ladefläche verschmutzt, Spanngurt fehlt.'],
    ['start' => '2026-09-18', 'rueckgabe' => '2026-09-18', 'fahrzeug_id' => 1, 'fahrer' => 'Finn Clausen',    'zweck' => 'lieferant',         'km' => 210,  'schaden' => false, 'bemerkung' => 'Innenraum könnte mal gereinigt werden.'],
    ['start' => '2026-09-15', 'rueckgabe' => '2026-09-15', 'fahrzeug_id' => 6, 'fahrer' => 'Ella Luppold',    'zweck' => 'schulung',          'km' => 188,  'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-12', 'rueckgabe' => '2026-09-12', 'fahrzeug_id' => 9, 'fahrer' => 'Larissa Wagner',  'zweck' => 'aufmass',           'km' => null, 'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-10', 'rueckgabe' => '2026-09-10', 'fahrzeug_id' => 7, 'fahrer' => 'Finn Clausen',    'zweck' => 'kundentermin',      'km' => 22,   'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-09-03', 'rueckgabe' => '2026-09-04', 'fahrzeug_id' => 2, 'fahrer' => 'Kenneth Sander',  'zweck' => 'service',           'km' => 265,  'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-08-27', 'rueckgabe' => '2026-08-27', 'fahrzeug_id' => 1, 'fahrer' => 'Ella Luppold',    'zweck' => 'kundentermin',      'km' => 118,  'schaden' => false, 'bemerkung' => ''],
    ['start' => '2026-08-20', 'rueckgabe' => '2026-08-21', 'fahrzeug_id' => 3, 'fahrer' => 'Finn Clausen',    'zweck' => 'montage',           'km' => 174,  'schaden' => false, 'bemerkung' => 'Rückfahrkamera zeigt zeitweise kein Bild.'],
    ['start' => '2026-08-14', 'rueckgabe' => '2026-08-14', 'fahrzeug_id' => 2, 'fahrer' => 'Lucie Schneider', 'zweck' => 'lieferant',         'km' => 97,   'schaden' => false, 'bemerkung' => ''],
];

// Fahrzeugart => Beschriftung als Kategorie im Filter der Fahrtenliste.
$artText = [
    'auto'    => 'Autos',
    'roller'  => 'Roller',
    'fahrrad' => 'Fahrräder',
];

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
// Eine Auswahl: entweder eine Kategorie ('art:auto') oder ein Fahrzeug
// ('id:3'), leer für alle. Wirkt nur auf die Fahrtenliste, Kennzahlen und
// Summen je Fahrzeug zeigen weiter den ganzen Fuhrpark.

$auswahlKategorien = [];

foreach ($artText as $art => $text) {
    $auswahlKategorien['art:' . $art] = $text;
}

$auswahlFahrzeuge = [];

foreach ($fahrzeuge as $id => $fahrzeug) {
    $auswahlFahrzeuge['id:' . $id] = $fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']
        . ' (' . $fahrzeug['kennzeichen'] . ')';
}

$wert = $_GET['auswahl'] ?? '';
$eingabe['auswahl'] = is_string($wert) ? $wert : '-';

$auswahlText = $auswahlKategorien[$eingabe['auswahl']] ?? $auswahlFahrzeuge[$eingabe['auswahl']] ?? null;

if ($eingabe['auswahl'] !== '' && $auswahlText === null) {
    $fehler[] = 'Diese Fahrzeugauswahl gibt es nicht.';
}

// --- Auswerten --------------------------------------------------------------

$imZeitraum = [];

// Je Fahrzeug, auch ohne Fahrten im Zeitraum. km ist null bei Fahrzeugen
// ohne km-Stand.
$jeFahrzeug = [];

foreach ($fahrzeuge as $id => $fahrzeug) {
    $km = $fahrzeug['art'] === 'fahrrad' ? null : 0;
    $jeFahrzeug[$id] = ['fahrten' => 0, 'km' => $km, 'schaeden' => 0, 'bemerkungen' => 0];
}

$summe = ['fahrten' => 0, 'km' => 0, 'schaeden' => 0, 'bemerkungen' => 0];

if ($fehler === []) {
    foreach ($fahrten as $fahrt) {
        $fahrt['start']     = new DateTimeImmutable($fahrt['start']);
        $fahrt['rueckgabe'] = new DateTimeImmutable($fahrt['rueckgabe']);

        if ($fahrt['rueckgabe'] < $von || $fahrt['rueckgabe'] > $bis) {
            continue;
        }

        $imZeitraum[] = $fahrt;

        // Eine Meldung ist entweder ein Schaden oder eine Bemerkung, wie in
        // fahrzeuge.php.
        $art = match (true) {
            $fahrt['schaden']          => 'schaeden',
            $fahrt['bemerkung'] !== '' => 'bemerkungen',
            default                    => null,
        };

        $zeile = &$jeFahrzeug[$fahrt['fahrzeug_id']];
        $zeile['fahrten']++;
        $summe['fahrten']++;

        if ($fahrt['km'] !== null && $zeile['km'] !== null) {
            $zeile['km'] += $fahrt['km'];
            $summe['km'] += $fahrt['km'];
        }

        if ($art !== null) {
            $zeile[$art]++;
            $summe[$art]++;
        }

        unset($zeile);
    }
}

// Fahrtenliste: die Fahrten im Zeitraum, eingeschränkt auf die Auswahl.
$fahrtenliste = array_values(array_filter(
    $imZeitraum,
    fn (array $fahrt): bool => passt_zur_auswahl($fahrt, $eingabe['auswahl'], $fahrzeuge),
));

// km der Liste; null, wenn keine Fahrt einen km-Stand hat (nur Fahrräder).
$kmWerte = array_filter(array_column($fahrtenliste, 'km'), fn (?int $km): bool => $km !== null);
$kmListe = $fahrtenliste !== [] && $kmWerte === [] ? null : array_sum($kmWerte);

// Längster Balken im Diagramm „Gefahrene km“; die übrigen relativ dazu.
$maxKm = max(array_map(fn (array $zeile): int => $zeile['km'] ?? 0, $jeFahrzeug));

$kennzahlen = [
    ['wert' => $summe['fahrten'],                         'label' => 'Fahrten'],
    ['wert' => number_format($summe['km'], 0, ',', '.'), 'label' => 'Gefahrene km'],
    ['wert' => $summe['schaeden'],                        'label' => $summe['schaeden'] === 1 ? 'Schaden gemeldet' : 'Schäden gemeldet'],
    ['wert' => $summe['bemerkungen'],                     'label' => 'Bemerkungen zum Zustand'],
];

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
 * Zeitraum einer Fahrt als Text, eintägig ohne „bis“.
 */
function zeitraum(array $fahrt): string
{
    $von = $fahrt['start']->format('d.m.Y');
    $bis = $fahrt['rueckgabe']->format('d.m.Y');

    return $von === $bis ? $von : $von . ' bis ' . $bis;
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
    Abgeschlossene Fahrten eines Zeitraums, gefahrene Kilometer je Fahrzeug und gemeldete
    Schäden und Bemerkungen.
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten. Eine Fahrt zählt zu dem Tag, an dem sie zurückgegeben wurde.
</p>

<form class="form form--breit" method="get" action="<?= url('auswertung.php') ?>">
    <h2 class="form__titel">Zeitraum</h2>

    <?php if ($auswahlText !== null): ?>
        <input type="hidden" name="auswahl" value="<?= e($eingabe['auswahl']) ?>">
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

    <section class="section">
        <h2>Je Fahrzeug</h2>

        <table class="table">
            <thead>
                <tr>
                    <th>Fahrzeug</th>
                    <th class="table__num">Fahrten</th>
                    <th class="table__balkenspalte">Gefahrene km</th>
                    <th class="table__num">Schäden</th>
                    <th class="table__num">Bemerkungen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jeFahrzeug as $id => $zeile): ?>
                    <?php $fahrzeug = $fahrzeuge[$id]; ?>
                    <tr>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $id) ?>"><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></a>
                            <span class="table__zusatz"><?= e($fahrzeug['kennzeichen']) ?></span>
                        </td>
                        <td class="table__num"><?= e((string) $zeile['fahrten']) ?></td>
                        <td>
                            <?php if ($zeile['km'] === null): ?>
                                <span class="kmbalken__ohne">ohne km-Stand</span>
                            <?php else: ?>
                                <?php $anteil = $maxKm > 0 ? $zeile['km'] / $maxKm : 0; ?>
                                <div class="kmbalken">
                                    <?php if ($zeile['km'] > 0): ?>
                                        <span class="kmbalken__balken" aria-hidden="true"
                                              style="--anteil: <?= e(number_format($anteil, 3, '.', '')) ?>"></span>
                                    <?php endif; ?>
                                    <span class="kmbalken__wert"><?= e(km_text($zeile['km'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="table__num">
                            <?php if ($zeile['schaeden'] > 0): ?>
                                <span class="badge badge--abgelehnt"><?= e((string) $zeile['schaeden']) ?></span>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </td>
                        <td class="table__num">
                            <?php if ($zeile['bemerkungen'] > 0): ?>
                                <span class="badge"><?= e((string) $zeile['bemerkungen']) ?></span>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="section" id="fahrten">
        <h2>Fahrten</h2>

        <form class="filter" method="get" action="<?= url('auswertung.php') ?>#fahrten">
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

            <button class="button" type="submit">Filtern</button>
        </form>

        <p class="lead">
            <?= e($von->format('d.m.Y')) ?> bis <?= e($bis->format('d.m.Y')) ?>,
            <?= e($auswahlText ?? 'alle Fahrzeuge') ?>:
            <?= e((string) count($fahrtenliste)) ?> <?= count($fahrtenliste) === 1 ? 'Fahrt' : 'Fahrten' ?><?php if ($fahrtenliste !== []): ?>,
                <?= e($kmListe === null ? 'ohne km-Stand' : km_text($kmListe) . ' km') ?>. Neueste zuerst.<?php else: ?>.<?php endif; ?>
        </p>

        <table class="table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Fahrer</th>
                    <th>Fahrzeug</th>
                    <th>Zweck</th>
                    <th class="table__num">km</th>
                    <th>Zustand</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fahrtenliste as $fahrt): ?>
                    <?php $fahrzeug = $fahrzeuge[$fahrt['fahrzeug_id']]; ?>
                    <tr>
                        <td><?= e(zeitraum($fahrt)) ?></td>
                        <td><?= e($fahrt['fahrer']) ?></td>
                        <td>
                            <?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?>
                            <span class="table__zusatz"><?= e($fahrzeug['kennzeichen']) ?></span>
                        </td>
                        <td><?= e($zweckText[$fahrt['zweck']] ?? $fahrt['zweck']) ?></td>
                        <td class="table__num"><?= e(km_text($fahrt['km'])) ?></td>
                        <td>
                            <?php if ($fahrt['schaden']): ?>
                                <span class="badge badge--abgelehnt">Schaden</span>
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

                <?php if ($fahrtenliste === []): ?>
                    <tr>
                        <td colspan="6" class="table__empty">Keine abgeschlossenen Fahrten für diese Auswahl im Zeitraum.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
