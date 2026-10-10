<?php
/**
 * Fahrzeug-Steckbrief (Anwendungsfälle 2 und 11, Anforderung F-05), für
 * beide Rollen.
 *
 * Aufruf: fahrzeug.php?id=1
 *
 * Stammdaten, noch nicht behobene Schäden, Belegung der nächsten 14 Tage und
 * darunter das Fahrtenbuch des Fahrzeugs mit Summe der km und Anzahl der
 * Fahrten. Den Fahrer sieht nur der Fuhrparkleiter, im Fahrtenbuch wie in der
 * Belegung; für Mitarbeiter wird der Name gar nicht ausgegeben (Datenschutz,
 * entschieden am 06.10.2026). Fahrzeuge ohne km-Stand (Fahrrad) zeigen im
 * Fahrtenbuch keine km-Spalten.
 *
 * Prototyp: Die Daten kommen aus includes/beispieldaten.php. Mit Datenbank:
 *
 *   SELECT * FROM fahrzeuge WHERE id = :id
 *   SELECT ... FROM buchungen
 *    WHERE fahrzeug_id = :id AND status IN ('offen', 'genehmigt', 'unterwegs')
 *      AND start <= :bis AND (ende >= :von OR status = 'unterwegs')
 *   SELECT b.*, n.vorname, n.nachname
 *     FROM buchungen b JOIN nutzer n ON n.id = b.fahrer_id
 *    WHERE b.fahrzeug_id = :id AND b.status = 'abgeschlossen'
 *    ORDER BY b.zurueckgegeben_am DESC
 *   SELECT s.* FROM schaeden s JOIN buchungen b ON b.id = s.buchung_id
 *    WHERE b.fahrzeug_id = :id
 *   SELECT * FROM wartungen WHERE fahrzeug_id = :id
 *
 * Für Mitarbeiter den Namen gar nicht erst abfragen (ohne JOIN).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$fuhrparkleiter = ist_fuhrparkleiter();

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

// Angezeigter Fahrzeugstatus => Beschriftung (wie in fahrzeuge.php).
$statusText = [
    'verfuegbar' => 'verfügbar',
    'unterwegs'  => 'unterwegs',
    'wartung'    => 'in Wartung',
];

// Kürzel aus der Datenbank => Beschriftung.
$artText = [
    'auto'        => 'Auto',
    'transporter' => 'Transporter',
    'roller'      => 'Roller',
    'fahrrad'     => 'Fahrrad',
];

$antriebText = [
    'benzin'  => 'Benzin',
    'diesel'  => 'Diesel',
    'elektro' => 'Elektro',
];

// Schwere eines Schadens => Beschriftung (schaeden.schwere).
$schwereText = [
    'klein'   => 'Kleinschaden',
    'wartung' => 'Schaden',
];

// Typabhängige Merkmale (F-01): Feld => Beschriftung, in Anzeigereihenfolge.
// Ein Fahrzeug führt nur die Felder, die für seine Art sinnvoll sind, z. B.
// keine HU/AU und keinen km-Stand beim Fahrrad.
$merkmalText = [
    'kennzeichen'   => 'Kennzeichen',
    'art'           => 'Fahrzeugart',
    'typ'           => 'Typ',
    'baujahr'       => 'Baujahr',
    'sitzplaetze'   => 'Sitzplätze',
    'antrieb'       => 'Antrieb',
    'kmstand'       => 'km-Stand',
    'fuehrerschein' => 'Führerschein',
    'hu_au'         => 'Nächste HU/AU',
];

// --- Fahrzeug ermitteln -----------------------------------------------------

$id = (int) ($_GET['id'] ?? 0);
$fahrzeug = beispiel_fahrzeuge()[$id] ?? null;

if ($fahrzeug === null) {
    http_response_code(404);
}

$pageTitle = $fahrzeug !== null
    ? $fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']
    : 'Fahrzeug nicht gefunden';

$heute = new DateTimeImmutable('today');

// Buchungen dieses Fahrzeugs. Für Mitarbeiter fällt der Fahrer schon hier
// weg, damit ihn keine Stelle der Seite ausgeben kann.
$buchungen = array_filter(beispiel_buchungen(), fn (array $b): bool => $b['fahrzeug_id'] === $id);

if (!$fuhrparkleiter) {
    foreach ($buchungen as &$buchung) {
        unset($buchung['fahrer_id'], $buchung['antragsteller_id']);
    }
    unset($buchung);
}

// Angezeigter Status: Wartung vor unterwegs vor verfügbar.
$stand = $fahrzeug['status'] ?? 'verfuegbar';

foreach ($buchungen as $buchung) {
    if ($stand === 'verfuegbar' && $buchung['status'] === 'unterwegs') {
        $stand = 'unterwegs';
    }
}

// --- Belegung der nächsten 14 Tage ------------------------------------------
// Je Tag: 'frei', 'vergeben' oder 'wartung'. In Wartung sind die Tage bis zum
// voraussichtlichen Ende; ist das Ende offen, alle Tage (wie im Kalender).
// Ein Tag ist vergeben, wenn eine beantragte, genehmigte oder laufende
// Buchung ihn einschließt; eine überfällige Rückgabe belegt heute. Fahrer und
// Zweck stehen nur für den Fuhrparkleiter im Hinweis (title-Attribut).

$belegung = [];

if ($fahrzeug !== null) {
    $wartungEndeOffen = wartung_ende_offen($fahrzeug);

    for ($tag = 0; $tag < 14; $tag++) {
        $datum = $heute->modify("+$tag day");
        $inWartung = $fahrzeug['status'] === 'wartung'
            && ($wartungEndeOffen || $datum <= $fahrzeug['wartung_bis']);
        $eintrag = [
            'datum'   => $datum,
            'zustand' => $inWartung ? 'wartung' : 'frei',
            'hinweis' => $inWartung ? 'in Wartung' : 'frei',
        ];

        if ($eintrag['zustand'] === 'frei') {
            foreach ($buchungen as $buchung) {
                $belegt = in_array($buchung['status'], ['offen', 'genehmigt', 'unterwegs'], true)
                    && $buchung['start'] <= $datum
                    && ($datum <= $buchung['ende'] || ($buchung['status'] === 'unterwegs' && $tag === 0));

                if ($belegt) {
                    $eintrag['zustand'] = 'vergeben';
                    $eintrag['hinweis'] = $fuhrparkleiter
                        ? nutzer_name($buchung['fahrer_id']) . ': ' . ($zweckText[$buchung['zweck']] ?? $buchung['zweck'])
                        : 'vergeben';
                    break;
                }
            }
        }

        $belegung[] = $eintrag;
    }
}

$wochentage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

$zustandText = [
    'frei'     => 'frei',
    'vergeben' => 'vergeben',
    'wartung'  => 'in Wartung',
];

// --- Schäden ----------------------------------------------------------------
// Noch nicht behobene Schäden, für beide Rollen ohne Namen. Schäden aus der
// Rückgabe stehen außerdem im Fahrtenbuch an der jeweiligen Fahrt.

$offeneSchaeden = $fahrzeug !== null ? offene_schaeden($id) : [];

$schadenJeBuchung = [];

foreach (beispiel_schaeden() as $schaden) {
    if ($schaden['anlass'] === 'rueckgabe') {
        $schadenJeBuchung[$schaden['buchung_id']] = $schaden;
    }
}

// --- Fahrtenbuch des Fahrzeugs ----------------------------------------------
// Ersetzt die Summen „Je Fahrzeug“ der früheren Auswertung.

$fahrtenbuch = array_filter($buchungen, fn (array $b): bool => $b['status'] === 'abgeschlossen');
usort($fahrtenbuch, fn (array $a, array $b): int => $b['zurueckgegeben_am'] <=> $a['zurueckgegeben_am']);

// Ohne km-Stand (Fahrrad) entfallen die km-Spalten und die Summe.
$mitKm = $fahrzeug !== null && $fahrzeug['kmstand'] !== null;

$summeKm = 0;
foreach ($fahrtenbuch as $fahrt) {
    $summeKm += $fahrt['km_ende'] !== null ? $fahrt['km_ende'] - $fahrt['km_start'] : 0;
}

// Wartungen stehen als eigene Zeilen zwischen den Fahrten. Sie zählen nicht
// zu den Fahrten und km. Sortiert wird nach dem Ende (Rückgabe bzw. Freigabe),
// eine laufende Wartung steht ganz oben.
$wartungen = array_filter(beispiel_wartungen(), fn (array $w): bool => $w['fahrzeug_id'] === $id);

$eintraege = [];

foreach ($fahrtenbuch as $fahrt) {
    $eintraege[] = ['art' => 'fahrt', 'datum' => $fahrt['zurueckgegeben_am'], 'daten' => $fahrt];
}

foreach ($wartungen as $wartung) {
    $eintraege[] = ['art' => 'wartung', 'datum' => $wartung['freigegeben_am'] ?? $heute->modify('+1 day'), 'daten' => $wartung];
}

usort($eintraege, fn (array $a, array $b): int => $b['datum'] <=> $a['datum']);

// Spalten des Fahrtenbuchs: Datum, Zweck, Zustand; dazu Fahrer (nur
// Fuhrparkleiter) und zwei km-Spalten.
$anzahlSpalten = 3 + ($fuhrparkleiter ? 1 : 0) + ($mitKm ? 2 : 0);

// Bild aus assets/img/, sonst Platzhalter (siehe docs/technisches-konzept.md).
$bildDatei = $fahrzeug['bild'] ?? null;
$hatBild = $bildDatei !== null && is_file(__DIR__ . '/assets/img/' . $bildDatei);

// Zurück zur Fahrzeugliste der Rolle: fahrzeuge.php ist für Mitarbeiter
// gesperrt, sie wählen Fahrzeuge in buchen.php.
$zurueckZurListe = $fuhrparkleiter
    ? ['datei' => 'fahrzeuge.php', 'text' => 'Alle Fahrzeuge']
    : ['datei' => 'buchen.php',    'text' => 'Zur Fahrzeugauswahl'];

/**
 * Kilometer mit Tausenderpunkt, Strich ohne km-Stand.
 */
