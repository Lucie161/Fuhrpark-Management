<?php
/**
 * Frühere und abgesagte Buchungen (Anwendungsfall 3), nur für Mitarbeiter.
 *
 * Abgeschlossene, stornierte und abgelehnte Buchungen, die ich beantragt
 * habe oder bei denen ich Fahrer bin, die neueste zuerst. Bei einer Ablehnung
 * steht die Begründung des Fuhrparkleiters dabei. Die Seite steht nicht in
 * der Navigation, sondern ist unter der Tabelle in meine-buchungen.php
 * verlinkt (siehe docs/technisches-konzept.md, Regel 3).
 *
 * Prototyp: Die Buchungen kommen aus includes/beispieldaten.php; die
 * abgeschlossenen sind dieselben Fahrten wie im Fahrtenbuch (fahrzeug.php,
 * historie.php). Mit Datenbank:
 *
 *   SELECT b.*, f.hersteller, f.modell, f.kennzeichen, ...
 *     FROM buchungen b JOIN fahrzeuge f ON f.id = b.fahrzeug_id
 *    WHERE (b.antragsteller_id = :ich OR b.fahrer_id = :ich)
 *      AND b.status IN ('abgeschlossen', 'storniert', 'abgelehnt')
 *    ORDER BY b.start DESC
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle('mitarbeiter');

$pageTitle = 'Frühere Buchungen';

$ich = aktueller_nutzer();

// Statuskürzel einer Buchung => Beschriftung. Das Kürzel dient zugleich als
// CSS-Klasse (.badge--abgelehnt usw.).
$statusText = [
    'abgeschlossen' => 'abgeschlossen',
    'storniert'     => 'storniert',
    'abgelehnt'     => 'abgelehnt',
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

// Meine Buchungen, die nicht mehr aktuell sind.
$buchungen = array_filter(
    beispiel_buchungen(),
    fn (array $b): bool => ($b['antragsteller_id'] === $ich || $b['fahrer_id'] === $ich)
        && isset($statusText[$b['status']]),
);

// Neueste zuerst.
usort($buchungen, fn (array $a, array $b): int => $b['start'] <=> $a['start']);

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Abgeschlossene, stornierte und abgelehnte Buchungen, die neueste zuerst. Bei einer Ablehnung
    steht die Begründung des Fuhrparkleiters dabei.
</p>

<p><a href="<?= url('meine-buchungen.php') ?>">&larr; Meine Buchungen</a></p>

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
        <?php foreach ($buchungen as $buchung): ?>
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
                <td>
                    <span class="badge badge--<?= e($buchung['status']) ?>">
                        <?= e($statusText[$buchung['status']] ?? $buchung['status']) ?>
                    </span>
                </td>
                <td><?= $buchung['entscheidung_kommentar'] !== '' ? e($buchung['entscheidung_kommentar']) : '&ndash;' ?></td>
            </tr>
        <?php endforeach; ?>

        <?php if ($buchungen === []): ?>
            <tr>
                <td colspan="5" class="table__empty">Keine früheren Buchungen.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
