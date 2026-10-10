<?php
/**
 * Meine Buchungen (Anwendungsfälle 3, 4 und 5), nur für Mitarbeiter.
 *
 * Zeigt die aktuellen Buchungen (beantragt, genehmigt, unterwegs), die ich
 * beantragt habe oder bei denen ich Fahrer bin, die nächste zuerst. Von hier
 * aus wird storniert und die Fahrt begonnen; „Fahrt beginnen“ hat keine
 * eigene Seite (siehe docs/technisches-konzept.md, Regel 5). Frühere und
 * abgesagte Buchungen stehen in fruehere-buchungen.php, verlinkt unter der
 * Tabelle.
 *
 * Prototyp: Die Buchungen kommen aus includes/beispieldaten.php, die
 * Schaltflächen lösen noch nichts aus. Mit Datenbank:
 *
 *   SELECT b.*, f.hersteller, f.modell, f.kennzeichen, ...
 *     FROM buchungen b JOIN fahrzeuge f ON f.id = b.fahrzeug_id
 *    WHERE (b.antragsteller_id = :ich OR b.fahrer_id = :ich)
 *      AND b.status IN ('offen', 'genehmigt', 'unterwegs')
 *    ORDER BY b.start
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('mitarbeiter');

$pageTitle = 'Meine Buchungen';

$ich = aktueller_nutzer();

// Statuskürzel einer Buchung => Beschriftung. Das Kürzel dient zugleich als
// CSS-Klasse (.badge--offen usw.).
$statusText = [
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

$heute = new DateTimeImmutable('today');

// --- Buchungen aufbereiten --------------------------------------------------
// Je Buchung wird festgehalten, welche Aktionen möglich sind. Die Regeln
// stehen in docs/technisches-konzept.md (Regeln 4 und 5).

$aktuelle = [];

foreach (beispiel_buchungen() as $buchung) {
    if (($buchung['antragsteller_id'] !== $ich && $buchung['fahrer_id'] !== $ich)
        || !isset($statusText[$buchung['status']])) {
        continue;
    }

    // Aktiv: genehmigt, heute liegt im Zeitraum, ich bin der Fahrer. Ob die
    // Fahrt wirklich beginnen kann, sagt 'sperre' (überfällige Rückgabe).
    $buchung['kann_beginnen'] = $buchung['status'] === 'genehmigt'
        && $buchung['start'] <= $heute && $heute <= $buchung['ende']
        && $buchung['fahrer_id'] === $ich;
    $buchung['sperre'] = $buchung['kann_beginnen'] ? sperre_fahrtbeginn($ich, $buchung['fahrzeug_id']) : null;

    // Stornieren nur, solange die Fahrt nicht begonnen hat; Antragsteller
    // und Fahrer dürfen beide.
    $buchung['kann_stornieren'] = in_array($buchung['status'], ['offen', 'genehmigt'], true);

    $buchung['ueberfaellig'] = $buchung['status'] === 'unterwegs' && $buchung['ende'] < $heute;

    $aktuelle[] = $buchung;
}

// Nächste zuerst.
usort($aktuelle, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Ihre aktuellen Buchungen: beantragt, genehmigt oder unterwegs, die nächste zuerst. Dazu
    gehören auch Buchungen, die jemand anderes für Sie als Fahrer angelegt hat.
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
                    <td><?= e(zeitraum_text($buchung['start'], $buchung['ende'])) ?></td>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $buchung['fahrzeug_id']) ?>"><?= e(fahrzeug_name($buchung['fahrzeug_id'])) ?></a>
                        <?php if ($buchung['antragsteller_id'] !== $ich): ?>
                            <span class="table__zusatz">gebucht von <?= e(nutzer_name($buchung['antragsteller_id'])) ?></span>
                        <?php endif; ?>
                        <?php if ($buchung['fahrer_id'] !== $ich): ?>
                            <span class="table__zusatz">Fahrer: <?= e(nutzer_name($buchung['fahrer_id'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($zweckText[$buchung['zweck']] ?? $buchung['zweck']) ?></td>
                    <td class="table__num"><?= e((string) $buchung['personenanzahl']) ?></td>
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
                                <?php require __DIR__ . '/includes/fahrt-beginnen.php'; ?>
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

    <div class="aktionen section">
        <a class="button" href="<?= url('buchen.php') ?>">Neues Fahrzeug buchen</a>
        <a class="button button--zweitrangig" href="<?= url('fruehere-buchungen.php') ?>">Frühere und abgesagte Buchungen</a>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
