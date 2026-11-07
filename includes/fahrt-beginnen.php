<?php
/**
 * „Fahrt beginnen“ an einer aktiven Buchung: dasselbe Formular in index.php
 * und meine-buchungen.php. Beide schicken an meine-buchungen.php; mit
 * zurueck=index geht es danach zur Übersicht zurück. Vor dem Bestätigen sieht
 * der Fahrer die noch nicht behobenen Schäden des Fahrzeugs, damit sie ihm
 * nicht zugerechnet werden.
 *
 * Bei einem Luxusauto kann die Fahrt trotz eines notierten Schadens beginnen;
 * nach der Rückgabe geht es in die Wartung.
 *
 * Erwartet von der einbindenden Seite:
 *   $Buchung         die aktive Buchung (CBuchung)
 *   $zurueck         'index' oder ''
 *   $offeneFahrt     optional: Buchung, deren Formular nach einem Fehler offen
 *                    bleibt, sonst null
 *   $schadenEingabe  optional: bisherige Eingabe im Feld „Schäden“
 */

declare(strict_types=1);

$fahrtID = $Buchung->GetID();
$fahrtSperre = $Buchung->GetSperreFahrtbeginn();
$fahrtLuxus = CFahrzeug::LoadByID($Buchung->GetFahrzeugID())->IstLuxus();
$fahrtOffen = ($offeneFahrt ?? null) === $fahrtID;
$fahrtOffeneSchaeden = offene_schaeden($Buchung->GetFahrzeugID());
?>
<?php if ($fahrtSperre !== null): ?>
    <span class="table__zusatz">Fahrt beginnen nicht möglich. <?= e($fahrtSperre) ?></span>
<?php else: ?>
    <details class="klappaktion"<?= $fahrtOffen ? ' open' : '' ?>>
        <summary class="button button--klein">Fahrt beginnen</summary>

        <form class="klappaktion__form" method="post" action="<?= url('meine-buchungen.php') ?>">
            <input type="hidden" name="buchung" value="<?= e((string) $fahrtID) ?>">
            <input type="hidden" name="aktion" value="beginnen">
            <?php if ($zurueck !== ''): ?>
                <input type="hidden" name="zurueck" value="<?= e($zurueck) ?>">
            <?php endif; ?>

            <?php if ($fahrtOffeneSchaeden !== []): ?>
                <p class="klappaktion__frage">
                    Diese Schäden sind bereits bekannt. Sie müssen sie nicht erneut angeben:
                </p>
                <ul class="klappaktion__liste">
                    <?php foreach ($fahrtOffeneSchaeden as $fahrtSchaden): ?>
                        <li>
                            <?= e($fahrtSchaden['beschreibung']) ?>
                            (<?= e($fahrtSchaden['gemeldet_am']->format('d.m.Y')) ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <label class="form__label" for="schaden-<?= e((string) $fahrtID) ?>">
                <?= $fahrtOffeneSchaeden !== [] ? 'Weitere' : 'Vorhandene' ?> Schäden, die Ihnen auffallen (optional)
            </label>
            <textarea class="form__input" id="schaden-<?= e((string) $fahrtID) ?>" name="schaden" rows="2"
                      maxlength="200" placeholder="z. B. Kratzer am Kotflügel links"><?= $fahrtOffen ? e($schadenEingabe ?? '') : '' ?></textarea>
            <?php if ($fahrtLuxus): ?>
                <p class="klappaktion__frage">
                    Luxusauto: Die Fahrt kann trotzdem beginnen. Nach der Rückgabe geht das Fahrzeug
                    wegen des Schadens in die Wartung.
                </p>
            <?php endif; ?>
            <button class="button button--klein" type="submit">Fahrzeug übernommen, Fahrt beginnen</button>
        </form>
    </details>
<?php endif; ?>
