<?php
/**
 * Anmeldung (Schritt 1 in jedem Anwendungsfall).
 *
 * Der Nutzer wählt seinen Namen aus einer Auswahlliste und gibt sein
 * persönliches Passwort ein (siehe docs/technisches-konzept.md, „Anmeldung“).
 * Bei einem Fehler gibt es eine neutrale Meldung, ohne Angabe, ob Name oder
 * Passwort falsch war.
 *
 * Prototyp: Nutzer und Passwort-Hashes kommen aus includes/beispieldaten.php
 * (Passwort aller Testnutzer: „fuhrpark“). Mit Datenbank:
 *
 *   SELECT id, vorname, nachname FROM nutzer ORDER BY nachname, vorname
 *   SELECT id, rolle, passwort_hash FROM nutzer WHERE id = ?
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Anmelden';
$fehler    = null;
$nutzerId  = 0;

// Abmelden: Session leeren und zurück zur Anmeldung.
// Muss vor jeder HTML-Ausgabe passieren, weil redirect() einen Header sendet.
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect('login.php');
}

$auswahl = nutzer_auswahl();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nutzerId = (int) (is_string($_POST['nutzer'] ?? null) ? $_POST['nutzer'] : 0);
    $passwort = is_string($_POST['passwort'] ?? null) ? $_POST['passwort'] : '';

    if ($nutzerId === 0 || $passwort === '') {
        $fehler = 'Bitte wählen Sie Ihren Namen und geben Sie Ihr Passwort ein.';
    } else {
        $datensatz = beispiel_nutzer()[$nutzerId] ?? null;

        if ($datensatz !== null && password_verify($passwort, $datensatz['passwort_hash'])) {
            session_regenerate_id(true);       // schützt vor Session-Fixation
            $_SESSION['user_id'] = $nutzerId;
            $_SESSION['rolle']   = $datensatz['rolle'];
            redirect('index.php');
        }

        $fehler = 'Name oder Passwort ist falsch.';
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fehler !== null): ?>
    <p class="alert"><?= e($fehler) ?></p>
<?php endif; ?>

<form class="form" method="post" action="<?= url('login.php') ?>">
    <div class="form__row">
        <label class="form__label" for="nutzer">Name</label>
        <select class="form__input" id="nutzer" name="nutzer" required autocomplete="username">
            <option value="">bitte wählen</option>
            <?php foreach ($auswahl as $id => $name): ?>
                <option value="<?= e((string) $id) ?>"<?= $id === $nutzerId ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form__row">
        <label class="form__label" for="passwort">Passwort</label>
        <input class="form__input" type="password" id="passwort" name="passwort"
               autocomplete="current-password" required>
    </div>

    <button class="button" type="submit">Anmelden</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
