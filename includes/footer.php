<?php
/**
 * Fußbereich jeder Seite: schließt den Inhalt und lädt das Skript.
 *
 * Links die Anwendung, in der Mitte für Mitarbeiter der Fuhrparkleiter als
 * Ansprechpartner mit der Adresse des Fuhrparks, rechts die Anschrift der Firma.
 * $Angemeldet kommt aus header.php.
 */

declare(strict_types=1);

$FussNutzer = $Angemeldet ?? null;
$fussKontakt = [];

// Ansprechpartner für Mitarbeiter: alle aktiven Fuhrparkleiter
if ($FussNutzer !== null && $FussNutzer->GetRolleID() === ROLLE_MITARBEITER) {
    $FussListe = new CListeNutzer();
    $FussListe->LadenAktive();

    foreach ($FussListe->GetAlle() as $Eintrag) {
        if ($Eintrag->GetRolleID() === ROLLE_FUHRPARKLEITER) {
            $fussKontakt[] = $Eintrag;
        }
    }
}
?>
</main>

<footer class="fussleiste">
    <div class="container fussleiste__innen">
        <div class="fussleiste__block">
            <p class="fussleiste__titel"><?= e(APP_NAME) ?></p>
            <p>Internes Werkzeug der <?= e(FIRMA_NAME) ?></p>
            <p>&copy; <?= date('Y') ?> <?= e(FIRMA_NAME) ?></p>
        </div>

        <?php if ($fussKontakt !== []): ?>
            <div class="fussleiste__block">
                <p class="fussleiste__titel">Fragen zum Fuhrpark</p>
                <?php foreach ($fussKontakt as $Kontakt): ?>
                    <p><?= e($Kontakt->GetName()) ?>, Fuhrparkleiter</p>
                <?php endforeach; ?>
                <p><a href="mailto:<?= e(FUHRPARK_EMAIL) ?>"><?= e(FUHRPARK_EMAIL) ?></a></p>
            </div>
        <?php endif; ?>

        <!-- Anschrift wie auf einem Briefkopf: Name, Straße, Ort untereinander -->
        <address class="fussleiste__block fussleiste__anschrift">
            <p class="fussleiste__titel"><?= e(FIRMA_NAME) ?></p>
            <p><?= e(FIRMA_STRASSE) ?></p>
            <p><?= e(FIRMA_ORT) ?></p>
        </address>
    </div>
</footer>

<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
