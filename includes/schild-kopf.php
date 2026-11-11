<?php
/**
 * Kopf eines Schilds: Kennzeichen groß, Modell und Fahrzeugart klein, rechts
 * das Feld „Stand“ mit Farbpunkt in Ampelfarbe.
 *
 * Erwartet von der einbindenden Seite:
 *   $SchildFahrzeug  das Fahrzeug (CFahrzeug)
 *   $Arten           CListeSchluessel('fahrzeugarten')
 *   $marke           [Wort, Farbe], Farbe 'gruen', 'gelb', 'rot' oder 'grau';
 *                    für Buchungen aus buchung_stand()
 */

declare(strict_types=1);
?>
<div class="schild__kopf">
    <div class="schild__name">
        <h3 class="schild__kennzeichen">
            <a href="<?= url('fahrzeug.php?id=' . $SchildFahrzeug->GetID()) ?>"><?= e($SchildFahrzeug->GetKennzeichen()) ?></a>
        </h3>
        <p class="schild__modell">
            <?= e($SchildFahrzeug->GetHersteller() . ' ' . $SchildFahrzeug->GetModell()) ?>
            &middot; <?= e($Arten->GetBezeichnung($SchildFahrzeug->GetArtID())) ?>
        </p>
    </div>
    <p class="stand stand--<?= e($marke[1]) ?>">
        <span class="stand__label">Stand</span>
        <span class="stand__wert"><?= e($marke[0]) ?></span>
    </p>
</div>
