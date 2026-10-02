<?php
/**
 * Fahrzeugstatus verwalten (Anwendungsfall 10) — nur für den Fuhrparkleiter.
 *
 * Statuspflege, keine Stammdatenpflege: Fahrzeuge werden hier weder angelegt
 * noch gelöscht (siehe docs/aenderungen-fachkonzept.md, A14).
 *
 * Prototyp: Fahrzeuge, Buchungen und Meldungen sind feste Beispieldaten. Eine
 * Statusänderung wird geprüft und auf dieser Seite angezeigt, aber noch nicht
 * gespeichert. Mit Datenbank (siehe docs/technisches-konzept.md, Regel 10):
 *
 *   SELECT * FROM fahrzeuge ORDER BY kennzeichen
 *   SELECT ... FROM buchungen
 *    WHERE status IN ('offen', 'genehmigt', 'unterwegs') AND ende >= CURDATE()
 *   SELECT ... FROM buchungen
 *    WHERE status = 'abgeschlossen' AND (schaden = 1 OR bemerkung <> '')
 *    ORDER BY zurueckgegeben_am DESC
 *
 * Beim Speichern:
 *   UPDATE fahrzeuge SET status = :status WHERE id = :id
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Fahrzeuge verwalten';

// Gespeicherter Fahrzeugstatus => Beschriftung. Das Kürzel dient zugleich als
// CSS-Klasse (.badge--wartung usw.).
$statusText = [
    'verfuegbar' => 'verfügbar',
    'wartung'    => 'in Wartung',
];

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
// Jahr-Monat, null bei Fahrzeugen ohne TÜV (Fahrrad).
$fahrzeuge = [
    1 => ['kennzeichen' => 'M-HS 101',  'hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'status' => 'verfuegbar', 'tuev' => '2027-03'],
    2 => ['kennzeichen' => 'M-HS 102',  'hersteller' => 'Škoda',          'modell' => 'Octavia Combi',  'status' => 'verfuegbar', 'tuev' => '2026-05'],
    3 => ['kennzeichen' => 'M-HS 201',  'hersteller' => 'Ford',           'modell' => 'Transit',        'status' => 'verfuegbar', 'tuev' => '2026-11'],
    4 => ['kennzeichen' => 'M-HS 202',  'hersteller' => 'Mercedes-Benz',  'modell' => 'Sprinter',       'status' => 'wartung',    'tuev' => '2026-10'],
    5 => ['kennzeichen' => 'M-HS 301E', 'hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'status' => 'verfuegbar', 'tuev' => '2027-08'],
    6 => ['kennzeichen' => 'M-HS 302E', 'hersteller' => 'Tesla',          'modell' => 'Model 3',        'status' => 'verfuegbar', 'tuev' => '2027-02'],
    7 => ['kennzeichen' => 'M-HS 401',  'hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'status' => 'verfuegbar', 'tuev' => '2027-06'],
    8 => ['kennzeichen' => 'Rad 1',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'status' => 'verfuegbar', 'tuev' => null],
    9 => ['kennzeichen' => 'Rad 2',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'status' => 'verfuegbar', 'tuev' => null],
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

// Meldungen aus Rückgaben (Schaden oder Bemerkung), neueste zuerst. Dieselben
// Bemerkungen wie in den Fahrten von fahrzeug.php.
$meldungen = [
    ['datum' => '30.09.2026', 'fahrzeug_id' => 8, 'fahrer' => 'Lucie Schneider', 'schaden' => false, 'bemerkung' => 'Akku nach Rückkehr wieder angeschlossen.'],
    ['datum' => '27.09.2026', 'fahrzeug_id' => 4, 'fahrer' => 'Larissa Wagner',  'schaden' => true,  'bemerkung' => 'Delle an der Schiebetür rechts, Tür schließt schwer.'],
    ['datum' => '24.09.2026', 'fahrzeug_id' => 1, 'fahrer' => 'Larissa Wagner',  'schaden' => false, 'bemerkung' => 'Klappergeräusch hinten rechts bei Tempo über 100.'],
    ['datum' => '22.09.2026', 'fahrzeug_id' => 3, 'fahrer' => 'Kenneth Sander',  'schaden' => false, 'bemerkung' => 'Ladefläche verschmutzt, Spanngurt fehlt.'],
    ['datum' => '18.09.2026', 'fahrzeug_id' => 1, 'fahrer' => 'Finn Clausen',    'schaden' => false, 'bemerkung' => 'Innenraum könnte mal gereinigt werden.'],
];

// --- Buchungen je Fahrzeug --------------------------------------------------

$buchungenJeFahrzeug = [];

foreach ($beispielBuchungen as $buchung) {
    $buchung['start'] = $heute->modify($buchung['von'] . ' day');
    $buchung['ende']  = $heute->modify($buchung['bis'] . ' day');
    $buchungenJeFahrzeug[$buchung['fahrzeug_id']][] = $buchung;
}

// --- Formular verarbeiten ---------------------------------------------------
// Läuft vor header.php, damit später eine Weiterleitung möglich ist.

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
}
unset($fahrzeug);

$anzahlSchaeden = count(array_filter($meldungen, fn (array $m): bool => $m['schaden']));

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
    Fahrzeuge für Wartung oder Reparatur sperren und wieder freigeben.
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten. Statusänderungen werden geprüft und angezeigt, aber noch
    nicht gespeichert.
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
    <h2>Fahrzeuge</h2>

    <table class="table">
        <thead>
            <tr>
                <th>Fahrzeug</th>
                <th>Status</th>
                <th>Nächster TÜV</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fahrzeuge as $id => $fahrzeug): ?>
                <?php $buchungen = $buchungenJeFahrzeug[$id] ?? []; ?>
                <tr>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $id) ?>"><?= e($fahrzeug['name']) ?></a>
                    </td>
                    <td>
                        <span class="badge badge--<?= e($fahrzeug['status']) ?>">
                            <?= e($statusText[$fahrzeug['status']] ?? $fahrzeug['status']) ?>
                        </span>
                        <?php if ($fahrzeug['unterwegs']): ?>
                            <span class="badge badge--unterwegs">unterwegs</span>
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

                                    <form class="klappaktion__form" method="post" action="<?= url('fahrzeuge-verwalten.php') ?>">
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
                                <form method="post" action="<?= url('fahrzeuge-verwalten.php') ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $id) ?>">
                                    <input type="hidden" name="aktion" value="freigeben">
                                    <button class="button button--klein" type="submit">Freigeben</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="section">
    <h2>Gemeldete Schäden und Bemerkungen</h2>

    <p class="lead">
        Aus den Rückgaben der Fahrer, neueste zuerst.
        <?= $anzahlSchaeden ?> <?= $anzahlSchaeden === 1 ? 'Schaden' : 'Schäden' ?> gemeldet.
    </p>

    <table class="table">
        <thead>
            <tr>
                <th>Datum</th>
                <th>Fahrzeug</th>
                <th>Fahrer</th>
                <th>Art</th>
                <th>Bemerkung</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($meldungen as $meldung): ?>
                <tr>
                    <td><?= e($meldung['datum']) ?></td>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $meldung['fahrzeug_id']) ?>"><?= e($fahrzeuge[$meldung['fahrzeug_id']]['name']) ?></a>
                    </td>
                    <td><?= e($meldung['fahrer']) ?></td>
                    <td>
                        <?php if ($meldung['schaden']): ?>
                            <span class="badge badge--abgelehnt">Schaden</span>
                        <?php else: ?>
                            <span class="badge">Bemerkung</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($meldung['bemerkung']) ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($meldungen === []): ?>
                <tr>
                    <td colspan="5" class="table__empty">Keine Meldungen.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
