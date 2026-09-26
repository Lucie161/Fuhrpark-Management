<?php
/**
 * Startseite / Übersicht.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Übersicht';

// Platzhalter-Zahlen. Kommen später aus der Datenbank, z. B.:
// $anzahlFahrzeuge = (int) db()->query('SELECT COUNT(*) FROM fahrzeuge')->fetchColumn();
$kennzahlen = [
    ['label' => 'Fahrzeuge gesamt', 'wert' => '—'],
    ['label' => 'Aktuell verfügbar', 'wert' => '—'],
    ['label' => 'In Wartung',        'wert' => '—'],
    ['label' => 'Offene Buchungen',  'wert' => '—'],
];

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Willkommen im <?= e(APP_NAME) ?>. Von hier aus werden Fahrzeuge, Fahrer
    und Buchungen verwaltet.
</p>

<div class="cards">
    <?php foreach ($kennzahlen as $kennzahl): ?>
        <div class="card">
            <p class="card__value"><?= e($kennzahl['wert']) ?></p>
            <p class="card__label"><?= e($kennzahl['label']) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<section class="section">
    <h2>Nächste Schritte</h2>
    <p class="note">
        Diese Seite ist noch ein Gerüst. Die Kennzahlen oben sind Platzhalter
        und werden befüllt, sobald die Datenbanktabellen stehen.
    </p>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
