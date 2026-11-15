<?php
/**
 * Schäden, nur für den Fuhrparkleiter.
 *
 * Oben die offenen Schäden als Schilder, neueste zuerst: Stand „In Wartung“
 * (der Schaden hält das Fahrzeug in Wartung) oder „Kleinschaden“, Felder
 * Gemeldet, Fahrer, Anlass, darunter Beschreibung und Fotos. Darunter die
 * behobenen Schäden als kompakte Tabelle. Gemeldet hat jeweils der Fahrer der
 * Buchung, bei der Rückgabe oder schon bei Fahrtbeginn („bei Übernahme“).
 * Bemerkungen ohne Schaden stehen nur im Fahrtenbuch.
 *
 * Bei Luxusautos sperrt schon ein Kleinschaden das Fahrzeug
 * (CSchaden::Sperrt()); er wird wie ein schwerer Schaden durch das
 * Freigeben behoben.
 *
 * Ein Kleinschaden lässt sich als behoben markieren. Hält der Schaden das
 * Fahrzeug in Wartung, wird stattdessen das Fahrzeug freigegeben; das
 * Formular schickt an fahrzeuge.php (mit zurueck=schaeden), das Freigeben
 * gilt dort zugleich als „behoben“. Das Wartungsende trägt der Fuhrparkleiter
 * in fahrzeuge.php ein (Link öffnet dort das Formular des Fahrzeugs).
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

        // Nächster Schritt: Im Steckbrief steht der Schaden nicht mehr offen.
        $fahrzeugID = $Schaden->GetFahrzeugID();
        merke_meldung(
            'Schaden an ' . CFahrzeug::LoadByID($fahrzeugID)->GetName() . ' als behoben markiert.',
            'erfolg',
            ['Zum Steckbrief', 'fahrzeug.php?id=' . $fahrzeugID]
        );
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

$Arten = new CListeSchluessel('fahrzeugarten');
$Anlaesse = new CListeSchluessel('schadensanlaesse');

$anzahlOffen = count($offene);
$anzahlWartung = $ListeSchaeden->CountSperrende();

require_once __DIR__ . '/includes/header.php';
?>

<p class="lead">
    Ein schwerer Schaden sperrt das Fahrzeug bis zur Freigabe, ein Kleinschaden nur bei Luxusautos.
</p>

<?php if ($fehler !== []): ?>
    <div class="alert">
        <?php foreach ($fehler as $meldung): ?>
            <p class="alert__zeile"><?= e($meldung) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Offene Schäden: je ein Schild -->
<section aria-labelledby="offene-schaeden">
    <h2 class="abschnitt" id="offene-schaeden">
        Offen
        <span class="abschnitt__wert">
            <?= $anzahlOffen === 0 ? 'keine Schäden' : e((string) $anzahlOffen) . ($anzahlOffen === 1 ? ' Schaden' : ' Schäden') ?>
        </span>
        <?php if ($anzahlWartung > 0): ?>
            <span class="abschnitt__zusatz">davon <?= e((string) $anzahlWartung) ?> in Wartung</span>
        <?php endif; ?>
    </h2>

    <?php foreach ($offene as $Schaden): ?>
        <?php
        $SchildFahrzeug = $Fahrzeuge->GetByID($Schaden->GetFahrzeugID());
        $fahrzeugID = $SchildFahrzeug->GetID();
        $inWartung = $Schaden->HaeltInWartung();
        $marke = $inWartung ? ['In Wartung', 'gelb'] : ['Kleinschaden', 'grau'];

        // Zweite Zeile im Text: was der Schaden für das Fahrzeug bedeutet
        if ($inWartung && $Schaden->GetSchwereID() === SCHWERE_KLEIN) {
            $folge = 'Luxusauto: Schon ein Kleinschaden sperrt das Fahrzeug bis zur Freigabe ('
                . $SchildFahrzeug->GetWartungText() . ').';
        } elseif ($inWartung) {
            $folge = 'Das Fahrzeug bleibt bis zur Freigabe gesperrt (' . $SchildFahrzeug->GetWartungText() . ').';
        } elseif ($Schaden->Sperrt()) {
            $folge = 'Luxusauto: Das Fahrzeug wird nach der Rückgabe gesperrt.';
        } else {
            $folge = 'Sperrt das Fahrzeug nicht.';
        }
        ?>
        <article class="schild" id="schaden-<?= e((string) $Schaden->GetID()) ?>">
            <?php require __DIR__ . '/includes/schild-kopf.php'; ?>

            <div class="schild__koerper">
                <dl class="felder">
                    <div class="feld">
                        <dt>Gemeldet</dt>
                        <dd><?= e($Schaden->GetGemeldetAm()->format('d.m.Y')) ?></dd>
                    </div>
                    <div class="feld">
                        <dt>Fahrer</dt>
                        <dd><?= e($Schaden->GetFahrerName()) ?></dd>
                    </div>
                    <div class="feld">
                        <dt>Anlass</dt>
                        <dd><?= e($Anlaesse->GetBezeichnung($Schaden->GetAnlassID())) ?></dd>
                    </div>
                </dl>

                <div class="schild__aktionen">
                    <div class="aktionen">
                        <?php if ($inWartung): ?>
                            <form method="post" action="<?= url('fahrzeuge.php') ?>">
                                <input type="hidden" name="fahrzeug" value="<?= e((string) $fahrzeugID) ?>">
                                <input type="hidden" name="aktion" value="freigeben">
                                <input type="hidden" name="zurueck" value="schaeden">
                                <button class="button button--klein" type="submit">Freigeben</button>
                            </form>
                            <a class="button button--klein button--zweitrangig"
                               href="<?= url('fahrzeuge.php?formular=' . $fahrzeugID . '#formular') ?>">
                                <?= $SchildFahrzeug->GetWartungBis() === null ? 'Wartungsende eintragen' : 'Ende ändern' ?>
                            </a>
                        <?php else: ?>
                            <form method="post" action="<?= url('schaeden.php') ?>">
                                <input type="hidden" name="schaden" value="<?= e((string) $Schaden->GetID()) ?>">
                                <button class="button button--klein" type="submit">Als behoben markieren</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="schild__text">
                <p class="schild__grund"><?= e($Schaden->GetBeschreibung()) ?></p>
                <p><?= e($folge) ?></p>

                <?php if ($Schaden->GetFotos() !== []): ?>
                    <ul class="schild__fotos" aria-label="Fotos zum Schaden">
                        <?php foreach ($Schaden->GetFotos() as $nr => $Foto): ?>
                            <li>
                                <a href="<?= url('foto.php?id=' . $Foto->GetID()) ?>" target="_blank">
                                    <img src="<?= url('foto.php?id=' . $Foto->GetID()) ?>"
                                         alt="Foto <?= e((string) ($nr + 1)) ?> zum Schaden" loading="lazy">
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>

    <?php if ($offene === []): ?>
        <div class="schild schild--leer">
            <p>Keine offenen Schäden. Neue Schäden melden die Fahrer bei der Rückgabe.</p>
        </div>
    <?php endif; ?>
</section>

<!-- Behobene Schäden: kompakte Tabelle, neueste zuerst -->
<section class="schaeden-behoben" aria-labelledby="behobene-schaeden">
    <h2 class="abschnitt" id="behobene-schaeden">
        Behoben
        <span class="abschnitt__wert"><?= e((string) count($behobene)) ?></span>
    </h2>

    <table class="table table--stapel">
        <thead>
            <tr>
                <th>Fahrzeug</th>
                <th>Gemeldet</th>
                <th>Art</th>
                <th>Stand</th>
                <th>Beschreibung</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($behobene as $Schaden): ?>
                <?php $Fahrzeug = $Fahrzeuge->GetByID($Schaden->GetFahrzeugID()); ?>
                <tr>
                    <td class="table__fahrzeug">
                        <a href="<?= url('fahrzeug.php?id=' . $Fahrzeug->GetID()) ?>"><?= e($Fahrzeug->GetHersteller() . ' ' . $Fahrzeug->GetModell()) ?></a>
                        <span class="table__zusatz"><?= e($Fahrzeug->GetKennzeichen()) ?></span>
                    </td>
                    <td data-label="Gemeldet">
                        <?= e($Schaden->GetGemeldetAm()->format('d.m.Y')) ?>
                        <span class="table__zusatz">von <?= e($Schaden->GetFahrerName()) ?></span>
                    </td>
                    <td data-label="Art">
                        <?= $Schaden->GetSchwereID() === SCHWERE_WARTUNG ? 'Schwerer Schaden' : 'Kleinschaden' ?>
                        <span class="table__zusatz"><?= e($Anlaesse->GetBezeichnung($Schaden->GetAnlassID())) ?></span>
                    </td>
                    <td class="table__stand" data-label="Stand">
                        <span class="marke marke--grau">Behoben</span>
                        <span class="table__zusatz">am <?= e($Schaden->GetBehobenAm()->format('d.m.Y')) ?></span>
                    </td>
                    <td class="table__breit" data-label="Beschreibung"><?= e($Schaden->GetBeschreibung()) ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if ($behobene === []): ?>
                <tr>
                    <td colspan="5" class="table__empty">Noch keine behobenen Schäden.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