function km_text(?int $km): string
{
    return $km === null ? '–' : number_format($km, 0, ',', '.');
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fahrzeug === null): ?>

    <p class="alert">Zu dieser Angabe gibt es kein Fahrzeug.</p>
    <p><a href="<?= url($zurueckZurListe['datei']) ?>"><?= e($zurueckZurListe['text']) ?></a></p>

<?php else: ?>

    <p><a href="<?= url($zurueckZurListe['datei']) ?>">&larr; <?= e($zurueckZurListe['text']) ?></a></p>

    <section class="steckbrief">
        <?php if ($hatBild): ?>
            <img class="steckbrief__bild" src="<?= url('assets/img/' . $bildDatei) ?>"
                 alt="<?= e($pageTitle) ?>">
        <?php else: ?>
            <div class="steckbrief__bild steckbrief__bild--platzhalter"><?= e($fahrzeug['typ']) ?></div>
        <?php endif; ?>

        <div class="steckbrief__daten">
            <p>
                <span class="badge badge--<?= e($stand) ?>">
                    <?= e($statusText[$stand] ?? $stand) ?>
                </span>
                <?php if (fahrzeug_ueberfaellig($id)): ?>
                    <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                <?php endif; ?>
            </p>

            <dl class="merkmale">
                <?php foreach ($merkmalText as $feld => $beschriftung): ?>
                    <?php if (isset($fahrzeug[$feld])): ?>
                        <dt><?= e($beschriftung) ?></dt>
                        <dd>
                            <?php if ($feld === 'kmstand'): ?>
                                <?= number_format($fahrzeug['kmstand'], 0, ',', '.') ?> km
                            <?php elseif ($feld === 'fuehrerschein'): ?>
                                Klasse <?= e($fahrzeug['fuehrerschein']) ?>
                            <?php elseif ($feld === 'hu_au'): ?>
                                <?= e((new DateTimeImmutable($fahrzeug['hu_au']))->format('m/Y')) ?>
                            <?php elseif ($feld === 'art'): ?>
                                <?= e($artText[$fahrzeug['art']] ?? $fahrzeug['art']) ?>
                            <?php elseif ($feld === 'antrieb'): ?>
                                <?= e($antriebText[$fahrzeug['antrieb']] ?? $fahrzeug['antrieb']) ?>
                            <?php else: ?>
                                <?= e((string) $fahrzeug[$feld]) ?>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dl>

            <?php if (wartung_ende_offen($fahrzeug)): ?>
                <p class="note">
                    Das Fahrzeug ist in Wartung. Wann es wieder gebucht werden kann, steht noch nicht
                    fest.
                </p>
            <?php elseif ($fahrzeug['status'] === 'wartung'): ?>
                <?php $buchbarAb = $fahrzeug['wartung_bis']->modify('+1 day'); ?>
                <p class="note">
                    Das Fahrzeug ist in Wartung, voraussichtlich bis
                    <?= e($fahrzeug['wartung_bis']->format('d.m.Y')) ?>. Danach kann es wieder gebucht werden.
                </p>
                <?php if (!$fuhrparkleiter): ?>
                    <a class="button" href="<?= url('buchen.php?' . http_build_query(['fahrzeug' => $id, 'beginn' => $buchbarAb->format('Y-m-d')])) ?>">Ab <?= e($buchbarAb->format('d.m.')) ?> buchen</a>
                <?php endif; ?>
            <?php elseif (!$fuhrparkleiter): ?>
                <a class="button" href="<?= url('buchen.php?fahrzeug=' . $id) ?>">Dieses Fahrzeug buchen</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="section">
        <h2>Offene Schäden</h2>

        <?php if ($offeneSchaeden === []): ?>
            <p class="note">Keine bekannten Schäden.</p>
        <?php else: ?>
            <ul class="schadensliste">
                <?php foreach ($offeneSchaeden as $schaden): ?>
                    <li>
                        <span class="badge<?= $schaden['schwere'] === 'wartung' ? ' badge--abgelehnt' : '' ?>">
                            <?= e($schwereText[$schaden['schwere']]) ?>
                        </span>
                        <?= e($schaden['beschreibung']) ?>
                        <span class="table__zusatz">
                            gemeldet am <?= e($schaden['gemeldet_am']->format('d.m.Y')) ?>
                            <?= $schaden['anlass'] === 'uebernahme' ? 'bei Übernahme' : 'bei Rückgabe' ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2>Belegung der nächsten 14 Tage</h2>

        <ol class="belegung">
            <?php foreach ($belegung as $tag): ?>
                <li class="belegung__tag belegung__tag--<?= e($tag['zustand']) ?>"
                    title="<?= e($tag['hinweis']) ?>">
                    <span class="belegung__wochentag"><?= e($wochentage[(int) $tag['datum']->format('w')]) ?></span>
                    <span class="belegung__datum"><?= e($tag['datum']->format('d.m.')) ?></span>
                    <span class="belegung__zustand"><?= e($zustandText[$tag['zustand']]) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="section" id="fahrtenbuch">
        <h2>Fahrtenbuch</h2>

        <?php if ($eintraege !== []): ?>
            <p class="lead">
                <?= e((string) count($fahrtenbuch)) ?> <?= count($fahrtenbuch) === 1 ? 'Fahrt' : 'Fahrten' ?><?php if ($mitKm): ?>,
                    zusammen <?= e(km_text($summeKm)) ?> km<?php endif; ?><?php if ($wartungen !== []): ?>,
                    dazu <?= e((string) count($wartungen)) ?> <?= count($wartungen) === 1 ? 'Wartung' : 'Wartungen' ?><?php endif; ?>.
                Neueste zuerst.
            </p>
        <?php endif; ?>

        <table class="table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <?php if ($fuhrparkleiter): ?>
                        <th>Fahrer</th>
                    <?php endif; ?>
                    <th>Zweck</th>
                    <?php if ($mitKm): ?>
                        <th class="table__num">km-Stand Anfang</th>
                        <th class="table__num">km-Stand Ende</th>
                    <?php endif; ?>
                    <th>Zustand</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eintraege as $eintrag): ?>
                    <?php if ($eintrag['art'] === 'wartung'): ?>
                        <?php $wartung = $eintrag['daten']; ?>
                        <tr class="table__zeile--wartung">
                            <td>
                                <?php if ($wartung['freigegeben_am'] !== null): ?>
                                    <?= e(zeitraum_text($wartung['begonnen_am'], $wartung['freigegeben_am'])) ?>
                                <?php else: ?>
                                    seit <?= e($wartung['begonnen_am']->format('d.m.Y')) ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($fuhrparkleiter): ?>
                                <td>&ndash;</td>
                            <?php endif; ?>
                            <td>Wartung</td>
                            <?php if ($mitKm): ?>
                                <td class="table__num">&ndash;</td>
                                <td class="table__num">&ndash;</td>
                            <?php endif; ?>
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
                    $schaden = $schadenJeBuchung[$fahrt['id']] ?? null;
                    ?>
                    <tr>
                        <td><?= e(zeitraum_text($fahrt['start'], $fahrt['zurueckgegeben_am'])) ?></td>
                        <?php if ($fuhrparkleiter): ?>
                            <td><?= e(nutzer_name($fahrt['fahrer_id'])) ?></td>
                        <?php endif; ?>
                        <td><?= e($zweckText[$fahrt['zweck']] ?? $fahrt['zweck']) ?></td>
                        <?php if ($mitKm): ?>
                            <td class="table__num"><?= e(km_text($fahrt['km_start'])) ?></td>
                            <td class="table__num"><?= e(km_text($fahrt['km_ende'])) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($schaden !== null): ?>
                                <span class="badge<?= $schaden['schwere'] === 'wartung' ? ' badge--abgelehnt' : '' ?>"><?= e($schwereText[$schaden['schwere']]) ?></span>
                                <span class="table__zusatz"><?= e($schaden['beschreibung']) ?></span>
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
                        <td colspan="<?= e((string) $anzahlSpalten) ?>" class="table__empty">Noch keine Fahrten erfasst.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
