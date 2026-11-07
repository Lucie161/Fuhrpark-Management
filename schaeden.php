<?php
/**
 * Schäden, nur für den Fuhrparkleiter.
 *
 * Alle gemeldeten Schäden, zuerst die offenen, dann die behobenen, jeweils
 * neueste zuerst: Schäden aus Rückgaben (Kleinschaden oder mit Wartung) und
 * vorhandene Schäden, die ein Fahrer bei Fahrtbeginn notiert hat („bei
 * Übernahme“). Gemeldet hat jeweils der Fahrer der Buchung. Bemerkungen ohne
 * Schaden stehen nur im Fahrtenbuch.
 *
 * Bei Luxusautos sperrt schon ein Kleinschaden das Fahrzeug
 * (CSchaden::Sperrt()); er wird wie ein Schaden mit Wartung durch das
 * Freigeben behoben und ist markiert.
 *
 * Ein offener Schaden lässt sich als behoben markieren. Hält er das Fahrzeug
 * in Wartung, wird es stattdessen freigegeben; das Formular schickt an
 * fahrzeuge.php, das Freigeben gilt dort zugleich als „behoben“.
 *
 * Daten: CListeSchaeden::LadenAlle() mit dem Namen des Fahrers, die Fahrzeuge
 * aus CListeFahrzeuge::LadenAlle(). Speichern über CSchaden::Beheben().
 *
 * Schadensfotos sieht nur der Fuhrparkleiter (Datenschutz). Sie liegen nicht
 * öffentlich, foto.php liefert sie nach Prüfung der Rolle aus.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$pageTitle = 'Schäden';

$ich = aktueller_nutzer();

// --- „Behoben“ verarbeiten --------------------------------------------------
// Läuft vor header.php, damit nach dem Speichern die Weiterleitung möglich
// ist. Die Regeln (Rolle, noch offen, nicht in Wartung) prüft CSchaden.

$fehler = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) (is_string($_POST['schaden'] ?? null) ? $_POST['schaden'] : 0);
    $Schaden = CSchaden::LoadByID($id);

    try {
        if ($Schaden === null) {
            throw new CFachlicherFehler('Diesen Schaden gibt es nicht.');
        }

        $Schaden->Beheben($ich);

        merke_meldung('Schaden an ' . CFahrzeug::LoadByID($Schaden->GetFahrzeugID())->GetName() . ' als behoben markiert.');
        redirect('schaeden.php');
    } catch (CFachlicherFehler $Fehler) {
        $fehler[] = $Fehler->getMessage();
    }
}

// --- Schäden laden ----------------------------------------------------------
// Offen und behoben getrennt, die Reihenfolge kommt aus der Abfrage.

$ListeSchaeden = new CListeSchaeden();
$ListeSchaeden->LadenAlle();

$offene   = array_filter($ListeSchaeden->GetAlle(), fn (CSchaden $Schaden): bool => $Schaden->IstOffen());
$behobene = array_filter($ListeSchaeden->GetAlle(), fn (CSchaden $Schaden): bool => !$Schaden->IstOffen());

// Alle Fahrzeuge, auch ausgemusterte: Ihre Schäden bleiben in der Liste.
$Fahrzeuge = new CListeFahrzeuge();
$Fahrzeuge->LadenAlle();

$Schweregrade = new CListeSchluessel('schweregrade');
$Anlaesse = new CListeSchluessel('schadensanlaesse');

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Gemeldete Schäden, zuerst die offenen. <?= e((string) count($offene)) ?> offen, davon
    <?= e((string) $ListeSchaeden->CountSperrende()) ?> mit Wartung. Ein Kleinschaden sperrt das Fahrzeug nicht und
    muss nicht sofort behoben werden; die Fahrer sehen ihn vor Fahrtbeginn und müssen ihn nicht
    erneut melden. Ausnahme Luxusautos: Dort sperrt jeder Schaden bis zur Freigabe. Bemerkungen ohne Schaden stehen im Fahrtenbuch.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php foreach (['Offen' => $offene, 'Behoben' => $behobene] as $titel => $liste): ?>
    <section class="section">
        <h2><?= e($titel) ?></h2>

        <table class="table">
            <thead>
                <tr>
                    <th>Gemeldet</th>
                    <th>Fahrzeug</th>
                    <th>Fahrer</th>
                    <th>Art</th>
                    <th>Beschreibung</th>
                    <th><?= $titel === 'Offen' ? 'Aktionen' : 'Behoben am' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($liste as $Schaden): ?>
                    <?php $Fahrzeug = $Fahrzeuge->GetByID($Schaden->GetFahrzeugID()); ?>
                    <tr>
                        <td><?= e($Schaden->GetGemeldetAm()->format('d.m.Y')) ?></td>
                        <td>
                            <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>"><?= e($Fahrzeug->GetName()) ?></a>
                            <?php if ($Fahrzeug->IstInWartung() && $Schaden->IstOffen()): ?>
                                <span class="table__zusatz">in Wartung</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($Schaden->GetFahrerName()) ?></td>
                        <td>
                            <span class="badge<?= $Schaden->Sperrt() ? ' badge--abgelehnt' : '' ?>"><?= e($Schweregrade->GetBezeichnung($Schaden->GetSchwereID())) ?></span>
                            <?php if ($Schaden->GetSchwereID() === SCHWERE_KLEIN && $Schaden->Sperrt() && $Schaden->IstOffen()): ?>
                                <?php if ($Schaden->HaeltInWartung()): ?>
                                    <span class="table__zusatz">Luxusauto: sperrt das Fahrzeug</span>
                                <?php elseif ($Schaden->GetAnlassID() === ANLASS_UEBERNAHME): ?>
                                    <span class="table__zusatz">Luxusauto: wird nach der Rückgabe gesperrt</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <span class="table__zusatz"><?= e($Anlaesse->GetBezeichnung($Schaden->GetAnlassID())) ?></span>
                        </td>
                        <td>
                            <?= e($Schaden->GetBeschreibung()) ?>

                            <?php if ($Schaden->GetFotos() !== []): ?>
                                <ul class="schadensfotos" aria-label="Fotos zum Schaden">
                                    <?php foreach ($Schaden->GetFotos() as $nr => $Foto): ?>
                                        <li>
                                            <a href="<?= url('foto.php?id=' . $Foto->GetID()) ?>" target="_blank">
                                                <img class="schadensfotos__bild" src="<?= url('foto.php?id=' . $Foto->GetID()) ?>"
                                                     alt="Foto <?= e((string) ($nr + 1)) ?> zum Schaden" loading="lazy">
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$Schaden->IstOffen()): ?>
                                <?= e($Schaden->GetBehobenAm()->format('d.m.Y')) ?>
                            <?php elseif ($Schaden->HaeltInWartung()): ?>
                                <form method="post" action="<?= url('fahrzeuge.php') ?>">
                                    <input type="hidden" name="fahrzeug" value="<?= e((string) $Fahrzeug->GetID()) ?>">
                                    <input type="hidden" name="aktion" value="freigeben">
                                    <button class="button button--klein" type="submit">Fahrzeug freigeben</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?= url('schaeden.php') ?>">
                                    <input type="hidden" name="schaden" value="<?= e((string) $Schaden->GetID()) ?>">
                                    <button class="button button--klein" type="submit">Als behoben markieren</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($liste === []): ?>
                    <tr>
                        <td colspan="6" class="table__empty"><?= $titel === 'Offen' ? 'Keine offenen Schäden.' : 'Noch keine behobenen Schäden.' ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
