<?php
/**
 * Anmeldung.
 *
 * Zeigt das Formular und verarbeitet den POST-Request. Die eigentliche
 * Prüfung der Zugangsdaten gegen die Datenbank ist noch offen (siehe TODO).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Anmelden';
$fehler    = null;
$benutzer  = '';

// Abmelden: Session leeren und zurück zur Startseite.
// Muss vor jeder HTML-Ausgabe passieren, weil redirect() einen Header sendet.
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $benutzer = trim($_POST['benutzer'] ?? '');
    $passwort = $_POST['passwort'] ?? '';

    if ($benutzer === '' || $passwort === '') {
        $fehler = 'Bitte Benutzername und Passwort ausfüllen.';
    } else {
        // TODO: Zugangsdaten gegen die Datenbank prüfen, sobald die
        // Tabelle `benutzer` steht. Vorgesehener Ablauf:
        //
        //   $stmt = db()->prepare('SELECT id, passwort_hash FROM benutzer WHERE benutzername = ?');
        //   $stmt->execute([$benutzer]);
        //   $datensatz = $stmt->fetch();
        //
        //   if ($datensatz && password_verify($passwort, $datensatz['passwort_hash'])) {
        //       session_regenerate_id(true);       // schützt vor Session-Fixation
        //       $_SESSION['user_id'] = $datensatz['id'];
        //       redirect('index.php');
        //   }
        //   $fehler = 'Benutzername oder Passwort ist falsch.';
        //
        // Passwörter werden dabei nie im Klartext gespeichert, sondern mit
        // password_hash($passwort, PASSWORD_DEFAULT) abgelegt.
        $fehler = 'Die Anmeldung ist noch nicht angebunden.';
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($fehler !== null): ?>
    <p class="alert"><?= e($fehler) ?></p>
<?php endif; ?>

<form class="form" method="post" action="<?= url('login.php') ?>">
    <div class="form__row">
        <label class="form__label" for="benutzer">Benutzername</label>
        <input class="form__input" type="text" id="benutzer" name="benutzer"
               value="<?= e($benutzer) ?>" autocomplete="username" required>
    </div>

    <div class="form__row">
        <label class="form__label" for="passwort">Passwort</label>
        <input class="form__input" type="password" id="passwort" name="passwort"
               autocomplete="current-password" required>
    </div>

    <button class="button" type="submit">Anmelden</button>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
