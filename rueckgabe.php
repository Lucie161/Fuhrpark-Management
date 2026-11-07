<?php
/**
 * Fahrzeug zurückgeben, nur für Mitarbeiter.
 *
 * Kein Navigationspunkt: Aufruf über „Zurückgeben“ an der laufenden Fahrt in
 * index.php (auch aus dem roten Banner) und meine-buchungen.php, jeweils mit
 * rueckgabe.php?buchung=12. Ohne Angabe ist bei nur einer laufenden Fahrt
 * diese gewählt.
 *
 * Ein neuer Schaden ist entweder ein Kleinschaden (z. B. ein Kratzer, das
 * Fahrzeug bleibt fahrbereit) oder ein Schaden, mit dem das Fahrzeug in die
 * Wartung muss. Bei einem Luxusauto sperrt auch ein Kleinschaden
 * (CSchaden::Sperrt()), es geht ebenfalls in die Wartung. Beides braucht eine
 * Beschreibung; Fotos sind bei beiden möglich. Die Bemerkung zum Zustand ist
 * davon getrennt und bleibt optional.
 *
 * Laden über CListeBuchungen::LadenLaufendeFahrten(), Speichern über
 * CBuchung::Zurueckgeben(). Die Fotos prüft CSchadensfoto::Pruefe(), abgelegt
 * werden sie mit dem Schaden.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_MITARBEITER);

$pageTitle = 'Fahrzeug zurückgeben';

$ich = aktueller_nutzer();

// Neuer Schaden => Beschriftung. Die Werte außer KEIN_SCHADEN sind zugleich
// schaeden.schwere_id; SCHWERE_WARTUNG sperrt das Fahrzeug.
$schadenText = [
    CBuchung::KEIN_SCHADEN => 'Nein',
    SCHWERE_KLEIN          => 'Ja, Kleinschaden',
    SCHWERE_WARTUNG        => 'Ja, muss in die Wartung',
];

// Meine laufenden Fahrten (Status „unterwegs“)
$ListeLaufende = new CListeBuchungen();
$ListeLaufende->LadenLaufendeFahrten($ich);

$Zwecke = new CListeSchluessel('zwecke');

// --- Fahrt auswählen --------------------------------------------------------
// Gibt es nur eine laufende Fahrt, ist sie gleich ausgewählt.

$buchungId = (int) ($_POST['buchung'] ?? $_GET['buchung'] ?? 0);

if ($buchungId === 0 && $ListeLaufende->Count() === 1) {
    $buchungId = $ListeLaufende->Get(0)->GetID();
}

$Fahrt = $ListeLaufende->GetByID($buchungId);

// Bei einem Luxusauto sperrt schon ein Kleinschaden, auch einer, der bei
// Fahrtbeginn notiert wurde: Die Fahrt durfte beginnen, mit der Rückgabe
// geht das Fahrzeug in die Wartung.
$Fahrzeug = $Fahrt !== null ? CFahrzeug::LoadByID($Fahrt->GetFahrzeugID()) : null;
$luxus = $Fahrzeug !== null && $Fahrzeug->IstLuxus();
$schadenBeiUebernahme = $luxus && $Fahrt->HatSchadenBeiUebernahme();

// --- Formular verarbeiten ---------------------------------------------------
// Läuft vor header.php, damit nach dem Speichern die Weiterleitung zu „Meine
// Buchungen“ möglich ist. Nach einem Fehler bleibt die Eingabe im Formular.

$fehler = [];

$eingabe = [
    'km_ende'      => trim((string) ($_POST['km_ende'] ?? '')),
    'schaden'      => (int) (is_string($_POST['schaden'] ?? null) ? $_POST['schaden'] : 0),
    'beschreibung' => trim((string) ($_POST['beschreibung'] ?? '')),
    'bemerkung'    => trim((string) ($_POST['bemerkung'] ?? '')),
];

// Überschreitet die Anfrage post_max_size, verwirft PHP alle Felder. Ohne
// diese Prüfung käme die irreführende Meldung „Bitte wählen Sie die Fahrt aus“.
$zuGross = $_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

if ($zuGross) {
    $fehler[] = 'Die Fotos sind zusammen zu groß. Bitte wählen Sie kleinere oder weniger Fotos.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($Fahrt === null) {
        $fehler[] = 'Bitte wählen Sie die Fahrt aus, die Sie beendet haben.';
    } else {
        // Fotos gehören zur Schadensmeldung und werden ohne Schaden ignoriert.
        $fotos = [];

        if ($eingabe['schaden'] !== CBuchung::KEIN_SCHADEN) {
            [$fotos, $fehler] = CSchadensfoto::Pruefe($_FILES['fotos'] ?? []);
        }

        // Speichern; die übrigen Regeln prüft CBuchung
        if ($fehler === []) {
            $kmEnde = filter_var($eingabe['km_ende'], FILTER_VALIDATE_INT);

            try {
                $Fahrt->Zurueckgeben(
                    $ich,
                    $kmEnde !== false ? $kmEnde : null,
                    $eingabe['schaden'],
                    $eingabe['beschreibung'],
                    $eingabe['bemerkung'],
                    $fotos,
                );
            } catch (CFachlicherFehler $Fehler) {
                $fehler[] = $Fehler->getMessage();
            }
        }

        if ($fehler === []) {
            // Bestätigung je nach Ergebnis: Ist das Fahrzeug jetzt in Wartung?
            $Fahrzeug = CFahrzeug::LoadByID($Fahrt->GetFahrzeugID());
            $meldung = 'Rückgabe gespeichert. ' . $Fahrzeug->GetName();

            if ($Fahrzeug->IstInWartung()) {
                $meldung .= ' steht jetzt „in Wartung“'
                    . ($luxus && $eingabe['schaden'] !== SCHWERE_WARTUNG ? ', denn bei einem Luxusauto sperrt auch ein Kleinschaden' : '')
                    . '. Der Fuhrparkleiter sieht Ihre Meldung und gibt das Fahrzeug nach der Reparatur wieder frei.';
            } elseif ($eingabe['schaden'] === SCHWERE_KLEIN) {
                $meldung .= ' ist wieder für andere frei. Der Fuhrparkleiter sieht den Kleinschaden, der nächste Fahrer sieht ihn vor Fahrtbeginn.';
            } else {
                $meldung .= ' ist wieder für andere frei.';
            }

            if ($Fahrt->GetKmEnde() !== null) {
                $meldung .= ' Gefahrene Strecke: '
                    . number_format($Fahrt->GetKmEnde() - $Fahrt->GetKmStart(), 0, ',', '.') . ' km.';
            }

            merke_meldung($meldung, $Fahrzeug->IstInWartung() ? 'hinweis' : 'erfolg');
            redirect('meine-buchungen.php');
        }

        if (($_FILES['fotos']['name'][0] ?? '') !== '') {
            // Der Browser kann ausgewählte Dateien nicht erneut vorbelegen.
            $fehler[] = 'Bitte wählen Sie die Fotos nach der Korrektur erneut aus.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($ListeLaufende->Count() === 0): ?>

    <p class="lead">Sie haben derzeit keine laufende Fahrt.</p>
    <p><a href="<?= url('meine-buchungen.php') ?>">Zu meinen Buchungen</a></p>

<?php else: ?>

    <section>
        <h2>Laufende Fahrten</h2>

        <table class="table">
            <thead>
                <tr>
                    <th>Zeitraum</th>
                    <th>Fahrzeug</th>
                    <th>Zweck</th>
                    <th>Stand</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ListeLaufende->GetAlle() as $Laufend): ?>
                    <tr<?= $Laufend->GetID() === $buchungId ? ' class="table__zeile--gewaehlt"' : '' ?>>
                        <td><?= e(zeitraum_text($Laufend->GetStart(), $Laufend->GetEnde())) ?></td>
                        <td><?= e($Laufend->GetFahrzeugName()) ?></td>
                        <td><?= e($Zwecke->GetBezeichnung($Laufend->GetZweckID())) ?></td>
                        <td>
                            <span class="badge badge--unterwegs">unterwegs</span>
                            <?php if ($Laufend->IstUeberfaellig()): ?>
                                <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($Laufend->GetID() !== $buchungId): ?>
                                <a class="button button--klein" href="<?= url('rueckgabe.php?buchung=' . $Laufend->GetID()) ?>">Auswählen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ($Fahrt !== null): ?>
        <form class="form form--breit section" method="post" action="<?= url('rueckgabe.php') ?>"
              enctype="multipart/form-data">
            <h2 class="form__titel">Rückgabe: <?= e($Fahrt->GetFahrzeugName()) ?></h2>

            <input type="hidden" name="buchung" value="<?= e((string) $Fahrt->GetID()) ?>">

            <div class="form__raster">
                <?php if ($Fahrt->GetKmStart() !== null): ?>
                    <div class="form__row">
                        <label class="form__label" for="km_ende">Kilometerstand bei Rückgabe</label>
                        <input class="form__input" type="number" id="km_ende" name="km_ende"
                               min="<?= e((string) $Fahrt->GetKmStart()) ?>" step="1" required
                               value="<?= e($eingabe['km_ende']) ?>">
                        <span class="form__hinweis">
                            bei Fahrtbeginn: <?= number_format($Fahrt->GetKmStart(), 0, ',', '.') ?> km
                        </span>
                    </div>
                <?php endif; ?>

                <fieldset class="form__row form__gruppe">
                    <legend class="form__label">Neuer Schaden entstanden?</legend>
                    <div class="form__optionen">
                        <?php foreach ($schadenText as $wert => $text): ?>
                            <label>
                                <input type="radio" name="schaden" value="<?= e((string) $wert) ?>"
                                       <?= $wert === $eingabe['schaden'] || ($wert === CBuchung::KEIN_SCHADEN && !isset($schadenText[$eingabe['schaden']])) ? 'checked' : '' ?>>
                                <?= e($text) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="form__hinweis">
                        <?php if ($luxus): ?>
                            Dies ist ein Luxusauto: Jeder Schaden, auch ein Kleinschaden, sperrt es, bis
                            der Fuhrparkleiter es nach der Reparatur wieder freigibt.
                            <?php if ($schadenBeiUebernahme): ?>
                                Wegen des bei Fahrtbeginn notierten Schadens geht es nach dieser
                                Rückgabe ohnehin in die Wartung.
                            <?php endif; ?>
                        <?php else: ?>
                            Ein Kleinschaden (z. B. ein Kratzer) sperrt das Fahrzeug nicht; der nächste
                            Fahrer sieht ihn vor Fahrtbeginn. Mit Wartung ist das Fahrzeug gesperrt, bis
                            der Fuhrparkleiter es wieder freigibt.
                        <?php endif; ?>
                    </span>
                </fieldset>
            </div>

            <div class="form__row" data-nur-bei-schaden>
                <label class="form__label" for="beschreibung">Beschreibung des Schadens</label>
                <textarea class="form__input" id="beschreibung" name="beschreibung" rows="2" maxlength="<?= e((string) CSchaden::MAX_BESCHREIBUNG) ?>"
                          placeholder="z. B. Delle hinten links, Tür schließt schwer"><?= e($eingabe['beschreibung']) ?></textarea>
                <span class="form__hinweis">Nur bei einem Schaden, dann Pflicht: Was ist beschädigt?</span>
            </div>

            <div class="form__row" data-nur-bei-schaden>
                <label class="form__label" for="fotos">Fotos vom Schaden (optional)</label>
                <input class="form__input" type="file" id="fotos" name="fotos[]" multiple
                       accept="image/jpeg,image/png,image/webp">
                <span class="form__hinweis">
                    Nur bei einem Schaden. Höchstens <?= e((string) CSchadensfoto::MAX_ANZAHL) ?> Fotos
                    (JPEG, PNG oder WebP) zu je <?= e((string) intdiv(CSchadensfoto::MAX_BYTES, 1024 * 1024)) ?> MB.
                </span>
            </div>

            <div class="form__row">
                <label class="form__label" for="bemerkung">Bemerkung zum Zustand (optional)</label>
                <textarea class="form__input" id="bemerkung" name="bemerkung" rows="2" maxlength="500"
                          placeholder="z. B. Innenraum verschmutzt, Akku fast leer"><?= e($eingabe['bemerkung']) ?></textarea>
                <span class="form__hinweis">Steht im Fahrtenbuch und sperrt das Fahrzeug nicht.</span>
            </div>

            <button class="button" type="submit">Rückgabe speichern</button>
        </form>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
