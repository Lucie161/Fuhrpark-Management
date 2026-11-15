<?php
/**
 * Filterleiste für historie.php und analyse.php: Zeitraum, Fahrzeug oder
 * Kategorie und Mitarbeiter in einem GET-Formular, damit sich eine Auswahl
 * als Link weitergeben lässt.
 *
 * Erwartet von der einbindenden Seite die Werte aus
 * includes/auswertung-filter.php und dazu:
 *   $filterZiel  Seite, an die das Formular geht, z. B. 'historie.php'
 *   $gefiltert   true, wenn etwas vom Standard abweicht („Filter zurücksetzen“)
 *   $suche       optional: Suchbegriff; gesetzt, gibt es das Suchfeld
 *   $nurFahrten  optional: Kästchen „nur Fahrten“; gesetzt, gibt es es
 */

declare(strict_types=1);
?>
<form class="filter historie-filter" method="get" action="<?= url($filterZiel) ?>">
    <?php if (isset($suche)): ?>
        <div class="filter__feld">
            <label class="form__label" for="suche">Suche</label>
            <input class="form__input" type="search" id="suche" name="suche" value="<?= e($suche) ?>"
                   placeholder="z. B. Transit, Wagner">
        </div>
    <?php endif; ?>

    <div class="filter__feld historie-filter__datum">
        <label class="form__label" for="von">Von</label>
        <input class="form__input" type="date" id="von" name="von" required
               value="<?= e($eingabe['von']) ?>">
    </div>

    <div class="filter__feld historie-filter__datum">
        <label class="form__label" for="bis">Bis</label>
        <input class="form__input" type="date" id="bis" name="bis" required
               value="<?= e($eingabe['bis']) ?>">
    </div>

    <div class="filter__feld">
        <label class="form__label" for="auswahl">Fahrzeug oder Kategorie</label>
        <select class="form__input" id="auswahl" name="auswahl">
            <option value="">Alle Fahrzeuge</option>
            <optgroup label="Kategorie">
                <?php foreach ($auswahlKategorien as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $wert === $eingabe['auswahl'] ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <optgroup label="Fahrzeug">
                <?php foreach ($auswahlFahrzeuge as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $wert === $eingabe['auswahl'] ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </optgroup>
        </select>
    </div>

    <div class="filter__feld">
        <label class="form__label" for="fahrer">Mitarbeiter</label>
        <select class="form__input" id="fahrer" name="fahrer">
            <option value="">Alle</option>
            <?php foreach ($mitarbeiter as $id => $name): ?>
                <option value="<?= e((string) $id) ?>"<?= $id === $fahrerFilter ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (isset($nurFahrten)): ?>
        <label class="filter__haken">
            <input type="checkbox" name="nur_fahrten" value="1"<?= $nurFahrten ? ' checked' : '' ?>>
            nur Fahrten
        </label>
    <?php endif; ?>

    <div class="aktionen">
        <button class="button" type="submit">Filtern</button>

        <?php if ($gefiltert): ?>
            <a class="button button--zweitrangig" href="<?= url($filterZiel) ?>">Filter zurücksetzen</a>
        <?php endif; ?>
    </div>
</form>
