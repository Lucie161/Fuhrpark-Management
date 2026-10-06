<?php
/**
 * Überblick nach der Anmeldung (Anwendungsfall 8), je Rolle.
 *
 * Beide Rollen: ganz oben ein rotes Banner, sobald eine Rückgabe überfällig
 * ist. Mitarbeiter: Banner nur für die eigenen Fahrten mit „Jetzt
 * zurückgeben“, darunter aktive Buchung mit „Fahrt beginnen“, laufende Fahrt,
 * nächste Fahrten und offene Anträge. Fuhrparkleiter: Banner mit der Anzahl,
 * die Liste hinter „Details“; dann „Zu erledigen“ mit den offenen Anträgen
 * zum direkten Entscheiden und den gemeldeten Schäden, zuletzt „Fuhrpark“
 * mit den Fahrzeugen nach Status. Er bucht nicht selbst und hat daher keine
 * eigenen Fahrten (siehe docs/technisches-konzept.md, Regeln 5 und 8).
 *
 * Prototyp: Alle Angaben sind feste Beispieldaten, dieselben wie in
 * meine-buchungen.php, fahrzeuge.php und genehmigungen.php. Mit
 * Datenbank:
 *
 *   Mitarbeiter:
 *   SELECT ... FROM buchungen WHERE (antragsteller_id = :ich OR fahrer_id = :ich)
 *      AND status IN ('offen', 'genehmigt', 'unterwegs')
 *   Fuhrparkleiter:
 *   SELECT status, COUNT(*) FROM fahrzeuge GROUP BY status
 *   SELECT ... FROM buchungen WHERE status = 'unterwegs'
 *   offene Anträge wie in genehmigungen.php
 *   SELECT ... FROM buchungen b JOIN fahrzeuge f ON f.id = b.fahrzeug_id
 *    WHERE b.schaden = 1 AND f.status = 'wartung'
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Übersicht';

// Angemeldeter Nutzer. Kommt später aus der Session.
$ich = 'Lucie Schneider';

// Stand einer Buchung => Beschriftung (wie in meine-buchungen.php).
$statusText = [
    'offen'     => 'beantragt',
    'genehmigt' => 'genehmigt',
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

$heute = new DateTimeImmutable('today');

// --- Meine Buchungen (nur Mitarbeiter) --------------------------------------
// Die aktuellen aus meine-buchungen.php. Tage relativ zu heute.

$meineBuchungen = [
    ['id' => 11, 'fahrzeug_id' => 8, 'fahrzeug' => 'Riese & Müller Charger4 (Rad 1)',      'von' => 0,  'bis' => 0,  'zweck' => 'aufmass',      'status' => 'genehmigt', 'fahrer' => $ich],
    ['id' => 12, 'fahrzeug_id' => 7, 'fahrzeug' => 'Vespa Primavera 125 (M-HS 401)',       'von' => -2, 'bis' => -1, 'zweck' => 'kundentermin', 'status' => 'unterwegs', 'fahrer' => $ich],
    ['id' => 13, 'fahrzeug_id' => 5, 'fahrzeug' => 'Volkswagen ID.3 (M-HS 301E)',          'von' => 2,  'bis' => 2,  'zweck' => 'kundentermin', 'status' => 'genehmigt', 'fahrer' => $ich],
    ['id' => 14, 'fahrzeug_id' => 2, 'fahrzeug' => 'Škoda Octavia Combi (M-HS 102)',       'von' => 5,  'bis' => 6,  'zweck' => 'montage',      'status' => 'offen',     'fahrer' => $ich],
    ['id' => 15, 'fahrzeug_id' => 1, 'fahrzeug' => 'Volkswagen Passat Variant (M-HS 101)', 'von' => 10, 'bis' => 11, 'zweck' => 'lieferant',    'status' => 'offen',     'fahrer' => $ich],
];

// Aktiv (Fahrt kann beginnen): genehmigt, heute im Zeitraum, ich bin Fahrer.
// Laufend: unterwegs. Demnächst: alles übrige Offene und Genehmigte.
$aktive     = [];
$laufende   = [];
$demnaechst = [];

foreach ($meineBuchungen as $buchung) {
    $buchung['start'] = $heute->modify($buchung['von'] . ' day');
    $buchung['ende']  = $heute->modify($buchung['bis'] . ' day');

    if ($buchung['status'] === 'unterwegs') {
        $buchung['ueberfaellig'] = $buchung['ende'] < $heute;
        $laufende[] = $buchung;
    } elseif ($buchung['status'] === 'genehmigt' && $buchung['fahrer'] === $ich
        && $buchung['start'] <= $heute && $heute <= $buchung['ende']) {
        $aktive[] = $buchung;
    } else {
        $demnaechst[] = $buchung;
    }
}

usort($demnaechst, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

// --- Fuhrpark (nur Fuhrparkleiter) ------------------------------------------

$fuhrpark = null;

if (ist_fuhrparkleiter()) {
    // Gespeicherter Status je Fahrzeug, wie in fahrzeuge.php.
    $fahrzeuge = [
        1 => ['name' => 'Volkswagen Passat Variant (M-HS 101)',   'status' => 'verfuegbar'],
        2 => ['name' => 'Škoda Octavia Combi (M-HS 102)',         'status' => 'verfuegbar'],
        3 => ['name' => 'Ford Transit (M-HS 201)',                'status' => 'verfuegbar'],
        4 => ['name' => 'Mercedes-Benz Sprinter (M-HS 202)',      'status' => 'wartung'],
        5 => ['name' => 'Volkswagen ID.3 (M-HS 301E)',            'status' => 'verfuegbar'],
        6 => ['name' => 'Tesla Model 3 (M-HS 302E)',              'status' => 'verfuegbar'],
        7 => ['name' => 'Vespa Primavera 125 (M-HS 401)',         'status' => 'verfuegbar'],
        8 => ['name' => 'Riese & Müller Charger4 (Rad 1)',        'status' => 'verfuegbar'],
        9 => ['name' => 'Riese & Müller Charger4 (Rad 2)',        'status' => 'verfuegbar'],
    ];

    // Laufende Fahrten aller Fahrer (Status „unterwegs“).
    $unterwegs = [
        ['fahrzeug_id' => 3, 'fahrer' => 'Kenneth Sander', 'bis' => 2],
        ['fahrzeug_id' => 7, 'fahrer' => $ich,             'bis' => -1],
    ];

    // Offene Anträge und vergebene Zeiträume wie in genehmigungen.php.
    $antraege = [
        19 => ['fahrzeug_id' => 4, 'antragsteller' => 'Kenneth Sander',  'fahrer' => 'Kenneth Sander',  'von' => 3,  'bis' => 4,  'zweck' => 'materialtransport'],
        14 => ['fahrzeug_id' => 2, 'antragsteller' => 'Kenneth Sander',  'fahrer' => 'Lucie Schneider', 'von' => 5,  'bis' => 6,  'zweck' => 'montage'],
        20 => ['fahrzeug_id' => 6, 'antragsteller' => 'Kenneth Sander',  'fahrer' => 'Kenneth Sander',  'von' => 8,  'bis' => 9,  'zweck' => 'aufmass'],
        15 => ['fahrzeug_id' => 1, 'antragsteller' => 'Lucie Schneider', 'fahrer' => 'Lucie Schneider', 'von' => 10, 'bis' => 11, 'zweck' => 'lieferant'],
    ];

    $vergeben = [
        ['fahrzeug_id' => 1, 'von' => 1,  'bis' => 1],
        ['fahrzeug_id' => 1, 'von' => 4,  'bis' => 6],
        ['fahrzeug_id' => 3, 'von' => 0,  'bis' => 2],
        ['fahrzeug_id' => 3, 'von' => 7,  'bis' => 8],
        ['fahrzeug_id' => 5, 'von' => 2,  'bis' => 2],
        ['fahrzeug_id' => 7, 'von' => -2, 'bis' => -1],
        ['fahrzeug_id' => 8, 'von' => 0,  'bis' => 0],
        ['fahrzeug_id' => 9, 'von' => 3,  'bis' => 4],
    ];

    // Schäden an Fahrzeugen in Wartung, wie in schaeden.php.
    $schaeden = [
        ['fahrzeug_id' => 4, 'datum' => '27.09.2026'],
    ];

    // Anträge aufbereiten wie in genehmigungen.php. 'hindernis': warum
    // Genehmigen nicht geht, sonst null.
    foreach ($antraege as &$antrag) {
        $antrag['start'] = $heute->modify($antrag['von'] . ' day');
        $antrag['ende']  = $heute->modify($antrag['bis'] . ' day');

        $antrag['hindernis'] = null;

        if ($fahrzeuge[$antrag['fahrzeug_id']]['status'] === 'wartung') {
            $antrag['hindernis'] = 'Das Fahrzeug ist in Wartung.';
        } else {
            foreach ($vergeben as $belegung) {
                if ($belegung['fahrzeug_id'] === $antrag['fahrzeug_id']
                    && $belegung['von'] <= $antrag['bis'] && $belegung['bis'] >= $antrag['von']) {
                    $antrag['hindernis'] = 'Das Fahrzeug ist im Zeitraum bereits vergeben.';
                    break;
                }
            }
        }
    }
    unset($antrag);

    $ueberfaellig = [];

    foreach ($unterwegs as $fahrt) {
        $fahrt['ende'] = $heute->modify($fahrt['bis'] . ' day');

        if ($fahrt['ende'] < $heute) {
            $fahrt['tage'] = $fahrt['ende']->diff($heute)->days;
            $ueberfaellig[] = $fahrt;
        }
    }

    // „Unterwegs“ ist kein gespeicherter Status, sondern ergibt sich aus den
    // laufenden Fahrten (siehe docs/technisches-konzept.md).
    $anzahlWartung   = count(array_filter($fahrzeuge, fn (array $f): bool => $f['status'] === 'wartung'));
    $anzahlUnterwegs = count($unterwegs);

    $fuhrpark = [
        'status' => [
            ['wert' => count($fahrzeuge) - $anzahlWartung - $anzahlUnterwegs, 'label' => 'Fahrzeuge frei'],
            ['wert' => $anzahlUnterwegs, 'label' => 'unterwegs'],
            ['wert' => $anzahlWartung,   'label' => 'in Wartung'],
        ],
        'antraege'     => $antraege,
        'schaeden'     => count($schaeden),
        'ueberfaellig' => $ueberfaellig,
        'fahrzeuge'    => $fahrzeuge,
    ];

    // Für das Formular aus includes/antrag-entscheidung.php: nach der
    // Entscheidung zurück zur Übersicht.
    $zurueck = 'index';
    $offenerAntrag = null;
    $kommentar = '';
}

// Eigene überfällige Rückgaben (nur Mitarbeiter), für das rote Banner.
$meineUeberfaelligen = array_filter($laufende, fn (array $b): bool => $b['ueberfaellig']);

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

<?php if ($fuhrpark !== null && $fuhrpark['ueberfaellig'] !== []): ?>
    <?php $anzahl = count($fuhrpark['ueberfaellig']); ?>
    <!-- Rotes Banner nur bei überfälligen Rückgaben; die Liste erst hinter
         „Details“, ohne JavaScript. -->
    <div class="banner">
        <p class="banner__text">
            <strong><?= e((string) $anzahl) ?> <?= $anzahl === 1 ? 'Rückgabe ist' : 'Rückgaben sind' ?> überfällig.</strong>
            Bitte mit den Fahrern klären.
        </p>

        <details class="banner__details">
            <summary>Details</summary>

            <ul class="banner__liste">
                <?php foreach ($fuhrpark['ueberfaellig'] as $fahrt): ?>
                    <li>
                        <a href="<?= url('fahrzeug.php?id=' . $fahrt['fahrzeug_id']) ?>"><?= e($fuhrpark['fahrzeuge'][$fahrt['fahrzeug_id']]['name']) ?></a>,
                        <?= e($fahrt['fahrer']) ?>, fällig am <?= e($fahrt['ende']->format('d.m.Y')) ?>,
                        seit <?= e((string) $fahrt['tage']) ?> <?= $fahrt['tage'] === 1 ? 'Tag' : 'Tagen' ?> überfällig
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    </div>
<?php endif; ?>

<?php if ($fuhrpark === null): ?>
    <?php foreach ($meineUeberfaelligen as $buchung): ?>
        <div class="banner">
            <p class="banner__text">
                <strong>Ihre Rückgabe ist überfällig.</strong>
                <?= e($buchung['fahrzeug']) ?> war am <?= e($buchung['ende']->format('d.m.Y')) ?> fällig.
            </p>
            <a class="button button--klein banner__knopf" href="<?= url('rueckgabe.php?buchung=' . $buchung['id']) ?>">Jetzt zurückgeben</a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<p class="lead">
    <?php if ($fuhrpark !== null): ?>
        Der Fuhrpark auf einen Blick: Fahrzeuge und was zu erledigen ist.
    <?php else: ?>
        Ihre Fahrten und Anträge auf einen Blick.
    <?php endif; ?>
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten. Angemeldet als <?= e($ich) ?>,
    Rolle <?= e(ist_fuhrparkleiter() ? 'Fuhrparkleiter' : 'Mitarbeiter') ?>
    (unter der Navigation umschaltbar).
</p>

<?php if ($fuhrpark !== null): ?>

    <section class="section">
        <h2>Zu erledigen</h2>

        <?php $anzahl = count($fuhrpark['antraege']); ?>
        <h3>
            <?= $anzahl === 0 ? 'Keine offenen Anträge' : e((string) $anzahl) . ($anzahl === 1 ? ' offener Antrag' : ' offene Anträge') ?>
        </h3>

        <?php if ($anzahl > 0): ?>
            <!-- Dasselbe Formular wie in genehmigungen.php (Regel 8); es
                 schickt dorthin und kehrt danach hierher zurück. -->
            <table class="table">
                <thead>
                    <tr>
                        <th>Antragsteller</th>
                        <th>Fahrzeug</th>
                        <th>Zeitraum</th>
                        <th>Zweck</th>
                        <th>Entscheidung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuhrpark['antraege'] as $id => $antrag): ?>
                        <tr>
                            <td>
                                <?= e($antrag['antragsteller']) ?>
                                <?php if ($antrag['fahrer'] !== $antrag['antragsteller']): ?>
                                    <span class="table__zusatz">für <?= e($antrag['fahrer']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= url('fahrzeug.php?id=' . $antrag['fahrzeug_id']) ?>"><?= e($fuhrpark['fahrzeuge'][$antrag['fahrzeug_id']]['name']) ?></a>
                            </td>
                            <td><?= e(zeitraum($antrag)) ?></td>
                            <td><?= e($zweckText[$antrag['zweck']] ?? $antrag['zweck']) ?></td>
                            <td>
                                <?php require __DIR__ . '/includes/antrag-entscheidung.php'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <a href="<?= url('genehmigungen.php') ?>">Alle Anträge mit Antragsdatum und Personenzahl</a>
            </p>
        <?php endif; ?>

        <div class="cards">
            <div class="card<?= $fuhrpark['schaeden'] > 0 ? ' card--offen' : '' ?>">
                <p class="card__value"><?= e((string) $fuhrpark['schaeden']) ?></p>
                <p class="card__label"><?= $fuhrpark['schaeden'] === 1 ? 'Schaden gemeldet' : 'Schäden gemeldet' ?></p>
                <?php if ($fuhrpark['schaeden'] > 0): ?>
                    <a class="card__link" href="<?= url('schaeden.php') ?>">Zu den Schäden</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section">
        <h2>Fuhrpark</h2>

        <div class="cards">
            <?php foreach ($fuhrpark['status'] as $kennzahl): ?>
                <div class="card">
                    <p class="card__value"><?= e((string) $kennzahl['wert']) ?></p>
                    <p class="card__label"><?= e($kennzahl['label']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <p>
            <a href="<?= url('fahrzeuge.php') ?>">Alle Fahrzeuge</a> &middot;
            <a href="<?= url('kalender.php') ?>">Kalender</a>
        </p>
    </section>

<?php else: ?>

    <section class="section">
        <h2>Meine Fahrten</h2>

        <?php foreach ($aktive as $buchung): ?>
            <div class="aktuell">
                <p class="aktuell__text">
                    <strong>Ihre Fahrt mit <?= e($buchung['fahrzeug']) ?> kann beginnen.</strong>
                    <span class="table__zusatz">
                        <?= e(zeitraum($buchung)) ?>, <?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?>
                    </span>
                </p>

                <!-- Dasselbe Formular wie in meine-buchungen.php (Regel 5). -->
                <details class="klappaktion">
                    <summary class="button button--klein">Fahrt beginnen</summary>

                    <div class="klappaktion__form">
                        <label class="form__label">
                            Vorhandene Schäden (optional)
                            <textarea class="form__input" rows="2"
                                      placeholder="z. B. Kratzer am Kotflügel links"></textarea>
                        </label>
                        <button class="button button--klein" type="button">Fahrzeug übernommen, Fahrt beginnen</button>
                    </div>
                </details>
            </div>
        <?php endforeach; ?>

        <?php foreach ($laufende as $buchung): ?>
            <div class="aktuell<?= $buchung['ueberfaellig'] ? ' aktuell--ueberfaellig' : '' ?>">
                <p class="aktuell__text">
                    <strong>Sie sind unterwegs mit <?= e($buchung['fahrzeug']) ?>.</strong>
                    <span class="table__zusatz">
                        Rückgabe <?= $buchung['ueberfaellig'] ? 'war' : 'ist' ?> fällig am
                        <?= e($buchung['ende']->format('d.m.Y')) ?>.
                    </span>
                    <?php if ($buchung['ueberfaellig']): ?>
                        <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                    <?php endif; ?>
                </p>

                <a class="button button--klein" href="<?= url('rueckgabe.php?buchung=' . $buchung['id']) ?>">Zurückgeben</a>
            </div>
        <?php endforeach; ?>

        <table class="table">
            <thead>
                <tr>
                    <th>Zeitraum</th>
                    <th>Fahrzeug</th>
                    <th>Zweck</th>
                    <th>Stand</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($demnaechst as $buchung): ?>
                    <tr>
                        <td><?= e(zeitraum($buchung)) ?></td>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $buchung['fahrzeug_id']) ?>"><?= e($buchung['fahrzeug']) ?></a>
                        </td>
                        <td><?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?></td>
                        <td>
                            <span class="badge badge--<?= e($buchung['status']) ?>">
                                <?= e($statusText[$buchung['status']] ?? $buchung['status']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($demnaechst === []): ?>
                    <tr>
                        <td colspan="4" class="table__empty">Keine weiteren Fahrten oder offenen Anträge.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="aktionen section">
            <a class="button" href="<?= url('buchen.php') ?>">Fahrzeug buchen</a>
            <a class="button button--zweitrangig" href="<?= url('meine-buchungen.php') ?>">Alle meine Buchungen</a>
        </div>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
