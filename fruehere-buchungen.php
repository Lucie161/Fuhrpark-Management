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
$pageZurueck = ['Meine Buchungen', 'meine-buchungen.php'];

$ich = aktueller_nutzer();

$ListeBuchungen = new CListeBuchungen();
$ListeBuchungen->LadenFruehere($ich);

$Zwecke = new CListeSchluessel('zwecke');

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Abgeschlossene, stornierte und abgelehnte Buchungen, die neueste zuerst. Bei einer Ablehnung
    steht die Begründung des Fuhrparkleiters dabei.
</p>

<table class="table table--stapel">
    <thead>
        <tr>
            <th>Fahrzeug</th>
            <th>Zeitraum</th>
            <th>Zweck</th>
            <th>Stand</th>
            <th>Begründung</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ListeBuchungen->GetAlle() as $Buchung): ?>
            <?php
            $Fahrzeug = CFahrzeug::LoadByID($Buchung->GetFahrzeugID());
            $marke = buchung_stand($Buchung, $ich);
            ?>
            <tr id="buchung-<?= e((string) $Buchung->GetID()) ?>">
                <td class="table__fahrzeug">
                    <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                    <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                </td>
                <td data-label="Zeitraum">
                    <?= e(zeitraum_kurz($Buchung->GetStart(), $Buchung->GetEnde())) ?>
                    <?php if ($Buchung->GetAntragstellerID() !== $ich): ?>
                        <span class="table__zusatz">gebucht von <?= e($Buchung->GetAntragstellerName()) ?></span>
                    <?php endif; ?>
                    <?php if ($Buchung->GetFahrerID() !== $ich): ?>
                        <span class="table__zusatz">Fahrer: <?= e($Buchung->GetFahrerName()) ?></span>
                    <?php endif; ?>
                </td>
                <td data-label="Zweck"><?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?></td>
                <td class="table__stand" data-label="Stand"><span class="marke marke--<?= e($marke[1]) ?>"><?= e($marke[0]) ?></span></td>
                <?php if ($Buchung->GetEntscheidungKommentar() !== ''): ?>
                    <td class="table__breit" data-label="Begründung"><?= e($Buchung->GetEntscheidungKommentar()) ?></td>
                <?php else: ?>
                    <td class="table__leer">&ndash;</td>
                <?php endif; ?>
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
