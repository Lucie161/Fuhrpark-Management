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
 * Prototyp: Die Buchungen sind feste Beispieldaten; die abgeschlossenen sind
 * dieselben Fahrten wie im Fahrtenbuch (fahrzeug.php, verlauf.php). Mit
 * Datenbank:
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

// Angemeldeter Nutzer. Kommt später aus der Session.
$ich = 'Lucie Schneider';

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

$heute = new DateTimeImmutable('today');

// Beispielbuchungen. Abgelehnt und storniert relativ zu heute, die
// abgeschlossenen mit festem Datum wie im Fahrtenbuch.
$buchungen = [
    ['id' => 16, 'fahrzeug_id' => 6, 'fahrzeug' => 'Tesla Model 3 (M-HS 302E)',       'start' => $heute->modify('+3 day'),        'ende' => $heute->modify('+3 day'),        'zweck' => 'kundentermin',      'status' => 'abgelehnt',     'antragsteller' => $ich, 'fahrer' => $ich, 'kommentar' => 'Für Einzeltermine bitte den ID.3 oder ein E-Fahrrad nutzen.'],
    ['id' => 22, 'fahrzeug_id' => 5, 'fahrzeug' => 'Volkswagen ID.3 (M-HS 301E)',     'start' => new DateTimeImmutable('2026-10-01'), 'ende' => new DateTimeImmutable('2026-10-01'), 'zweck' => 'kundentermin', 'status' => 'abgeschlossen', 'antragsteller' => $ich, 'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 17, 'fahrzeug_id' => 3, 'fahrzeug' => 'Ford Transit (M-HS 201)',         'start' => $heute->modify('-6 day'),        'ende' => $heute->modify('-6 day'),        'zweck' => 'materialtransport', 'status' => 'storniert',     'antragsteller' => $ich, 'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 23, 'fahrzeug_id' => 8, 'fahrzeug' => 'Riese & Müller Charger4 (Rad 1)', 'start' => new DateTimeImmutable('2026-09-30'), 'ende' => new DateTimeImmutable('2026-09-30'), 'zweck' => 'aufmass',      'status' => 'abgeschlossen', 'antragsteller' => $ich, 'fahrer' => $ich, 'kommentar' => ''],
    ['id' => 24, 'fahrzeug_id' => 2, 'fahrzeug' => 'Škoda Octavia Combi (M-HS 102)',  'start' => new DateTimeImmutable('2026-08-14'), 'ende' => new DateTimeImmutable('2026-08-14'), 'zweck' => 'lieferant',    'status' => 'abgeschlossen', 'antragsteller' => $ich, 'fahrer' => $ich, 'kommentar' => ''],
];

// Neueste zuerst.
usort($buchungen, fn (array $a, array $b): int => $b['start'] <=> $a['start']);

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
    Abgeschlossene, stornierte und abgelehnte Buchungen, die neueste zuerst. Bei einer Ablehnung
    steht die Begründung des Fuhrparkleiters dabei.
</p>

<p class="note">
    Prototyp &ndash; Beispieldaten. Angemeldet als <?= e($ich) ?>.
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
                <td>
                    <span class="badge badge--<?= e($buchung['status']) ?>">
                        <?= e($statusText[$buchung['status']] ?? $buchung['status']) ?>
                    </span>
                </td>
                <td><?= $buchung['kommentar'] !== '' ? e($buchung['kommentar']) : '&ndash;' ?></td>
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
