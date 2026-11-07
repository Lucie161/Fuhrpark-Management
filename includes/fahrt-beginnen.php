<?php
/**
 * „Fahrt beginnen“ an einer aktiven Buchung: dasselbe Formular in
 * index.php und meine-buchungen.php. Vor dem Bestätigen sieht der Fahrer die noch nicht behobenen
 * Schäden des Fahrzeugs, damit sie ihm nicht zugerechnet werden.
 *
 * Bei einem Luxusauto kann die Fahrt trotz eines notierten Schadens beginnen;
 * nach der Rückgabe geht es in die Wartung.
 *
 * Erwartet von der einbindenden Seite:
 *   $buchung  die Buchung mit 'fahrzeug_id' und 'sperre' (null oder Grund,
 *             warum die Fahrt nicht beginnen kann, aus sperre_fahrtbeginn())
 *
 * Prototyp: Der Knopf löst noch nichts aus.
 */

declare(strict_types=1);

$fahrtOffeneSchaeden = offene_schaeden($buchung['fahrzeug_id']);
$fahrtLuxus = beispiel_fahrzeuge()[$buchung['fahrzeug_id']]['art_id'] === ART_LUXUS;
?>
<?php if ($buchung['sperre'] !== null): ?>
    <span class="table__zusatz">Fahrt beginnen nicht möglich. <?= e($buchung['sperre']) ?></span>
<?php else: ?>
    <details class="klappaktion">
        <summary class="button button--klein">Fahrt beginnen</summary>

        <div class="klappaktion__form">
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

            <label class="form__label">
                <?= $fahrtOffeneSchaeden !== [] ? 'Weitere' : 'Vorhandene' ?> Schäden, die Ihnen auffallen (optional)
                <textarea class="form__input" rows="2"
                          placeholder="z. B. Kratzer am Kotflügel links"></textarea>
            </label>
            <?php if ($fahrtLuxus): ?>
                <p class="klappaktion__frage">
                    Luxusauto: Die Fahrt kann trotzdem beginnen. Nach der Rückgabe geht das Fahrzeug
                    wegen des Schadens in die Wartung.
                </p>
            <?php endif; ?>
            <button class="button button--klein" type="button">Fahrzeug übernommen, Fahrt beginnen</button>
        </div>
    </details>
<?php endif; ?>
