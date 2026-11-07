<?php
/**
 * Meine Buchungen, nur für Mitarbeiter.
 *
 * Zeigt die aktuellen Buchungen (beantragt, genehmigt, unterwegs), die ich
 * beantragt habe oder bei denen ich Fahrer bin, die nächste zuerst. Von hier
 * aus wird storniert und die Fahrt begonnen; „Fahrt beginnen“ hat keine
 * eigene Seite, das Formular (includes/fahrt-beginnen.php) schickt auch von
 * index.php hierher. Frühere und abgesagte Buchungen stehen in
 * fruehere-buchungen.php, verlinkt unter der Tabelle.
 *
 * Laden und Speichern laufen über CListeBuchungen und CBuchung.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_MITARBEITER);

$pageTitle = 'Meine Buchungen';

$ich = aktueller_nutzer();

$ListeBuchungen = new CListeBuchungen();
$ListeBuchungen->LadenAktuelle($ich);

$Zwecke = new CListeSchluessel('zwecke');
$Status = new CListeSchluessel('buchungsstatus');

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
            );
        } elseif ($aktion === 'beginnen') {
            $offeneFahrt = $id;
            $Buchung->FahrtBeginnen($ich, $schadenEingabe);
            merke_meldung(
                'Fahrt mit ' . $Buchung->GetFahrzeugName() . ' begonnen. Gute Fahrt!'
                    . ($schadenEingabe !== '' ? ' Der notierte Schaden ist gespeichert.' : '')
            );
        } else {
            throw new CFachlicherFehler('Diese Aktion gibt es nicht.');
        }

        redirect($zurueck === 'index' ? 'index.php' : 'meine-buchungen.php');
    } catch (CFachlicherFehler $Fehler) {
        $fehler[] = $Fehler->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Ihre aktuellen Buchungen: beantragt, genehmigt oder unterwegs, die nächste zuerst. Dazu
    gehören auch Buchungen, die jemand anderes für Sie als Fahrer angelegt hat.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="section">
    <h2>Aktuelle Buchungen</h2>

    <table class="table">
        <thead>
            <tr>
                <th>Zeitraum</th>
                <th>Fahrzeug</th>
                <th>Zweck</th>
                <th class="table__num">Personen</th>
                <th>Stand</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ListeBuchungen->GetAlle() as $Buchung): ?>
                <tr>
                    <td><?= e(zeitraum_text($Buchung->GetStart(), $Buchung->GetEnde())) ?></td>
                    <td>
                        <a href="<?= url('fahrzeug.php?id=' . $Buchung->GetFahrzeugID()) ?>"><?= e($Buchung->GetFahrzeugName()) ?></a>
                        <?php if ($Buchung->GetAntragstellerID() !== $ich): ?>
                            <span class="table__zusatz">gebucht von <?= e($Buchung->GetAntragstellerName()) ?></span>
                        <?php endif; ?>
                        <?php if ($Buchung->GetFahrerID() !== $ich): ?>
                            <span class="table__zusatz">Fahrer: <?= e($Buchung->GetFahrerName()) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($Zwecke->GetBezeichnung($Buchung->GetZweckID())) ?></td>
                    <td class="table__num"><?= e((string) $Buchung->GetPersonenanzahl()) ?></td>
                    <td>
                        <span class="badge badge--<?= e(BUCHUNGSSTATUS_KLASSE[$Buchung->GetStatusID()]) ?>">
                            <?= e($Status->GetBezeichnung($Buchung->GetStatusID())) ?>
                        </span>
                        <?php if ($Buchung->IstUeberfaellig()): ?>
                            <span class="badge badge--abgelehnt">Rückgabe überfällig</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="aktionen">
                            <?php if ($Buchung->KannBeginnen($ich)): ?>
                                <?php require __DIR__ . '/includes/fahrt-beginnen.php'; ?>
                            <?php endif; ?>

                            <?php if ($Buchung->GetStatusID() === STATUS_UNTERWEGS && $Buchung->GetFahrerID() === $ich): ?>
                                <a class="button button--klein"
                                   href="<?= url('rueckgabe.php?buchung=' . $Buchung->GetID()) ?>">Zurückgeben</a>
                            <?php endif; ?>

                            <?php if ($Buchung->KannStornieren($ich)): ?>
                                <details class="klappaktion">
                                    <summary class="button button--klein button--gefahr">Stornieren</summary>

                                    <form class="klappaktion__form" method="post" action="<?= url('meine-buchungen.php') ?>">
                                        <input type="hidden" name="buchung" value="<?= e((string) $Buchung->GetID()) ?>">
                                        <input type="hidden" name="aktion" value="stornieren">
                                        <p class="klappaktion__frage">
                                            Buchung wirklich stornieren? Das Fahrzeug wird für den
                                            Zeitraum wieder freigegeben.
                                        </p>
                                        <button class="button button--klein button--gefahr" type="submit">Ja, stornieren</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($ListeBuchungen->Count() === 0): ?>
                <tr>
                    <td colspan="6" class="table__empty">Keine aktuellen Buchungen.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="aktionen section">
        <a class="button" href="<?= url('buchen.php') ?>">Neues Fahrzeug buchen</a>
        <a class="button button--zweitrangig" href="<?= url('fruehere-buchungen.php') ?>">Frühere und abgesagte Buchungen</a>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
