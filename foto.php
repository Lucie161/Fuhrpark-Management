<?php
/**
 * Liefert ein Schadensfoto aus, nur an den Fuhrparkleiter.
 *
 * Aufruf: foto.php?id=3 (ID aus schadensfotos), z. B. als <img src> in
 * schaeden.php. Der Ordner uploads/schaeden/ ist von außen gesperrt; nur über
 * diese Seite kommt man an die Dateien, und sie prüft die Rolle.
 *
 * Kein HTML, kein header.php: Die Antwort ist das Bild selbst.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

nur_fuer_rolle(ROLLE_FUHRPARKLEITER);

$id = (int) (is_string($_GET['id'] ?? null) ? $_GET['id'] : 0);
$Foto = CSchadensfoto::LoadByID($id);

// Unbekannte ID, fehlende Datei oder fremde Endung: nicht gefunden
if ($Foto === null || $Foto->GetTyp() === null || !is_file($Foto->GetPfad())) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Foto nicht gefunden.');
}

// Bild ausliefern. nosniff: Der Browser nimmt den angegebenen Typ und rät
// nicht selbst. Zwischenspeichern nur im eigenen Browser.
header('Content-Type: ' . $Foto->GetTyp());
header('Content-Length: ' . filesize($Foto->GetPfad()));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($Foto->GetPfad());
