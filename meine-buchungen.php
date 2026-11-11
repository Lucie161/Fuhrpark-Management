<?php
/**
 * Meine Buchungen, nur für Mitarbeiter.
 *
 * Zeigt die aktuellen Buchungen (beantragt, genehmigt, unterwegs), die ich
 * beantragt habe oder bei denen ich Fahrer bin, die nächste zuerst. Jede
 * Buchung ist ein Schild: Kennzeichen und Stand im Kopf, Zeitraum, Zweck und
 * Personen als Felder, rechts die Aktionen (Fahrt beginnen, Zurückgeben,
 * Ändern bzw. Verlängern, Stornieren mit Rückfrage als Popup). Ändern setzt
 * eine genehmigte Buchung wieder auf „beantragt“, wenn das Fahrzeug eine
 * Genehmigung braucht (CBuchung::AendereZeitraum()). „Fahrt beginnen“ hat
 * keine eigene Seite, das Formular (includes/fahrt-beginnen.php) schickt auch
 * von index.php hierher. Frühere und abgesagte Buchungen stehen in
 * fruehere-buchungen.php, verlinkt unter den Schildern.
 *
 * Laden und Speichern laufen über CListeBuchungen und CBuchung.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_MITARBEITER);

$pageTitle = 'Meine Buchungen';

$ich = aktueller_nutzer();
$heute = new DateTimeImmutable('today');

// „Fahrzeug buchen“ neben dem Titel nur, wenn Buchen gerade möglich ist
if (CNutzer::LoadByID($ich)->GetSperreBuchen() === null) {
    $pageAktion = ['Fahrzeug buchen', 'buchen.php'];
}

$ListeBuchungen = new CListeBuchungen();
$ListeBuchungen->LadenAktuelle($ich);

$Zwecke = new CListeSchluessel('zwecke');
$Arten = new CListeSchluessel('fahrzeugarten');

// --- Aktion verarbeiten -----------------------------------------------------
// Läuft vor header.php, damit die Weiterleitung möglich ist. Nach Erfolg geht
// es zur aufrufenden Seite zurück (zurueck=index: Übersicht); nach einem
// Fehler bleibt es hier, mit Meldung und der Eingabe im Feld.

$fehler = [];

// Bei einem Fehler beim Fahrtbeginn bleibt das Formular dieser Buchung offen
// und behält die Eingabe.
$offeneFahrt = null;
$schadenEingabe = '';
$zurueck = '';

// Ebenso beim Ändern des Zeitraums
$offeneAenderung = null;
$aenderEingabe = ['beginn' => '', 'ende' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) (is_string($_POST['buchung'] ?? null) ? $_POST['buchung'] : 0);
    $aktion = is_string($_POST['aktion'] ?? null) ? $_POST['aktion'] : '';
    $schadenEingabe = is_string($_POST['schaden'] ?? null) ? trim($_POST['schaden']) : '';
    $zurueck = ($_POST['zurueck'] ?? '') === 'index' ? 'index' : '';

    // Nur Buchungen aus meiner Liste, also eigene
    $Buchung = $ListeBuchungen->GetByID($id);

    try {
        if ($Buchung === null) {
            throw new CFachlicherFehler('Diese Buchung gibt es nicht mehr. Vielleicht ist sie schon erledigt.');
        }

        if ($aktion === 'stornieren') {
            $Buchung->Stornieren($ich);
            merke_meldung(
                'Buchung für ' . $Buchung->GetFahrzeugName() . ', '
                    . zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())
                    . ', storniert. Das Fahrzeug ist für den Zeitraum wieder frei.',
                'hinweis',
                ['Fahrzeug buchen', 'buchen.php'],
            );
        } elseif ($aktion === 'aendern') {
            // Neues Von und Bis; unterwegs zählt nur Bis
            $offeneAenderung = $id;
            $aenderEingabe = [
                'beginn' => is_string($_POST['beginn'] ?? null) ? trim($_POST['beginn']) : '',
                'ende'   => is_string($_POST['ende'] ?? null) ? trim($_POST['ende']) : '',
            ];
            $neuBeginn = $Buchung->GetStatusID() === STATUS_UNTERWEGS
                ? $Buchung->GetStart()
                : datum_aus_eingabe($aenderEingabe['beginn']);
            $neuEnde = datum_aus_eingabe($aenderEingabe['ende']);

            if ($neuBeginn === null || $neuEnde === null) {
                throw new CFachlicherFehler('Bitte geben Sie gültige Daten für den neuen Zeitraum ein.');
            }

            $warGenehmigt = $Buchung->GetStatusID() === STATUS_GENEHMIGT;
            $Buchung->AendereZeitraum($ich, $neuBeginn, $neuEnde);

            // Meldung je nach Ergebnis: neu zu genehmigen oder sofort gültig
            $text = 'Zeitraum für ' . $Buchung->GetFahrzeugName() . ' geändert: '
                . zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde()) . '.';

            if ($warGenehmigt && $Buchung->GetStatusID() === STATUS_OFFEN) {
                merke_meldung($text . ' Der Fuhrparkleiter muss die Buchung neu genehmigen.', 'hinweis');
            } else {
                merke_meldung($text, 'erfolg');
            }
        } elseif ($aktion === 'beginnen') {
            $offeneFahrt = $id;
            $Buchung->FahrtBeginnen($ich, $schadenEingabe);
            merke_meldung(
                'Fahrt mit ' . $Buchung->GetFahrzeugName() . ' begonnen. Gute Fahrt!'
                    . ($schadenEingabe !== '' ? ' Der notierte Schaden ist gespeichert.' : ''),
                'erfolg',
                ['Nach der Fahrt zurückgeben', 'rueckgabe.php?buchung=' . $Buchung->GetID()],
            );
        } else {
            throw new CFachlicherFehler('Diese Aktion gibt es nicht.');
        }

        redirect($zurueck === 'index' ? 'index.php' : 'meine-buchungen.php');
    } catch (CFachlicherFehler $Fehler) {
        $fehler[] = $Fehler->getMessage();
    }
}

// Rote Meldung über dem Titel: meine überfälligen Fahrten als Fahrer
$bannerFahrten = array_filter(
    $ListeBuchungen->GetAlle(),
    fn (CBuchung $Buchung): bool => $Buchung->GetFahrerID() === $ich && $Buchung->IstUeberfaellig(),
);
$bannerMitNamen = false;

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section aria-labelledby="aktuell">
    <?php $anzahl = $ListeBuchungen->Count(); ?>
    <h2 class="abschnitt" id="aktuell">
        Aktuell
        <span class="abschnitt__wert">
            <?= $anzahl === 0 ? 'keine Buchungen' : e((string) $anzahl) . ($anzahl === 1 ? ' Buchung' : ' Buchungen') ?>
        </span>
    </h2>

    <?php foreach ($ListeBuchungen->GetAlle() as $Buchung): ?>
        <?php
        $SchildFahrzeug = CFahrzeug::LoadByID($Buchung->GetFahrzeugID());
        $marke = buchung_stand($Buchung, $ich);
        $binFahrer = $Buchung->GetFahrerID() === $ich;
        $unterwegs = $Buchung->GetStatusID() === STATUS_UNTERWEGS;
        ?>
        <article class="schild">
            <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

            <div class="schild__koerper">
                <dl class="felder felder--buchung">
                    <div class="feld">
                        <dt>Zeitraum</dt>
                        <dd>
                            <?= e(zeitraum_kurz($Buchung->GetStart(), $Buchung->GetEnde())) ?>
                            <?php if ($Buchung->IstUeberfaellig()): ?>
                                <?php $tage = $Buchung->GetEnde()->diff($heute)->days; ?>
                                <small>seit <?= e((string) $tage) ?> <?= $tage === 1 ? 'Tag' : 'Tagen' ?> überfällig</small>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="feld">
                        <dt>Zweck</dt>
                        <dd>
                            <?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?>
                            <?php if (!$binFahrer): ?>
                                <small>Fahrer: <?= e($Buchung->GetFahrerName()) ?></small>
                            <?php elseif ($Buchung->GetAntragstellerID() !== $ich): ?>
                                <small>gebucht von <?= e($Buchung->GetAntragstellerName()) ?></small>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="feld">
                        <dt>Personen</dt>
                        <dd><?= e((string) $Buchung->GetPersonenanzahl()) ?></dd>
                    </div>
                </dl>

                <div class="schild__aktionen">
                    <?php if ($unterwegs && $binFahrer): ?>
                        <a class="button button--klein" href="<?= url('rueckgabe.php?buchung=' . $Buchung->GetID()) ?>">Zurückgeben</a>
                    <?php elseif ($Buchung->KannBeginnen($ich)): ?>
                        <?php require __DIR__ . '/includes/fahrt-beginnen.php'; ?>
                    <?php endif; ?>

                    <?php // Hinweis, wenn hier gerade nichts zu tun ist ?>
                    <?php if ($unterwegs && !$binFahrer): ?>
                        <p class="schild__hinweis">Das Fahrzeug gibt der Fahrer zurück.</p>
                    <?php elseif ($Buchung->GetStatusID() === STATUS_OFFEN): ?>
                        <p class="schild__hinweis">Wartet auf die Entscheidung des Fuhrparkleiters.</p>
                    <?php elseif ($Buchung->GetStatusID() === STATUS_GENEHMIGT && !$binFahrer): ?>
                        <p class="schild__hinweis">
                            Die Fahrt beginnt der Fahrer<?= $Buchung->GetStart() > $heute ? ' ab ' . e($Buchung->GetStart()->format('d.m.Y')) : '' ?>.
                        </p>
                    <?php elseif ($Buchung->GetStatusID() === STATUS_GENEHMIGT && $Buchung->GetStart() > $heute): ?>
                        <p class="schild__hinweis">Fahrt beginnen ab <?= e($Buchung->GetStart()->format('d.m.Y')) ?></p>
                    <?php endif; ?>

                    <?php if ($Buchung->KannAendern($ich)): ?>
                        <?php
                        // Zeitraum ändern; nach einem Fehler bleibt das Formular mit der Eingabe offen
                        $nr = (string) $Buchung->GetID();
                        $aenderOffen = $offeneAenderung === $Buchung->GetID();
                        $wertBeginn = $aenderOffen ? $aenderEingabe['beginn'] : max($Buchung->GetStart(), $heute)->format('Y-m-d');
                        $wertEnde = $aenderOffen ? $aenderEingabe['ende'] : $Buchung->GetEnde()->format('Y-m-d');
                        ?>
                        <details class="klappaktion"<?= $aenderOffen ? ' open' : '' ?>>
                            <summary class="button button--klein button--zweitrangig"><?= $unterwegs ? 'Verlängern' : 'Ändern' ?></summary>

                            <form class="klappaktion__form" method="post" action="<?= url('meine-buchungen.php') ?>">
                                <input type="hidden" name="buchung" value="<?= e($nr) ?>">
                                <input type="hidden" name="aktion" value="aendern">

                                <?php if (!$unterwegs): ?>
                                    <label class="form__label" for="beginn-<?= e($nr) ?>">Von</label>
                                    <input class="form__input" type="date" id="beginn-<?= e($nr) ?>" name="beginn" required
                                           min="<?= e($heute->format('Y-m-d')) ?>" value="<?= e($wertBeginn) ?>">
                                <?php endif; ?>

                                <label class="form__label" for="ende-<?= e($nr) ?>"><?= $unterwegs ? 'Neue Rückgabe am' : 'Bis' ?></label>
                                <input class="form__input" type="date" id="ende-<?= e($nr) ?>" name="ende" required
                                       min="<?= e($heute->format('Y-m-d')) ?>" value="<?= e($wertEnde) ?>">

                                <?php if ($Buchung->GetStatusID() === STATUS_GENEHMIGT && $SchildFahrzeug->BrauchtGenehmigung()): ?>
                                    <p class="klappaktion__frage">Danach muss der Fuhrparkleiter die Buchung neu genehmigen.</p>
                                <?php endif; ?>

                                <button class="button button--klein" type="submit">Zeitraum ändern</button>
                            </form>
                        </details>
                    <?php endif; ?>

                    <?php if ($Buchung->KannStornieren($ich)): ?>
                        <!-- Rückfrage als Popup (Popover-Attribut, geht ohne JavaScript) -->
                        <?php $popup = 'storno-' . $Buchung->GetID(); ?>
                        <button class="button button--klein button--gefahr" type="button" popovertarget="<?= e($popup) ?>">Stornieren</button>

                        <div class="rueckfrage" id="<?= e($popup) ?>" popover>
                            <form method="post" action="<?= url('meine-buchungen.php') ?>">
                                <input type="hidden" name="buchung" value="<?= e((string) $Buchung->GetID()) ?>">
                                <input type="hidden" name="aktion" value="stornieren">
                                <p class="rueckfrage__titel">Buchung stornieren?</p>
                                <p class="rueckfrage__text">
                                    <?= e($Buchung->GetFahrzeugName()) ?>,
                                    <?= e(zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())) ?>.
                                    Das Fahrzeug wird für den Zeitraum wieder freigegeben.
                                </p>
                                <div class="aktionen">
                                    <button class="button button--klein button--gefahr" type="submit">Ja, stornieren</button>
                                    <button class="button button--klein button--zweitrangig" type="button"
                                            popovertarget="<?= e($popup) ?>" popovertargetaction="hide">Abbrechen</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if ($anzahl === 0): ?>
        <div class="schild schild--leer">
            <p>Sie haben gerade keine Buchung. Neue Buchungen und Anträge erscheinen hier.</p>
            <a class="button" href="<?= url('buchen.php') ?>">Fahrzeug buchen</a>
        </div>
    <?php endif; ?>

    <p class="weiter"><a href="<?= url('fruehere-buchungen.php') ?>">Frühere und abgesagte Buchungen</a></p>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
