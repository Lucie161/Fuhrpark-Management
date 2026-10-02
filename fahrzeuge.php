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

// Beispiel-Fuhrpark, dieselben Fahrzeuge wie in fahrzeug.php und buchen.php.
$fahrzeuge = [
    ['id' => 1, 'kennzeichen' => 'M-HS 101',  'hersteller' => 'Volkswagen',     'modell' => 'Passat Variant', 'baujahr' => 2021, 'kmstand' => 48250,  'status' => 'verfuegbar'],
    ['id' => 2, 'kennzeichen' => 'M-HS 102',  'hersteller' => 'Škoda',          'modell' => 'Octavia Combi',  'baujahr' => 2023, 'kmstand' => 9870,   'status' => 'verfuegbar'],
    ['id' => 3, 'kennzeichen' => 'M-HS 201',  'hersteller' => 'Ford',           'modell' => 'Transit',        'baujahr' => 2019, 'kmstand' => 112400, 'status' => 'unterwegs'],
    ['id' => 4, 'kennzeichen' => 'M-HS 202',  'hersteller' => 'Mercedes-Benz',  'modell' => 'Sprinter',       'baujahr' => 2020, 'kmstand' => 87310,  'status' => 'wartung'],
    ['id' => 5, 'kennzeichen' => 'M-HS 301E', 'hersteller' => 'Volkswagen',     'modell' => 'ID.3',           'baujahr' => 2022, 'kmstand' => 31540,  'status' => 'verfuegbar'],
    ['id' => 6, 'kennzeichen' => 'M-HS 302E', 'hersteller' => 'Tesla',          'modell' => 'Model 3',        'baujahr' => 2024, 'kmstand' => 12020,  'status' => 'verfuegbar'],
    ['id' => 7, 'kennzeichen' => 'M-HS 401',  'hersteller' => 'Vespa',          'modell' => 'Primavera 125',  'baujahr' => 2022, 'kmstand' => 6400,   'status' => 'verfuegbar'],
    ['id' => 8, 'kennzeichen' => 'Rad 1',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'baujahr' => 2023, 'kmstand' => null,   'status' => 'verfuegbar'],
    ['id' => 9, 'kennzeichen' => 'Rad 2',     'hersteller' => 'Riese & Müller', 'modell' => 'Charger4',       'baujahr' => 2023, 'kmstand' => null,   'status' => 'verfuegbar'],
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
                <td>
                    <a href="<?= url('fahrzeug.php?id=' . $fahrzeug['id']) ?>"><?= e($fahrzeug['kennzeichen']) ?></a>
                </td>
                <td><?= e($fahrzeug['hersteller']) ?></td>
                <td><?= e($fahrzeug['modell']) ?></td>
                <td><?= e((string) $fahrzeug['baujahr']) ?></td>
                <td class="table__num"><?= $fahrzeug['kmstand'] !== null ? number_format($fahrzeug['kmstand'], 0, ',', '.') : '&ndash;' ?></td>
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
