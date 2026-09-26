<?php
/**
 * Fahrzeugliste.
 *
 * Beispiel für eine Unterseite mit Tabelle. Die Daten stammen derzeit aus
 * einem PHP-Array; sobald die Tabelle existiert, wird $fahrzeuge ersetzt durch:
 *
 *   $fahrzeuge = db()->query('SELECT * FROM fahrzeuge ORDER BY kennzeichen')->fetchAll();
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Fahrzeuge';

// Statuskürzel => Beschriftung. Das Kürzel dient zugleich als CSS-Klasse
// (.badge--verfuegbar usw.) und bleibt deshalb bewusst ohne Umlaute.
$statusText = [
    'verfuegbar' => 'verfügbar',
    'unterwegs'  => 'unterwegs',
    'wartung'    => 'in Wartung',
];

$fahrzeuge = [
    [
        'kennzeichen' => 'M-FP 1001',
        'hersteller'  => 'Volkswagen',
        'modell'      => 'Passat Variant',
        'baujahr'     => 2021,
        'kmstand'     => 48250,
        'status'      => 'verfuegbar',
    ],
    [
        'kennzeichen' => 'M-FP 1002',
        'hersteller'  => 'Ford',
        'modell'      => 'Transit',
        'baujahr'     => 2019,
        'kmstand'     => 112400,
        'status'      => 'unterwegs',
    ],
    [
        'kennzeichen' => 'M-FP 1003',
        'hersteller'  => 'Škoda',
        'modell'      => 'Octavia',
        'baujahr'     => 2023,
        'kmstand'     => 9870,
        'status'      => 'wartung',
    ],
];

require_once __DIR__ . '/includes/header.php';
?>

<p class="note">
    Beispieldaten &ndash; noch ohne Datenbankanbindung.
</p>

<table class="table">
    <thead>
        <tr>
            <th>Kennzeichen</th>
            <th>Hersteller</th>
            <th>Modell</th>
            <th>Baujahr</th>
            <th class="table__num">km-Stand</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($fahrzeuge as $fahrzeug): ?>
            <tr>
                <td><?= e($fahrzeug['kennzeichen']) ?></td>
                <td><?= e($fahrzeug['hersteller']) ?></td>
                <td><?= e($fahrzeug['modell']) ?></td>
                <td><?= e((string) $fahrzeug['baujahr']) ?></td>
                <td class="table__num"><?= number_format($fahrzeug['kmstand'], 0, ',', '.') ?></td>
                <td>
                    <span class="badge badge--<?= e($fahrzeug['status']) ?>">
                        <?= e($statusText[$fahrzeug['status']] ?? $fahrzeug['status']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if ($fahrzeuge === []): ?>
            <tr>
                <td colspan="6" class="table__empty">Keine Fahrzeuge erfasst.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
