<?php
/**
 * Meine Buchungen (Anwendungsfälle 3, 4 und 5).
 *
 * Zeigt die Buchungen, die ich beantragt habe oder bei denen ich Fahrer bin.
 * Von hier aus wird storniert und die Fahrt begonnen; „Fahrt beginnen“ hat
 * keine eigene Seite (siehe docs/technisches-konzept.md, Regel 5).
 *
 * Prototyp ohne Funktion: Buchungen sind feste Beispieldaten, die
 * Schaltflächen lösen noch nichts aus. Mit Datenbank wird $buchungen ersetzt
 * durch:
 *
 *   SELECT b.*, f.hersteller, f.modell, f.kennzeichen, ...
 *     FROM buchungen b JOIN fahrzeuge f ON f.id = b.fahrzeug_id
 *    WHERE b.antragsteller_id = :ich OR b.fahrer_id = :ich
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Meine Buchungen';

// Angemeldeter Nutzer. Kommt später aus der Session.
$ich = 'Lucie Schneider';

// Statuskürzel einer Buchung => Beschriftung. Das Kürzel dient zugleich als
// CSS-Klasse (.badge--offen usw.).
$statusText = [
    'offen'         => 'beantragt',
    'genehmigt'     => 'genehmigt',
    'abgelehnt'     => 'abgelehnt',
    'storniert'     => 'storniert',
    'unterwegs'     => 'unterwegs',
    'abgeschlossen' => 'abgeschlossen',
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

// Beispielbuchungen. Tage relativ zu heute, damit der Prototyp immer alle
// Zustände zeigt.
$heute = new DateTimeImmutable('today');

$beispiele = [
    ['id' => 11, 'fahrzeug_id' => 8, 'fahrzeug' => 'Riese & Müller Charger4 (Rad 1)',     'von' => 0,  'bis' => 0,  'zweck' => 'aufmass',           'personen' => 1, 'status' => 'genehmigt',     'antragsteller' => $ich,             'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 12, 'fahrzeug_id' => 7, 'fahrzeug' => 'Vespa Primavera 125 (M-HS 401)',      'von' => -2, 'bis' => -1, 'zweck' => 'kundentermin',      'personen' => 1, 'status' => 'unterwegs',     'antragsteller' => $ich,             'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 13, 'fahrzeug_id' => 5, 'fahrzeug' => 'Volkswagen ID.3 (M-HS 301E)',         'von' => 2,  'bis' => 2,  'zweck' => 'kundentermin',      'personen' => 1, 'status' => 'genehmigt',     'antragsteller' => $ich,             'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 14, 'fahrzeug_id' => 2, 'fahrzeug' => 'Škoda Octavia Combi (M-HS 102)',      'von' => 5,  'bis' => 6,  'zweck' => 'montage',           'personen' => 3, 'status' => 'offen',         'antragsteller' => 'Kenneth Sander', 'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 15, 'fahrzeug_id' => 1, 'fahrzeug' => 'Volkswagen Passat Variant (M-HS 101)', 'von' => 10, 'bis' => 11, 'zweck' => 'lieferant',         'personen' => 2, 'status' => 'offen',         'antragsteller' => $ich,             'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 16, 'fahrzeug_id' => 6, 'fahrzeug' => 'Tesla Model 3 (M-HS 302E)',           'von' => 3,  'bis' => 3,  'zweck' => 'kundentermin',      'personen' => 1, 'status' => 'abgelehnt',     'antragsteller' => $ich,             'fahrer' => $ich, 'kommentar' => 'Für Einzeltermine bitte den ID.3 oder ein E-Fahrrad nutzen.'],
    ['id' => 17, 'fahrzeug_id' => 3, 'fahrzeug' => 'Ford Transit (M-HS 201)',             'von' => -6, 'bis' => -6, 'zweck' => 'materialtransport', 'personen' => 2, 'status' => 'storniert',     'antragsteller' => $ich,             'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 18, 'fahrzeug_id' => 8, 'fahrzeug' => 'Riese & Müller Charger4 (Rad 1)',     'von' => -2, 'bis' => -2, 'zweck' => 'aufmass',           'personen' => 1, 'status' => 'abgeschlossen', 'antragsteller' => $ich,             'fahrer' => $ich, 'kommentar' => ''],
];

// --- Buchungen aufbereiten --------------------------------------------------
// Je Buchung wird festgehalten, welche Aktionen möglich sind. Die Regeln
// stehen in docs/technisches-konzept.md (Regeln 4 und 5).

$aktuelle = [];
$fruehere = [];

foreach ($beispiele as $buchung) {
    $buchung['start'] = $heute->modify($buchung['von'] . ' day');
    $buchung['ende']  = $heute->modify($buchung['bis'] . ' day');

    // Aktiv: genehmigt, heute liegt im Zeitraum, ich bin der Fahrer.
    $buchung['kann_beginnen'] = $buchung['status'] === 'genehmigt'
        && $buchung['start'] <= $heute && $heute <= $buchung['ende']
        && $buchung['fahrer'] === $ich;

    // Stornieren nur, solange die Fahrt nicht begonnen hat.
    $buchung['kann_stornieren'] = in_array($buchung['status'], ['offen', 'genehmigt'], true);

    $buchung['ueberfaellig'] = $buchung['status'] === 'unterwegs' && $buchung['ende'] < $heute;

    if (in_array($buchung['status'], ['offen', 'genehmigt', 'unterwegs'], true)) {
        $aktuelle[] = $buchung;
    } else {
        $fruehere[] = $buchung;
    }
}

// Aktuelle: nächste zuerst. Frühere: neueste zuerst.
usort($aktuelle, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
usort($fruehere, fn (array $a, array $b): int => $b['start'] <=> $a['start']);

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
    Buchungen, die Sie beantragt haben oder bei denen Sie als Fahrer eingetragen sind.
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten, noch ohne Funktion. Angemeldet als <?= e($ich) ?>.
</p>

<section class="section">
    <h2>Aktuelle Buchungen</h2>

    <table class="table">
        <thead>
            <tr>
                <th>Zeitraum</th>
                <th>Fahrzeug</th>
                <th>Zweck</th>
                <th class="table__num">Personen</th>
                <th>Stand</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($aktuelle as $buchung): ?>
                <tr>
                    <td><?= e(zeitraum($buchung)) ?></td>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $buchung['fahrzeug_id']) ?>"><?= e($buchung['fahrzeug']) ?></a>
                        <?php if ($buchung['antragsteller'] !== $ich): ?>
                            <span class="table__zusatz">gebucht von <?= e($buchung['antragsteller']) ?></span>
                        <?php endif; ?>
                        <?php if ($buchung['fahrer'] !== $ich): ?>
                            <span class="table__zusatz">Fahrer: <?= e($buchung['fahrer']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?></td>
                    <td class="table__num"><?= e((string) $buchung['personen']) ?></td>
                    <td>
                        <span class="badge badge--<?= e($buchung['status']) ?>">
                            <?= e($statusText[$buchung['status']] ?? $buchung['status']) ?>
                        </span>
                        <?php if ($buchung['ueberfaellig']): ?>
                            <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="aktionen">
                            <?php if ($buchung['kann_beginnen']): ?>
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
                            <?php endif; ?>

                            <?php if ($buchung['status'] === 'unterwegs'): ?>
                                <a class="button button--klein"
                                   href="<?= url('rueckgabe.php?buchung=' . $buchung['id']) ?>">Zurückgeben</a>
                            <?php endif; ?>

                            <?php if ($buchung['kann_stornieren']): ?>
                                <details class="klappaktion">
                                    <summary class="button button--klein button--gefahr">Stornieren</summary>

                                    <div class="klappaktion__form">
                                        <p class="klappaktion__frage">
                                            Buchung wirklich stornieren? Das Fahrzeug wird für den
                                            Zeitraum wieder freigegeben.
                                        </p>
                                        <button class="button button--klein button--gefahr" type="button">Ja, stornieren</button>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($aktuelle === []): ?>
                <tr>
                    <td colspan="6" class="table__empty">Keine aktuellen Buchungen.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <p><a class="button" href="<?= url('buchen.php') ?>">Neues Fahrzeug buchen</a></p>
</section>

<section class="section">
    <h2>Frühere und abgesagte Buchungen</h2>

    <table class="table">
        <thead>
            <tr>
                <th>Zeitraum</th>
                <th>Fahrzeug</th>
                <th>Zweck</th>
                <th>Stand</th>
                <th>Begründung</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fruehere as $buchung): ?>
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
                    <td><?= $buchung['kommentar'] !== '' ? e($buchung['kommentar']) : '&ndash;' ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($fruehere === []): ?>
                <tr>
                    <td colspan="5" class="table__empty">Keine früheren Buchungen.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
