<?php
/**
 * Fahrzeuge (Anwendungsfall 10), nur für den Fuhrparkleiter.
 *
 * Liste aller Fahrzeuge mit Status und TÜV, filterbar nach Fahrzeugart,
 * Status und fälligem TÜV, sortierbar nach Kennzeichen, km-Stand, TÜV und
 * Baujahr. Fahrzeuge in Wartung setzen und wieder freigeben. Die gemeldeten
 * Schäden und Bemerkungen stehen in schaeden.php, hier nur ein Hinweis am
 * Fahrzeug. Mitarbeiter finden die Fahrzeuge in buchen.php und im Steckbrief.
 *
 * Statuspflege, keine Stammdatenpflege: Fahrzeuge werden hier weder angelegt
 * noch gelöscht (siehe docs/aenderungen-fachkonzept.md, A14).
 *
 * Filter und Sortierung kommen per GET, ohne JavaScript, z. B.
 * fahrzeuge.php?art=auto&sortierung=tuev&richtung=auf. Übernommen werden nur
 * Werte aus den festen Listen unten, alles andere fällt auf den Standard
 * zurück.
 *
 * Prototyp: Fahrzeuge und Buchungen sind feste Beispieldaten. Eine
 * Statusänderung wird geprüft und auf dieser Seite angezeigt, aber noch nicht
 * gespeichert. Mit Datenbank (siehe docs/technisches-konzept.md, Regel 10):
 *
 *   SELECT * FROM fahrzeuge [WHERE art = :art] ORDER BY <spalte> ASC|DESC
 *   SELECT ... FROM buchungen
 *    WHERE status IN ('offen', 'genehmigt', 'unterwegs') AND ende >= CURDATE()
 *   SELECT DISTINCT fahrzeug_id FROM buchungen WHERE schaden = 1
 *
 * <spalte> und die Richtung nur aus $sortierungen bzw. 'ASC'/'DESC', denn
 * ORDER BY lässt sich nicht als Platzhalter binden. Status und TÜV filtert
 * PHP danach, denn „unterwegs“ ergibt sich erst aus den Buchungen.
 *
 * Beim Speichern:
 *   UPDATE fahrzeuge SET status = :status WHERE id = :id
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('fuhrparkleiter');

$pageTitle = 'Fahrzeuge';

// Status => Beschriftung. Das Kürzel dient zugleich als CSS-Klasse
// (.badge--wartung usw.). Gespeichert werden nur „verfuegbar“ und „wartung“;
// „unterwegs“ wird aus den laufenden Fahrten abgeleitet.
$statusText = [
    'verfuegbar' => 'verfügbar',
    'unterwegs'  => 'unterwegs',
    'wartung'    => 'in Wartung',
];

// Fahrzeugart => Beschriftung im Filter (wie in verlauf.php).
$artText = [
    'auto'    => 'Autos',
    'roller'  => 'Roller',
    'fahrrad' => 'Fahrräder',
];

// Erlaubte Sortierungen, zugleich die Spalten in ORDER BY. „Fahrzeug“ wird
// nach dem Kennzeichen sortiert.
$sortierungen = ['kennzeichen', 'kmstand', 'tuev', 'baujahr'];

// Aktion => [erlaubt bei Status, neuer Status].
$aktionen = [
    'wartung'   => ['verfuegbar', 'wartung'],
    'freigeben' => ['wartung',    'verfuegbar'],
];

// Stand einer Buchung => Beschriftung (wie in meine-buchungen.php).
$buchungText = [
    'offen'     => 'beantragt',
    'genehmigt' => 'genehmigt',
    'unterwegs' => 'unterwegs',
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

// Beispiel-Fuhrpark, dieselben Fahrzeuge wie in fahrzeug.php. TÜV als
// Jahr-Monat; TÜV und km-Stand sind null beim Fahrrad.
$fahrzeuge = [
    1 => ['kennzeichen' => 'M-HS 101',  'hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'art' => 'auto',    'baujahr' => 2021, 'kmstand' => 48250,  'status' => 'verfuegbar', 'tuev' => '2027-03'],
    2 => ['kennzeichen' => 'M-HS 102',  'hersteller' => 'Škoda',          'modell' => 'Octavia Combi',  'art' => 'auto',    'baujahr' => 2023, 'kmstand' => 9870,   'status' => 'verfuegbar', 'tuev' => '2026-05'],
    3 => ['kennzeichen' => 'M-HS 201',  'hersteller' => 'Ford',           'modell' => 'Transit',        'art' => 'auto',    'baujahr' => 2019, 'kmstand' => 112400, 'status' => 'verfuegbar', 'tuev' => '2026-11'],
    4 => ['kennzeichen' => 'M-HS 202',  'hersteller' => 'Mercedes-Benz',  'modell' => 'Sprinter',       'art' => 'auto',    'baujahr' => 2020, 'kmstand' => 87310,  'status' => 'wartung',    'tuev' => '2026-10'],
    5 => ['kennzeichen' => 'M-HS 301E', 'hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'art' => 'auto',    'baujahr' => 2022, 'kmstand' => 31540,  'status' => 'verfuegbar', 'tuev' => '2027-08'],
    6 => ['kennzeichen' => 'M-HS 302E', 'hersteller' => 'Tesla',          'modell' => 'Model 3',        'art' => 'auto',    'baujahr' => 2024, 'kmstand' => 12020,  'status' => 'verfuegbar', 'tuev' => '2027-02'],
    7 => ['kennzeichen' => 'M-HS 401',  'hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'art' => 'roller',  'baujahr' => 2022, 'kmstand' => 6400,   'status' => 'verfuegbar', 'tuev' => '2027-06'],
    8 => ['kennzeichen' => 'Rad 1',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad', 'baujahr' => 2023, 'kmstand' => null,   'status' => 'verfuegbar', 'tuev' => null],
    9 => ['kennzeichen' => 'Rad 2',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'art' => 'fahrrad', 'baujahr' => 2023, 'kmstand' => null,   'status' => 'verfuegbar', 'tuev' => null],
];

$heute = new DateTimeImmutable('today');

// Bestehende Buchungen ab heute (beantragt, genehmigt, unterwegs). Tage
// relativ zu heute, wie in fahrzeug.php und meine-buchungen.php.
$beispielBuchungen = [
    ['fahrzeug_id' => 1, 'von' => 1,  'bis' => 1,  'fahrer' => 'Ella Luppold',    'zweck' => 'kundentermin',      'status' => 'genehmigt'],
    ['fahrzeug_id' => 1, 'von' => 4,  'bis' => 6,  'fahrer' => 'Finn Clausen',    'zweck' => 'service',           'status' => 'offen'],
    ['fahrzeug_id' => 3, 'von' => 0,  'bis' => 2,  'fahrer' => 'Kenneth Sander',  'zweck' => 'montage',           'status' => 'unterwegs'],
    ['fahrzeug_id' => 3, 'von' => 7,  'bis' => 8,  'fahrer' => 'Larissa Wagner',  'zweck' => 'materialtransport', 'status' => 'genehmigt'],
    ['fahrzeug_id' => 5, 'von' => 2,  'bis' => 2,  'fahrer' => 'Lucie Schneider', 'zweck' => 'kundentermin',      'status' => 'genehmigt'],
    ['fahrzeug_id' => 7, 'von' => -2, 'bis' => -1, 'fahrer' => 'Lucie Schneider', 'zweck' => 'kundentermin',      'status' => 'unterwegs'],
];

// Fahrzeuge mit gemeldetem Schaden, wie in schaeden.php. Der Hinweis
// erscheint nur, solange das Fahrzeug in Wartung steht; mit dem Freigeben
// gilt der Schaden als erledigt (wie die Kachel in index.php).
$mitSchaden = [4];

// --- Filter und Sortierung (GET) --------------------------------------------

$filter = [
    'art'    => erlaubter_wert('art', array_keys($artText)),
    'status' => erlaubter_wert('status', array_keys($statusText)),
    'tuev'   => erlaubter_wert('tuev', ['faellig']),
];

$sortierung = erlaubter_wert('sortierung', $sortierungen, 'kennzeichen');
$richtung   = erlaubter_wert('richtung', ['auf', 'ab'], 'auf');

// Parameter dieser Ansicht ohne Standardwerte. Formulare und Links der Seite
// hängen sie an, damit Filter und Sortierung nach einer Aktion erhalten
// bleiben.
$ansicht = array_filter($filter, fn (string $wert): bool => $wert !== '');

if ($sortierung !== 'kennzeichen' || $richtung !== 'auf') {
    $ansicht += ['sortierung' => $sortierung, 'richtung' => $richtung];
}

$seite = 'fahrzeuge.php' . ($ansicht !== [] ? '?' . http_build_query($ansicht) : '');

// --- Buchungen je Fahrzeug --------------------------------------------------

$buchungenJeFahrzeug = [];

foreach ($beispielBuchungen as $buchung) {
    $buchung['start'] = $heute->modify($buchung['von'] . ' day');
    $buchung['ende']  = $heute->modify($buchung['bis'] . ' day');
    $buchungenJeFahrzeug[$buchung['fahrzeug_id']][] = $buchung;
}

// --- Formular verarbeiten ---------------------------------------------------
// Läuft vor header.php, damit später eine Weiterleitung möglich ist. Auch
// „Freigeben“ in schaeden.php schickt hierher.

$fehler = [];
$bestaetigung = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['fahrzeug'] ?? 0);
    $aktion = (string) ($_POST['aktion'] ?? '');

    if (!isset($fahrzeuge[$id])) {
        $fehler[] = 'Zu dieser Angabe gibt es kein Fahrzeug.';
    } elseif (!isset($aktionen[$aktion])) {
        $fehler[] = 'Diese Aktion gibt es nicht.';
    } else {
        [$erlaubtBei, $neuerStatus] = $aktionen[$aktion];
        $alterStatus = $fahrzeuge[$id]['status'];

        if ($alterStatus !== $erlaubtBei) {
            $fehler[] = 'Das Fahrzeug ist bereits „' . ($statusText[$alterStatus] ?? $alterStatus)
                . '“. Die Aktion ist dafür nicht möglich.';
        } else {
            // Nur für diese Anzeige; gespeichert wird noch nicht.
            $fahrzeuge[$id]['status'] = $neuerStatus;

            $bestaetigung = [
                'fahrzeug'  => $id,
                'status'    => $neuerStatus,
                'buchungen' => count($buchungenJeFahrzeug[$id] ?? []),
            ];
        }
    }
}

// --- Fahrzeuge aufbereiten --------------------------------------------------

$diesenMonat = $heute->modify('first day of this month');

foreach ($fahrzeuge as $id => &$fahrzeug) {
    $fahrzeug['name'] = $fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']
        . ' (' . $fahrzeug['kennzeichen'] . ')';

    // TÜV: überfällig vor diesem Monat, fällig in diesem oder den nächsten
    // zwei Monaten.
    $fahrzeug['tuev_stand'] = null;

    if ($fahrzeug['tuev'] !== null) {
        $tuev = new DateTimeImmutable($fahrzeug['tuev'] . '-01');

        if ($tuev < $diesenMonat) {
            $fahrzeug['tuev_stand'] = 'ueberfaellig';
        } elseif ($tuev <= $diesenMonat->modify('+2 month')) {
            $fahrzeug['tuev_stand'] = 'faellig';
        }
    }

    $fahrzeug['unterwegs'] = false;

    foreach ($buchungenJeFahrzeug[$id] ?? [] as $buchung) {
        if ($buchung['status'] === 'unterwegs') {
            $fahrzeug['unterwegs'] = true;
        }
    }

    // Angezeigter Status: Wartung vor unterwegs vor verfügbar.
    $fahrzeug['stand'] = $fahrzeug['status'] === 'verfuegbar' && $fahrzeug['unterwegs']
        ? 'unterwegs'
        : $fahrzeug['status'];

    $fahrzeug['schaden'] = $fahrzeug['status'] === 'wartung' && in_array($id, $mitSchaden, true);
}
unset($fahrzeug);

// --- Filtern und sortieren --------------------------------------------------

$liste = array_filter($fahrzeuge, fn (array $f): bool =>
    ($filter['art'] === '' || $f['art'] === $filter['art'])
    && match ($filter['status']) {
        ''          => true,
        'unterwegs' => $f['unterwegs'],
        default     => $f['stand'] === $filter['status'],
    }
    && ($filter['tuev'] === '' || $f['tuev_stand'] !== null)
);

// Ohne Wert (Fahrrad: kein km-Stand, kein TÜV) stehen die Fahrzeuge in
// beiden Richtungen am Ende. Bei Gleichstand entscheidet das Kennzeichen.
uasort($liste, function (array $a, array $b) use ($sortierung, $richtung): int {
    $x = $a[$sortierung];
    $y = $b[$sortierung];

    if ($x === null || $y === null) {
        $vergleich = ($x === null) <=> ($y === null);
    } else {
        $vergleich = is_int($x) ? $x <=> $y : strnatcmp($x, $y);
        $vergleich = $richtung === 'ab' ? -$vergleich : $vergleich;
    }

    return $vergleich !== 0 ? $vergleich : strnatcmp($a['kennzeichen'], $b['kennzeichen']);
});

// Spalten der Tabelle: Überschrift, Sortierung (null = nicht sortierbar),
// CSS-Klasse.
$spalten = [
    ['Fahrzeug',     'kennzeichen', ''],
    ['Baujahr',      'baujahr',     ''],
    ['km-Stand',     'kmstand',     'table__num'],
    ['Status',       null,          ''],
    ['Nächster TÜV', 'tuev',        ''],
    ['Aktionen',     null,          ''],
];

/**
 * GET-Parameter, sofern er in der Liste erlaubter Werte steht, sonst der
 * Standard.
 */
