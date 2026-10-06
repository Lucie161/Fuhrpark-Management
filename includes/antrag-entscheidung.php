<?php
/**
 * Genehmigen und Ablehnen eines Antrags: dasselbe Formular in
 * genehmigungen.php und auf der Übersicht des Fuhrparkleiters (index.php).
 * Beide schicken an genehmigungen.php. Mit zurueck=index leitet die Seite
 * nach der Entscheidung zur Übersicht zurück (siehe
 * docs/technisches-konzept.md, Regel 8).
 *
 * Erwartet von der einbindenden Seite:
 *   $id             Nummer des Antrags
 *   $antrag         der Antrag mit 'hindernis' (null oder Grund, warum
 *                   Genehmigen nicht geht)
 *   $zurueck        'index' oder ''
 *   $offenerAntrag  Antrag, dessen Ablehnen nach einem Fehler offen bleibt,
 *                   sonst null
 *   $kommentar      bisherige Eingabe der Begründung
 */

declare(strict_types=1);
?>
<div class="aktionen">
    <?php if ($antrag['hindernis'] === null): ?>
        <form method="post" action="<?= url('genehmigungen.php') ?>">
            <input type="hidden" name="antrag" value="<?= e((string) $id) ?>">
            <input type="hidden" name="entscheidung" value="genehmigen">
            <?php if ($zurueck !== ''): ?>
                <input type="hidden" name="zurueck" value="<?= e($zurueck) ?>">
            <?php endif; ?>
            <button class="button button--klein" type="submit">Genehmigen</button>
        </form>
    <?php endif; ?>

    <details class="klappaktion"<?= $id === $offenerAntrag ? ' open' : '' ?>>
        <summary class="button button--klein button--gefahr">Ablehnen</summary>

        <form class="klappaktion__form" method="post" action="<?= url('genehmigungen.php') ?>">
            <input type="hidden" name="antrag" value="<?= e((string) $id) ?>">
            <input type="hidden" name="entscheidung" value="ablehnen">
            <?php if ($zurueck !== ''): ?>
                <input type="hidden" name="zurueck" value="<?= e($zurueck) ?>">
            <?php endif; ?>
            <label class="form__label" for="kommentar-<?= e((string) $id) ?>">Begründung</label>
            <textarea class="form__input" id="kommentar-<?= e((string) $id) ?>" name="kommentar"
                      rows="3" maxlength="500" required><?= $id === $offenerAntrag ? e($kommentar) : '' ?></textarea>
            <button class="button button--klein button--gefahr" type="submit">Ablehnung speichern</button>
        </form>
    </details>
</div>

<?php if ($antrag['hindernis'] !== null): ?>
    <span class="table__zusatz"><?= e($antrag['hindernis']) ?> Genehmigen ist nicht möglich.</span>
<?php endif; ?>
