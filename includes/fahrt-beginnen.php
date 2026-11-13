<?php
/**
 * „Fahrt beginnen“ an einer aktiven Buchung: dasselbe Formular in index.php
 * und meine-buchungen.php. Beide schicken an meine-buchungen.php; mit
 * zurueck=index geht es danach zur Übersicht zurück. Vor dem Bestätigen sieht
 * der Fahrer die noch nicht behobenen Schäden des Fahrzeugs, damit sie ihm
 * nicht zugerechnet werden.
 *
 * Ist die Fahrt gesperrt, weil das Fahrzeug nicht bereitsteht, gibt es einen
 * Link zur Suche nach einem Ersatzfahrzeug im selben Zeitraum.
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
 *
 * Das Formular öffnet sich als Popup (Popover-Attribut, ohne JavaScript).
 * Nach einem Fehler öffnet assets/js/main.js es wieder (data-offen).
 */

declare(strict_types=1);

$fahrtID = $Buchung->GetID();
$fahrtSperre = $Buchung->GetSperreFahrtbeginn();
$FahrtFahrzeug = CFahrzeug::LoadByID($Buchung->GetFahrzeugID());
$fahrtLuxus = $FahrtFahrzeug->IstLuxus();
$fahrtOffen = ($offeneFahrt ?? null) === $fahrtID;
$FahrtOffeneSchaeden = new CListeSchaeden();
$FahrtOffeneSchaeden->LadenOffene($Buchung->GetFahrzeugID());
?>
<?php if ($fahrtSperre !== null): ?>
    <span class="table__zusatz">Fahrt beginnen nicht möglich. <?= e($fahrtSperre) ?></span>
    <?php
    // Liegt es am Fahrzeug und nicht an der eigenen Rückgabe: Ersatz suchen
    $fahrtErsatz = CNutzer::LoadByID(aktueller_nutzer())->GetSperreBuchen() === null
        ? ersatz_link($Buchung, aktueller_nutzer())
        : null;
    ?>
    <?php if ($fahrtErsatz !== null): ?>
        <a class="button button--klein button--zweitrangig" href="<?= e(url($fahrtErsatz)) ?>">Ersatzfahrzeug buchen</a>
    <?php endif; ?>
<?php else: ?>
    <?php $popup = 'fahrt-' . $fahrtID; ?>
    <button class="button button--klein" type="button" popovertarget="<?= e($popup) ?>">
        <span class="icon icon--schluessel" aria-hidden="true"></span>Fahrt beginnen
    </button>

    <div class="rueckfrage" id="<?= e($popup) ?>" popover<?= $fahrtOffen ? ' data-offen' : '' ?>>
        <form method="post" action="<?= url('meine-buchungen.php') ?>">
            <input type="hidden" name="buchung" value="<?= e((string) $fahrtID) ?>">
            <input type="hidden" name="aktion" value="beginnen">
            <?php if ($zurueck !== ''): ?>
                <input type="hidden" name="zurueck" value="<?= e($zurueck) ?>">
            <?php endif; ?>

            <p class="rueckfrage__titel">
                <span class="icon icon--schluessel" aria-hidden="true"></span>
                Fahrt beginnen
            </p>
            <dl class="felder">
                <div class="feld">
                    <dt>Fahrzeug</dt>
                    <dd><?= e($FahrtFahrzeug->GetHersteller() . ' ' . $FahrtFahrzeug->GetModell()) ?></dd>
                </div>
                <div class="feld">
                    <dt>Zeitraum</dt>
                    <dd><?= e(zeitraum_kurz($Buchung->GetStart(), $Buchung->GetEnde())) ?></dd>
                </div>
            </dl>

            <div class="rueckfrage__inhalt">
                <?php if ($FahrtOffeneSchaeden->Count() > 0): ?>
                    <p class="klappaktion__frage">
                        Diese Schäden sind bereits bekannt. Sie müssen sie nicht erneut angeben:
                    </p>
                    <ul class="klappaktion__liste">
                        <?php foreach ($FahrtOffeneSchaeden->GetAlle() as $FahrtSchaden): ?>
                            <li>
                                <?= e($FahrtSchaden->GetBeschreibung()) ?>
                                (<?= e($FahrtSchaden->GetGemeldetAm()->format('d.m.Y')) ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <label class="form__label" for="schaden-<?= e((string) $fahrtID) ?>">
                    <?= $FahrtOffeneSchaeden->Count() > 0 ? 'Weitere' : 'Vorhandene' ?> Schäden, die Ihnen auffallen (optional)
                </label>
                <textarea class="form__input" id="schaden-<?= e((string) $fahrtID) ?>" name="schaden" rows="3"
                          maxlength="<?= e((string) CSchaden::MAX_BESCHREIBUNG) ?>" placeholder="z. B. Kratzer am Kotflügel links"><?= $fahrtOffen ? e($schadenEingabe ?? '') : '' ?></textarea>
                <?php if ($fahrtLuxus): ?>
                    <p class="klappaktion__frage">
                        Luxusauto: Die Fahrt kann trotzdem beginnen. Nach der Rückgabe geht das Fahrzeug
                        wegen des Schadens in die Wartung.
                    </p>
                <?php endif; ?>
            </div>

            <div class="aktionen">
                <button class="button button--klein button--zweitrangig" type="button"
                        popovertarget="<?= e($popup) ?>" popovertargetaction="hide">Abbrechen</button>
                <button class="button button--klein" type="submit">Fahrzeug übernommen, Fahrt beginnen</button>
            </div>
        </form>
    </div>
<?php endif; ?>
