<?php
/**
 * Frühere und abgesagte Buchungen, nur für Mitarbeiter.
 *
 * Abgeschlossene, stornierte und abgelehnte Buchungen, die ich beantragt
 * habe oder bei denen ich Fahrer bin, die neueste zuerst. Bei einer Ablehnung
 * oder einer automatischen Stornierung (Wartung) steht die Begründung dabei.
 * Die Seite steht nicht in der Navigation, sondern ist unter der Tabelle in
 * meine-buchungen.php verlinkt.
 *
 * Laden über CListeBuchungen::LadenFruehere().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_MITARBEITER);

$pageTitle = 'Frühere Buchungen';

$ich = aktueller_nutzer();

$ListeBuchungen = new CListeBuchungen();
$ListeBuchungen->LadenFruehere($ich);

$Zwecke = new CListeSchluessel('zwecke');
$Status = new CListeSchluessel('buchungsstatus');

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
        <?php foreach ($ListeBuchungen->GetAlle() as $Buchung): ?>
            <tr>
                <td><?= e(zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())) ?></td>
                <td>
                    <a href="<?= url('fahrzeug.php?id=' . $Buchung->GetFahrzeugID()) ?>"><?= e($Buchung->GetFahrzeugName()) ?></a>
                    <?php if ($Buchung->GetAntragstellerID() !== $ich): ?>
                        <span class="table__zusatz">gebucht von <?= e($Buchung->GetAntragstellerName()) ?></span>
                    <?php endif; ?>
                    <?php if ($Buchung->GetFahrerID() !== $ich): ?>
                        <span class="table__zusatz">Fahrer: <?= e($Buchung->GetFahrerName()) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?></td>
                <td>
                    <span class="badge badge--<?= e(BUCHUNGSSTATUS_KLASSE[$Buchung->GetStatusID()]) ?>">
                        <?= e($Status->GetBezeichnung($Buchung->GetStatusID())) ?>
                    </span>
                </td>
                <td><?= $Buchung->GetEntscheidungKommentar() !== '' ? e($Buchung->GetEntscheidungKommentar()) : '&ndash;' ?></td>
            </tr>
        <?php endforeach; ?>

        <?php if ($ListeBuchungen->Count() === 0): ?>
            <tr>
                <td colspan="5" class="table__empty">Keine früheren Buchungen.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
