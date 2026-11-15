<?php
/**
 * Anträge genehmigen, nur für den Fuhrparkleiter.
 *
 * Alle offenen Anträge, die nächste Fahrt zuerst, jeder als Schild wie auf der
 * Übersicht (includes/schild-antrag.php): Antragsteller (und Fahrer, wenn er
 * nicht selbst beantragt hat), Zeitraum, Zweck, wie lange der Antrag schon
 * wartet, rechts Genehmigen und Ablehnen. Genehmigen geht nur, wenn das
 * Fahrzeug im Zeitraum noch frei, nicht in Wartung und nicht überfällig ist
 * (CBuchung::GetGenehmigungHindernis()); eine Ablehnung braucht eine
 * Begründung, die der Antragsteller liest.
 *
 * Laden und Speichern laufen über CListeBuchungen und CBuchung.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$pageTitle = 'Anträge genehmigen';

$ListeAntraege = new CListeBuchungen();
$ListeAntraege->LadenOffeneAntraege();

$Zwecke = new CListeSchluessel('zwecke');
$Arten = new CListeSchluessel('fahrzeugarten');

// --- Entscheidung verarbeiten -----------------------------------------------
// Läuft vor header.php, damit die Weiterleitung möglich ist. Das Formular
// steht in includes/antrag-entscheidung.php und kommt auch von index.php (mit
// zurueck=index). Nach Erfolg geht es dorthin zurück; nach einem Fehler bleibt
// es hier, mit Meldung und der Eingabe im Feld.

$fehler = [];

// Bei einem Fehler im Ablehnen bleibt das Feld dieses Antrags offen und
// behält die Eingabe. $zurueck bleibt in den Formularen, damit es nach der
// Korrektur trotzdem zur Übersicht zurückgeht.
$offenerAntrag = null;
$kommentar = '';
$zurueck = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) (is_string($_POST['antrag'] ?? null) ? $_POST['antrag'] : 0);
    $entscheidung = is_string($_POST['entscheidung'] ?? null) ? $_POST['entscheidung'] : '';
    $kommentar = is_string($_POST['kommentar'] ?? null) ? trim($_POST['kommentar']) : '';
    $zurueck = ($_POST['zurueck'] ?? '') === 'index' ? 'index' : '';

    $Antrag = $ListeAntraege->GetByID($id);

    try {
        if ($Antrag === null) {
            throw new CFachlicherFehler('Diesen Antrag gibt es nicht mehr. Vielleicht wurde er schon entschieden.');
        }

        if ($entscheidung === 'genehmigen') {
            $Antrag->Genehmigen(aktueller_nutzer());
        } elseif ($entscheidung === 'ablehnen') {
            $offenerAntrag = $id;
            $Antrag->Ablehnen(aktueller_nutzer(), $kommentar);
        } else {
            throw new CFachlicherFehler('Diese Entscheidung gibt es nicht.');
        }

        // Meldung mit dem nächsten Schritt: Nach dem Genehmigen steht die
        // Fahrt im Kalender. Nach dem Ablehnen sieht der Antragsteller die
        // Begründung auf seiner Übersicht; weiter geht es mit den übrigen
        // Anträgen.
        $text = 'Antrag von ' . $Antrag->GetAntragstellerName() . ' für ' . $Antrag->GetFahrzeugName()
            . ' (' . zeitraum_text($Antrag->GetStart(), $Antrag->GetEnde()) . ') ';

        if ($entscheidung === 'genehmigen') {
            merke_meldung($text . 'genehmigt.', 'erfolg', ['Im Kalender ansehen', 'kalender.php']);
        } else {
            $weiter = $zurueck === 'index' ? ['Alle Anträge', 'genehmigungen.php'] : ['Zur Übersicht', 'index.php'];
            merke_meldung($text . 'abgelehnt. Die Begründung sieht der Antragsteller auf seiner Übersicht.', 'hinweis', $weiter);
        }

        redirect($zurueck === 'index' ? 'index.php' : 'genehmigungen.php');
    } catch (CFachlicherFehler $Fehler) {
        $fehler[] = $Fehler->getMessage();
    }
}

$anzahl = $ListeAntraege->Count();

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Genehmigen geht nur, solange das Fahrzeug im Zeitraum frei ist. Eine Ablehnung braucht eine
    Begründung, die der Antragsteller auf seiner Übersicht liest.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section aria-labelledby="offene-antraege">
    <h2 class="abschnitt" id="offene-antraege">
        Offen
        <span class="abschnitt__wert">
            <?= $anzahl === 0 ? 'keine Anträge' : e((string) $anzahl) . ($anzahl === 1 ? ' Antrag' : ' Anträge') ?>
        </span>
        <?php if ($anzahl > 1): ?>
            <span class="abschnitt__zusatz">nächste Fahrt zuerst</span>
        <?php endif; ?>
    </h2>

    <?php foreach ($ListeAntraege->GetAlle() as $Antrag): ?>
        <?php require __DIR__ . '/includes/schild-antrag.php'; ?>
    <?php endforeach; ?>

    <?php if ($anzahl === 0): ?>
        <div class="schild schild--leer">
            <p>Keine offenen Anträge. Neue Anträge erscheinen hier, sobald jemand ein Fahrzeug bucht, das eine Genehmigung braucht.</p>
            <a class="button button--zweitrangig" href="<?= url('kalender.php') ?>">Zum Kalender</a>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
