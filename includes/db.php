<?php
/**
 * Datenbank-Anbindung via PDO.
 */

declare(strict_types=1);

/**
 * Liefert die PDO-Verbindung.
 *
 * Die Verbindung wird erst beim ersten Aufruf aufgebaut und danach
 * wiederverwendet. Seiten, die (noch) keine Daten aus der Datenbank lesen,
 * funktionieren dadurch auch dann, wenn MySQL gar nicht läuft.
 *
 * Verwendung:
 *   $stmt = db()->prepare('SELECT * FROM fahrzeuge WHERE id = ?');
 *   $stmt->execute([$id]);
 *   $fahrzeug = $stmt->fetch();
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            // Fehler als Exception werfen, statt sie still zu schlucken.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // fetch() liefert assoziative Arrays statt doppelter Spalten.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Echte Prepared Statements der Datenbank nutzen.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        if (APP_ENV === 'dev') {
            exit(
                'Keine Verbindung zur Datenbank "' . DB_NAME . '": ' . $e->getMessage()
                . '<br>Läuft MySQL in XAMPP, und existiert die Datenbank bereits?'
            );
        }
        exit('Die Anwendung ist derzeit nicht verfügbar.');
    }

    return $pdo;
}
