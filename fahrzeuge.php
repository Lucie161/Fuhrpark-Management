<?php
/**
 * Fahrzeuge (Anwendungsfall 10), nur für den Fuhrparkleiter.
 *
 * Liste aller Fahrzeuge mit Status und HU/AU, filterbar nach Fahrzeugart,
 * Status und fälliger HU/AU, sortierbar nach Kennzeichen, km-Stand, HU/AU und
 * Baujahr. Fahrzeuge in Wartung setzen und wieder freigeben. Die gemeldeten
 * Schäden stehen in schaeden.php, hier nur ein Hinweis am Fahrzeug.
 * Mitarbeiter finden die Fahrzeuge in buchen.php und im Steckbrief.
 *
 * Statuspflege, keine Stammdatenpflege: Fahrzeuge werden hier weder angelegt
 * noch gelöscht (siehe docs/aenderungen-fachkonzept.md, A14).
 *
 * Filter und Sortierung kommen per GET, ohne JavaScript, z. B.
 * fahrzeuge.php?art=auto&sortierung=hu_au&richtung=auf. Übernommen werden nur
 * Werte aus den festen Listen unten, alles andere fällt auf den Standard
 * zurück.
 *
 * Prototyp: Die Daten kommen aus includes/beispieldaten.php. Eine
 * Statusänderung wird geprüft und auf dieser Seite angezeigt, aber noch nicht
 * gespeichert. Mit Datenbank (siehe docs/technisches-konzept.md, Regel 10):
 *
 *   SELECT * FROM fahrzeuge [WHERE art = :art] ORDER BY <spalte> ASC|DESC
 *   SELECT ... FROM buchungen
 *    WHERE status IN ('offen', 'genehmigt', 'unterwegs') AND ende >= CURDATE()
 *   SELECT DISTINCT b.fahrzeug_id FROM schaeden s JOIN buchungen b ON b.id = s.buchung_id
 *    WHERE s.schwere = 'wartung' AND s.behoben_am IS NULL
 *
 * <spalte> und die Richtung nur aus $sortierungen bzw. 'ASC'/'DESC', denn
 * ORDER BY lässt sich nicht als Platzhalter binden. Status und HU/AU filtert
 * PHP danach, denn „unterwegs“ ergibt sich erst aus den Buchungen.
 *
 * Wartung (entschieden am 10.10.2026): Jede Wartung ist eine Zeile in
 * wartungen; „in Wartung“ ist ein Fahrzeug, solange es eine Zeile ohne
 * freigegeben_am gibt. „In Wartung setzen“ verlangt Grund und
 * voraussichtliches Ende, „Ende ändern“ das neue Ende. Bis dahin ist das
 * Fahrzeug gesperrt, danach buchbar; freigegeben wird es von Hand. Beantragte
 * und genehmigte Buchungen, die vor dem Ende beginnen, werden automatisch
 * storniert; laufende Fahrten bleiben, der Fuhrparkleiter sieht einen Hinweis.
 *
 * Beim Speichern in einer Transaktion:
 *   INSERT INTO wartungen (fahrzeug_id, grund, begonnen_am, voraussichtlich_bis, angelegt_von)
 *   VALUES (:id, :grund, CURDATE(), :bis, :ich)                       -- In Wartung setzen
 *   UPDATE wartungen SET voraussichtlich_bis = :bis
 *    WHERE fahrzeug_id = :id AND freigegeben_am IS NULL               -- Ende ändern
 *   UPDATE buchungen SET status = 'storniert',
 *          entscheidung_kommentar = 'Automatisch storniert: …'
 *    WHERE fahrzeug_id = :id AND status IN ('offen', 'genehmigt')
 *      AND start <= :bis AND ende >= CURDATE()
 * beim Freigeben:
 *   UPDATE wartungen SET freigegeben_am = CURDATE(), freigegeben_von = :ich
 *    WHERE fahrzeug_id = :id AND freigegeben_am IS NULL
 * und der Schaden, der die Wartung ausgelöst hat, gilt als behoben:
 *   UPDATE schaeden s JOIN buchungen b ON b.id = s.buchung_id
 *      SET s.behoben_am = NOW(), s.behoben_von = :ich
 *    WHERE b.fahrzeug_id = :id AND s.schwere = 'wartung' AND s.behoben_am IS NULL
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

// Fahrzeugart => Beschriftung im Filter (wie in historie.php).
$artText = [
    'auto'        => 'Autos',
    'transporter' => 'Transporter',
    'roller'      => 'Roller',
    'fahrrad'     => 'Fahrräder',
];

// Erlaubte Sortierungen, zugleich die Spalten in ORDER BY. „Fahrzeug“ wird
// nach dem Kennzeichen sortiert.
$sortierungen = ['kennzeichen', 'kmstand', 'hu_au', 'baujahr'];

// Aktion => [erlaubt bei Status, neuer Status]. „verlaengern“ ändert nur das
// voraussichtliche Ende einer laufenden Wartung (auch auf früher).
$aktionen = [
    'wartung'     => ['verfuegbar', 'wartung'],
    'verlaengern' => ['wartung',    'wartung'],
    'freigeben'   => ['wartung',    'verfuegbar'],
];

// Grund in entscheidung_kommentar, den der Fahrer unter „Frühere Buchungen“
// liest.
$stornoGrund = 'Automatisch storniert: Das Fahrzeug ist in diesem Zeitraum in Wartung.';

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

$fahrzeuge = beispiel_fahrzeuge();

$heute = new DateTimeImmutable('today');

// Fahrzeuge mit offenem Schaden, der die Wartung ausgelöst hat (wie in
// schaeden.php). Mit dem Freigeben gilt er als behoben.
$mitSchaden = [];

foreach (beispiel_schaeden() as $schaden) {
    if ($schaden['schwere'] === 'wartung' && $schaden['behoben_am'] === null) {
        $mitSchaden[] = $schaden['fahrzeug_id'];
    }
}

// --- Filter und Sortierung (GET) --------------------------------------------

$filter = [
    'art'    => erlaubter_wert('art', array_keys($artText)),
    'status' => erlaubter_wert('status', array_keys($statusText)),
    'hu_au'  => erlaubter_wert('hu_au', ['faellig']),
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
// Bestehende Buchungen ab heute (beantragt, genehmigt, unterwegs).

$buchungenJeFahrzeug = [];

foreach (beispiel_buchungen() as $buchung) {
    if (isset($buchungText[$buchung['status']])
        && ($buchung['ende'] >= $heute || $buchung['status'] === 'unterwegs')) {
        $buchungenJeFahrzeug[$buchung['fahrzeug_id']][] = $buchung;
    }
}

foreach ($buchungenJeFahrzeug as &$liste) {
    usort($liste, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
}
unset($liste);

// --- Formular verarbeiten ---------------------------------------------------
// Läuft vor header.php, damit später eine Weiterleitung möglich ist. Auch
// „Freigeben“ in schaeden.php schickt hierher.

$fehler = [];
$bestaetigung = null;

// Nach einem Fehler bleibt das Wartungsformular dieses Fahrzeugs offen.
$offenesFormular = null;

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
            $wartungBis = null;

            $grund = is_string($_POST['grund'] ?? null) ? trim($_POST['grund']) : '';

            if ($aktion === 'wartung' && ($grund === '' || mb_strlen($grund) > 200)) {
                $fehler[] = 'Bitte geben Sie den Grund der Wartung an (höchstens 200 Zeichen), z. B. „Inspektion“ oder „HU/AU“.';
                $offenesFormular = $id;
            }

            if ($neuerStatus === 'wartung') {
                $wert = is_string($_POST['wartung_bis'] ?? null) ? $_POST['wartung_bis'] : '';
                $datum = DateTimeImmutable::createFromFormat('!Y-m-d', $wert);

                if ($datum === false || $datum->format('Y-m-d') !== $wert || $datum < $heute) {
                    $fehler[] = 'Bitte geben Sie das voraussichtliche Ende der Wartung an, frühestens heute.';
                    $offenesFormular = $id;
                } else {
                    $wartungBis = $datum;
                }
            }

            if ($fehler === []) {
                // Buchungen, die vor dem Ende der Wartung beginnen: beantragte
                // und genehmigte werden storniert, laufende bleiben.
                $storniert = [];
                $laufend   = [];

                foreach ($buchungenJeFahrzeug[$id] ?? [] as $buchung) {
                    if ($wartungBis === null || $buchung['start'] > $wartungBis) {
                        continue;
                    }

                    if ($buchung['status'] === 'unterwegs') {
                        $laufend[] = $buchung;
                    } else {
                        $storniert[] = $buchung;
                    }
                }

                // Nur für diese Anzeige; gespeichert wird noch nicht.
                $fahrzeuge[$id]['status'] = $neuerStatus;
                $fahrzeuge[$id]['wartung_bis'] = $wartungBis;
                $buchungenJeFahrzeug[$id] = array_values(array_filter(
                    $buchungenJeFahrzeug[$id] ?? [],
                    fn (array $b): bool => !in_array($b, $storniert, true),
                ));

                $bestaetigung = [
                    'fahrzeug'  => $id,
                    'aktion'    => $aktion,
                    'bis'       => $wartungBis,
                    'storniert' => $storniert,
                    'laufend'   => $laufend,
                    'behoben'   => $neuerStatus === 'verfuegbar' && in_array($id, $mitSchaden, true),
                ];
            }
        }
    }
}

// --- Fahrzeuge aufbereiten --------------------------------------------------

$diesenMonat = $heute->modify('first day of this month');

foreach ($fahrzeuge as $id => &$fahrzeug) {
    // HU/AU: überfällig vor diesem Monat, fällig in diesem oder den nächsten
    // zwei Monaten.
    $fahrzeug['hu_au_stand'] = null;

    if ($fahrzeug['hu_au'] !== null) {
        $huAu = new DateTimeImmutable($fahrzeug['hu_au']);

        if ($huAu < $diesenMonat) {
            $fahrzeug['hu_au_stand'] = 'ueberfaellig';
        } elseif ($huAu <= $diesenMonat->modify('+2 month')) {
            $fahrzeug['hu_au_stand'] = 'faellig';
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
    && ($filter['hu_au'] === '' || $f['hu_au_stand'] !== null)
);

// Ohne Wert (Fahrrad: kein km-Stand, keine HU/AU) stehen die Fahrzeuge in
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
    ['Fahrzeug',       'kennzeichen', ''],
    ['Baujahr',        'baujahr',     ''],
    ['km-Stand',       'kmstand',     'table__num'],
    ['Status',         null,          ''],
    ['Nächste HU/AU',  'hu_au',       ''],
    ['Aktionen',       null,          ''],
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

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Alle Fahrzeuge mit Status und HU/AU. Fahrzeuge für Wartung oder Reparatur sperren und wieder
    freigeben. Gemeldete Schäden stehen unter <a href="<?= url('schaeden.php') ?>">Schäden</a>.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($bestaetigung !== null): ?>
    <?php $name = fahrzeug_name($bestaetigung['fahrzeug']); ?>

    <?php if ($bestaetigung['aktion'] === 'freigeben'): ?>
        <p class="alert alert--erfolg">
            <?= e($name) ?> ist wieder freigegeben und kann gebucht werden.
            <?php if ($bestaetigung['behoben']): ?>
                Der gemeldete Schaden gilt damit als behoben.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="alert alert--hinweis">
            <p class="alert__zeile">
                <?= e($name) ?> ist in Wartung, voraussichtlich bis
                <?= e($bestaetigung['bis']->format('d.m.Y')) ?>. Bis dahin kann es nicht gebucht werden.
            </p>
            <?php if ($bestaetigung['storniert'] !== []): ?>
                <p class="alert__zeile">
                    Automatisch storniert; die Fahrer sehen den Grund unter &bdquo;Frühere Buchungen&ldquo;:
                </p>
                <ul class="klappaktion__liste">
                    <?php foreach ($bestaetigung['storniert'] as $buchung): ?>
                        <li><?= e(zeitraum_text($buchung['start'], $buchung['ende'])) ?>, <?= e(nutzer_name($buchung['fahrer_id'])) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php foreach ($bestaetigung['laufend'] as $buchung): ?>
                <p class="alert__zeile">
                    <?= e(nutzer_name($buchung['fahrer_id'])) ?> ist mit dem Fahrzeug noch unterwegs. Bitte
                    klären Sie die Rückgabe mit dem Fahrer.
                </p>
            <?php endforeach; ?>
        </div>
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
            <label class="form__label" for="hu_au">HU/AU</label>
            <select class="form__input" id="hu_au" name="hu_au">
                <option value="">Alle</option>
                <option value="faellig"<?= $filter['hu_au'] === 'faellig' ? ' selected' : '' ?>>bald fällig oder überfällig</option>
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
                        <?php if (wartung_ende_offen($fahrzeug)): ?>
                            <span class="badge badge--abgelehnt">Ende festlegen</span>
                        <?php endif; ?>
                        <?php if ($fahrzeug['status'] === 'wartung'): ?>
                            <span class="table__zusatz"><?= e(wartung_text($fahrzeug)) ?></span>
                        <?php endif; ?>
                        <?php if (fahrzeug_ueberfaellig($id)): ?>
                            <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                        <?php endif; ?>
                        <?php if ($fahrzeug['schaden']): ?>
                            <span class="table__zusatz">
                                <a href="<?= url('schaeden.php') ?>">Schaden gemeldet</a>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($fahrzeug['hu_au'] === null): ?>
                            &ndash;
                        <?php else: ?>
                            <?= e((new DateTimeImmutable($fahrzeug['hu_au']))->format('m/Y')) ?>
                            <?php if ($fahrzeug['hu_au_stand'] === 'ueberfaellig'): ?>
                                <span class="badge badge--abgelehnt">überfällig</span>
                            <?php elseif ($fahrzeug['hu_au_stand'] === 'faellig'): ?>
                                <span class="badge badge--offen">bald fällig</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="aktionen">
                            <?php
                            // Dasselbe Formular zum Sperren und zum Ändern des Endes.
                            $wartungAktion = $fahrzeug['status'] === 'verfuegbar' ? 'wartung' : 'verlaengern';
                            $wartungWert = $fahrzeug['status'] === 'wartung' && !wartung_ende_offen($fahrzeug)
                                ? $fahrzeug['wartung_bis']->format('Y-m-d')
                                : '';
                            ?>
                            <details class="klappaktion"<?= $offenesFormular === $id ? ' open' : '' ?>>
                                <summary class="button button--klein"><?= $wartungAktion === 'wartung' ? 'In Wartung setzen' : 'Ende ändern' ?></summary>

                                <form class="klappaktion__form" method="post" action="<?= e(url($seite)) ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                    <input type="hidden" name="aktion" value="<?= e($wartungAktion) ?>">

                                    <?php if ($wartungAktion === 'wartung'): ?>
                                        <label class="form__label" for="grund-<?= e((string) $id) ?>">Grund</label>
                                        <input class="form__input" type="text" id="grund-<?= e((string) $id) ?>"
                                               name="grund" required maxlength="200" placeholder="z. B. Inspektion, HU/AU">
                                    <?php endif; ?>

                                    <label class="form__label" for="wartung-bis-<?= e((string) $id) ?>">Voraussichtlich bis</label>
                                    <input class="form__input" type="date" id="wartung-bis-<?= e((string) $id) ?>"
                                           name="wartung_bis" required min="<?= e($heute->format('Y-m-d')) ?>"
                                           value="<?= e($wartungWert) ?>">

                                    <?php if ($buchungen !== []): ?>
                                        <p class="klappaktion__frage">
                                            Beantragte und genehmigte Buchungen, die bis dahin beginnen, werden
                                            automatisch storniert:
                                        </p>
                                        <ul class="klappaktion__liste">
                                            <?php foreach ($buchungen as $buchung): ?>
                                                <li>
                                                    <?= e(zeitraum_text($buchung['start'], $buchung['ende'])) ?>,
                                                    <?= e(nutzer_name($buchung['fahrer_id'])) ?>,
                                                    <?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?>
                                                    (<?= e($buchungText[$buchung['status']] ?? $buchung['status']) ?>)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="klappaktion__frage">Es bestehen keine Buchungen.</p>
                                    <?php endif; ?>

                                    <button class="button button--klein" type="submit">
                                        <?= $wartungAktion === 'wartung' ? 'Fahrzeug sperren' : 'Ende speichern' ?>
                                    </button>
                                </form>
                            </details>

                            <?php if ($fahrzeug['status'] === 'wartung'): ?>
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
