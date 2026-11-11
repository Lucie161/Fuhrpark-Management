<?php
/**
 * Fußbereich jeder Seite: schließt den Inhalt und lädt das Skript.
 *
 * Links Anwendung, Version und Firma. Für Angemeldete in der Mitte, wer
 * angemeldet ist und mit welcher Rolle; Mitarbeiter sehen dazu den
 * Fuhrparkleiter als Ansprechpartner. $Angemeldet kommt aus header.php.
 */

declare(strict_types=1);

$FussNutzer = $Angemeldet ?? null;
$fussRolle = '';
$fussKontakt = [];

if ($FussNutzer !== null) {
    $fussRolle = (new CListeSchluessel('rollen'))->GetBezeichnung($FussNutzer->GetRolleID());

    // Ansprechpartner: alle aktiven Fuhrparkleiter
    if ($FussNutzer->GetRolleID() === ROLLE_MITARBEITER) {
        $FussListe = new CListeNutzer();
        $FussListe->LadenAktive();

        foreach ($FussListe->GetAlle() as $Eintrag) {
            if ($Eintrag->GetRolleID() === ROLLE_FUHRPARKLEITER) {
                $fussKontakt[] = $Eintrag->GetName();
            }
        }
    }
}
?>
</main>

<footer class="fussleiste">
    <div class="container fussleiste__innen">
        <div class="fussleiste__block">
            <p><?= e(APP_NAME) ?> &middot; Version <?= e(APP_VERSION) ?></p>
            <p>Internes Werkzeug der <?= e(FIRMA_NAME) ?>, <?= e(FIRMA_ADRESSE) ?></p>
        </div>

        <?php if ($FussNutzer !== null): ?>
            <div class="fussleiste__block">
                <p>Angemeldet als <?= e($FussNutzer->GetName()) ?>, <?= e($fussRolle) ?></p>
                <?php if ($fussKontakt !== []): ?>
                    <p>Fragen zum Fuhrpark: <?= e(implode(', ', $fussKontakt)) ?> (Fuhrparkleiter)</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p>&copy; <?= date('Y') ?> <?= e(FIRMA_NAME) ?></p>
    </div>
</footer>

<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