function erlaubter_wert(string $name, array $erlaubt, string $standard = ''): string
{
    $wert = $_GET[$name] ?? '';

    return is_string($wert) && in_array($wert, $erlaubt, true) ? $wert : $standard;
}

/**
 * Link einer Spaltenüberschrift: sortiert nach dieser Spalte aufsteigend,
 * ist sie es schon, kehrt er die Richtung um. Der Filter bleibt erhalten.
 */
function sortier_link(string $spalte, string $sortierung, string $richtung, array $filter): string
{
    $neueRichtung = $spalte === $sortierung && $richtung === 'auf' ? 'ab' : 'auf';
    $parameter = array_filter($filter, fn (string $wert): bool => $wert !== '')
        + ['sortierung' => $spalte, 'richtung' => $neueRichtung];

    return url('fahrzeuge.php?' . http_build_query($parameter));
}

/**
 * Zeitraum einer Buchung als Text, eintägig ohne „bis“.
 */
function zeitraum(array $buchung): string
{
    $von = $buchung['start']->format('d.m.Y');
    $bis = $buchung['ende']->format('d.m.Y');

    return $von === $bis ? $von : $von . ' bis ' . $bis;
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Alle Fahrzeuge mit Status und TÜV. Fahrzeuge für Wartung oder Reparatur sperren und wieder
    freigeben. Gemeldete Schäden stehen unter <a href="<?= url('schaeden.php') ?>">Schäden</a>.
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten. Statusänderungen werden geprüft und angezeigt, aber noch nicht
    gespeichert.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($bestaetigung !== null): ?>
    <?php $name = $fahrzeuge[$bestaetigung['fahrzeug']]['name']; ?>

    <?php if ($bestaetigung['status'] === 'verfuegbar'): ?>
        <p class="alert alert--erfolg">
            <?= e($name) ?> ist wieder freigegeben und kann gebucht werden.
        </p>
    <?php else: ?>
        <p class="alert alert--hinweis">
            <?= e($name) ?> ist jetzt &bdquo;<?= e($statusText[$bestaetigung['status']]) ?>&ldquo;
            und kann nicht mehr gebucht werden.
            <?php if ($bestaetigung['buchungen'] > 0): ?>
                Es bestehen noch <?= e((string) $bestaetigung['buchungen']) ?> Buchungen für dieses
                Fahrzeug. Sie bleiben bestehen &ndash; bitte klären Sie sie mit den Fahrern.
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<section class="section">
    <form class="filter" method="get" action="<?= url('fahrzeuge.php') ?>">
        <?php if (isset($ansicht['sortierung'])): ?>
            <input type="hidden" name="sortierung" value="<?= e($sortierung) ?>">
            <input type="hidden" name="richtung" value="<?= e($richtung) ?>">
        <?php endif; ?>

        <div class="filter__feld filter__feld--schmal">
            <label class="form__label" for="art">Fahrzeugart</label>
            <select class="form__input" id="art" name="art">
                <option value="">Alle</option>
                <?php foreach ($artText as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $wert === $filter['art'] ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter__feld filter__feld--schmal">
            <label class="form__label" for="status">Status</label>
            <select class="form__input" id="status" name="status">
                <option value="">Alle</option>
                <?php foreach ($statusText as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $wert === $filter['status'] ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter__feld filter__feld--schmal">
            <label class="form__label" for="tuev">TÜV</label>
            <select class="form__input" id="tuev" name="tuev">
                <option value="">Alle</option>
                <option value="faellig"<?= $filter['tuev'] === 'faellig' ? ' selected' : '' ?>>bald fällig oder überfällig</option>
            </select>
        </div>

        <button class="button" type="submit">Filtern</button>

        <?php if (array_filter($filter) !== []): ?>
            <a class="button button--zweitrangig"
               href="<?= e(url('fahrzeuge.php' . (isset($ansicht['sortierung']) ? '?' . http_build_query(['sortierung' => $sortierung, 'richtung' => $richtung]) : ''))) ?>">Filter zurücksetzen</a>
        <?php endif; ?>
    </form>

    <p class="lead">
        <?php if (count($liste) === count($fahrzeuge)): ?>
            <?= e((string) count($fahrzeuge)) ?> Fahrzeuge.
        <?php else: ?>
            <?= e((string) count($liste)) ?> von <?= e((string) count($fahrzeuge)) ?> Fahrzeugen.
        <?php endif; ?>
        Zum Sortieren auf eine Spaltenüberschrift klicken.
    </p>

    <table class="table">
        <thead>
            <tr>
                <?php foreach ($spalten as [$titel, $spalte, $klasse]): ?>
                    <?php $aktiv = $spalte === $sortierung; ?>
                    <th<?= $klasse !== '' ? ' class="' . e($klasse) . '"' : '' ?><?= $aktiv ? ' aria-sort="' . ($richtung === 'auf' ? 'ascending' : 'descending') . '"' : '' ?>>
                        <?php if ($spalte === null): ?>
                            <?= e($titel) ?>
                        <?php else: ?>
                            <a class="table__sortierlink" href="<?= e(sortier_link($spalte, $sortierung, $richtung, $filter)) ?>"><?= e($titel) ?><?php if ($aktiv): ?>
                                <span aria-hidden="true"><?= $richtung === 'auf' ? '&uarr;' : '&darr;' ?></span><?php endif; ?></a>
                        <?php endif; ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($liste as $id => $fahrzeug): ?>
                <?php $buchungen = $buchungenJeFahrzeug[$id] ?? []; ?>
                <tr>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $id) ?>"><?= e($fahrzeug['hersteller'] . ' ' . $fahrzeug['modell']) ?></a>
                        <span class="table__zusatz"><?= e($fahrzeug['kennzeichen']) ?></span>
                    </td>
                    <td><?= e((string) $fahrzeug['baujahr']) ?></td>
                    <td class="table__num">
                        <?= $fahrzeug['kmstand'] !== null ? e(number_format($fahrzeug['kmstand'], 0, ',', '.')) : '&ndash;' ?>
                    </td>
                    <td>
                        <span class="badge badge--<?= e($fahrzeug['stand']) ?>">
                            <?= e($statusText[$fahrzeug['stand']] ?? $fahrzeug['stand']) ?>
                        </span>
                        <?php if ($fahrzeug['stand'] === 'wartung' && $fahrzeug['unterwegs']): ?>
                            <span class="badge badge--unterwegs">unterwegs</span>
                        <?php endif; ?>
                        <?php if ($fahrzeug['schaden']): ?>
                            <span class="table__zusatz">
                                <a href="<?= url('schaeden.php') ?>">Schaden gemeldet</a>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($fahrzeug['tuev'] === null): ?>
                            &ndash;
                        <?php else: ?>
                            <?= e((new DateTimeImmutable($fahrzeug['tuev'] . '-01'))->format('m/Y')) ?>
                            <?php if ($fahrzeug['tuev_stand'] === 'ueberfaellig'): ?>
                                <span class="badge badge--abgelehnt">überfällig</span>
                            <?php elseif ($fahrzeug['tuev_stand'] === 'faellig'): ?>
                                <span class="badge badge--offen">bald fällig</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="aktionen">
                            <?php if ($fahrzeug['status'] === 'verfuegbar'): ?>
                                <details class="klappaktion">
                                    <summary class="button button--klein">In Wartung setzen</summary>

                                    <form class="klappaktion__form" method="post" action="<?= e(url($seite)) ?>">
                                        <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                        <input type="hidden" name="aktion" value="wartung">

                                        <?php if ($buchungen !== []): ?>
                                            <p class="klappaktion__frage">Bestehende Buchungen bleiben erhalten:</p>
                                            <ul class="klappaktion__liste">
                                                <?php foreach ($buchungen as $buchung): ?>
                                                    <li>
                                                        <?= e(zeitraum($buchung)) ?>, <?= e($buchung['fahrer']) ?>,
                                                        <?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?>
                                                        (<?= e($buchungText[$buchung['status']] ?? $buchung['status']) ?>)
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="klappaktion__frage">Es bestehen keine Buchungen.</p>
                                        <?php endif; ?>

                                        <button class="button button--klein" type="submit">Fahrzeug sperren</button>
                                    </form>
                                </details>
                            <?php else: ?>
                                <form method="post" action="<?= e(url($seite)) ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                    <input type="hidden" name="aktion" value="freigeben">
                                    <button class="button button--klein" type="submit">Freigeben</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($liste === []): ?>
                <tr>
                    <td colspan="<?= e((string) count($spalten)) ?>" class="table__empty">
                        <?= $fahrzeuge === [] ? 'Keine Fahrzeuge erfasst.' : 'Kein Fahrzeug passt zu diesem Filter.' ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
