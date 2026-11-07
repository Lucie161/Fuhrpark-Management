<?php
/**
 * Anträge genehmigen, nur für den Fuhrparkleiter.
 *
 * Alle offenen Anträge, älteste zuerst. Der Fahrer steht nur dabei, wenn er
 * nicht selbst beantragt hat („Buchen für“). Genehmigen geht nur, wenn das
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

        merke_meldung(
            'Antrag von ' . $Antrag->GetAntragstellerName() . ' für ' . $Antrag->GetFahrzeugName()
                . ' (' . zeitraum_text($Antrag->GetStart(), $Antrag->GetEnde()) . ') '
                . ($entscheidung === 'genehmigen' ? 'genehmigt.' : 'abgelehnt.'),
            $entscheidung === 'genehmigen' ? 'erfolg' : 'hinweis',
        );
        redirect($zurueck === 'index' ? 'index.php' : 'genehmigungen.php');
    } catch (CFachlicherFehler $Fehler) {
        $fehler[] = $Fehler->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    <?php if ($ListeAntraege->Count() === 0): ?>
        Keine offenen Anträge.
    <?php else: ?>
        <?= e((string) $ListeAntraege->Count()) ?>
        <?= $ListeAntraege->Count() === 1 ? 'Antrag wartet' : 'Anträge warten' ?> auf Ihre Entscheidung,
        älteste zuerst.
    <?php endif; ?>
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<table class="table">
    <thead>
        <tr>
            <th>Beantragt am</th>
            <th>Antragsteller</th>
            <th>Fahrzeug</th>
            <th>Zeitraum</th>
            <th>Zweck</th>
            <th class="table__num">Personen</th>
            <th>Entscheidung</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ListeAntraege->GetAlle() as $Antrag): ?>
            <?php
            $id = $Antrag->GetID();
            $hindernis = $Antrag->GetGenehmigungHindernis();
            ?>
            <tr>
                <td>
                    <?= e($Antrag->GetBeantragtAm()->format('d.m.Y')) ?>
                    <span class="table__zusatz"><?= e($Antrag->GetBeantragtAm()->format('H:i')) ?> Uhr</span>
                </td>
                <td>
                    <?= e($Antrag->GetAntragstellerName()) ?>
                    <?php if ($Antrag->GetFahrerID() !== $Antrag->GetAntragstellerID()): ?>
                        <span class="table__zusatz">für <?= e($Antrag->GetFahrerName()) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= url('fahrzeug.php?id=' . $Antrag->GetFahrzeugID()) ?>"><?= e($Antrag->GetFahrzeugName()) ?></a>
                </td>
                <td><?= e(zeitraum_text($Antrag->GetStart(), $Antrag->GetEnde())) ?></td>
                <td><?= e($Zwecke->GetBezeichnung($Antrag->GetZweckID())) ?></td>
                <td class="table__num"><?= e((string) $Antrag->GetPersonenanzahl()) ?></td>
                <td>
                    <?php require __DIR__ . '/includes/antrag-entscheidung.php'; ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if ($ListeAntraege->Count() === 0): ?>
            <tr>
                <td colspan="7" class="table__empty">Keine offenen Anträge.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
