<?php
/**
 * Ein Foto zu einem Schaden. Die Datei liegt unter zufälligem Namen in
 * uploads/schaeden/, ausgeliefert wird sie über foto.php.
 */

declare(strict_types=1);

class CSchadensfoto
{
    // Höchstens 3 Fotos zu je 5 MB. Die Grenzen liegen unter post_max_size
    // (XAMPP: 40 MB).
    public const MAX_ANZAHL = 3;
    public const MAX_BYTES  = 5 * 1024 * 1024;

    // Erlaubte Dateitypen => Endung der abgelegten Datei
    private const TYPEN = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private const ORDNER = __DIR__ . '/../../uploads/schaeden/';

    private int $m_ID;
    private int $m_SchadenID;
    private string $m_Datei;

    /**
     * Legt das Objekt aus einer Zeile der Tabelle schadensfotos an.
     */
    public function __construct(array $in_Zeile)
    {
        $this->m_ID        = (int) $in_Zeile['id'];
        $this->m_SchadenID = (int) $in_Zeile['schaden_id'];
        $this->m_Datei     = $in_Zeile['datei'];
    }

    /**
     * Lädt ein Foto. Gibt es es nicht, null.
     */
    public static function LoadByID(int $in_ID): ?CSchadensfoto
    {
        $Statement = db()->prepare('SELECT * FROM schadensfotos WHERE id = ?');
        $Statement->execute([$in_ID]);
        $Zeile = $Statement->fetch();

        return $Zeile !== false ? new CSchadensfoto($Zeile) : null;
    }

    /**
     * Lädt die Fotos eines Schadens in der Reihenfolge des Hochladens.
     */
    public static function LadenZuSchaden(int $in_SchadenID): array
    {
        $Statement = db()->prepare('SELECT * FROM schadensfotos WHERE schaden_id = ? ORDER BY id');
        $Statement->execute([$in_SchadenID]);

        $Fotos = [];

        foreach ($Statement->fetchAll() as $Zeile) {
            $Fotos[] = new CSchadensfoto($Zeile);
        }

        return $Fotos;
    }

    /**
     * Prüft die hochgeladenen Fotos aus <input type="file" name="fotos[]" multiple>.
     *
     * Der Dateityp wird am Inhalt erkannt, nicht an Endung oder Browserangabe,
     * beides lässt sich fälschen. Liefert [gültige Fotos, Fehlermeldungen];
     * die gültigen Fotos gehen an Create().
     */
    public static function Pruefe(array $in_Dateien): array
    {
        $Fotos  = [];
        $Fehler = [];

        foreach ($in_Dateien['error'] ?? [] as $i => $Code) {
            $Name = (string) $in_Dateien['name'][$i];

            // Leeres Feld: nichts ausgewählt
            if ($Code === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            // Zu groß, unvollständig oder kein Foto: mit Grund überspringen
            if ($Code === UPLOAD_ERR_INI_SIZE || $Code === UPLOAD_ERR_FORM_SIZE || $in_Dateien['size'][$i] > self::MAX_BYTES) {
                $Fehler[] = '„' . $Name . '“ ist größer als ' . intdiv(self::MAX_BYTES, 1024 * 1024) . ' MB.';
                continue;
            }

            if ($Code !== UPLOAD_ERR_OK || !is_uploaded_file($in_Dateien['tmp_name'][$i])) {
                $Fehler[] = '„' . $Name . '“ konnte nicht hochgeladen werden.';
                continue;
            }

            $Typ = (new finfo(FILEINFO_MIME_TYPE))->file($in_Dateien['tmp_name'][$i]);

            if (!isset(self::TYPEN[$Typ])) {
                $Fehler[] = '„' . $Name . '“ ist kein Foto im Format JPEG, PNG oder WebP.';
                continue;
            }

            $Fotos[] = ['tmp_name' => $in_Dateien['tmp_name'][$i], 'typ' => $Typ];
        }

        if (count($Fotos) > self::MAX_ANZAHL) {
            $Fehler[] = 'Bitte höchstens ' . self::MAX_ANZAHL . ' Fotos anhängen.';
        }

        return [$Fotos, $Fehler];
    }

    /**
     * Legt ein geprüftes Foto (aus Pruefe()) unter zufälligem Namen ab und
     * trägt es zum Schaden ein. Läuft in der Transaktion des Aufrufers.
     */
    public static function Create(int $in_SchadenID, array $in_Foto): CSchadensfoto
    {
        // Datei unter neuem Namen ablegen; der Name des Nutzers wird nicht
        // übernommen
        $Datei = bin2hex(random_bytes(16)) . '.' . self::TYPEN[$in_Foto['typ']];

        if (!move_uploaded_file($in_Foto['tmp_name'], self::ORDNER . $Datei)) {
            throw new CFachlicherFehler('Ein Foto konnte nicht gespeichert werden. Bitte versuchen Sie es noch einmal oder ohne Foto.');
        }

        // Eintragen; schlägt das fehl, die Datei wieder entfernen
        try {
            $Statement = db()->prepare('INSERT INTO schadensfotos (schaden_id, datei) VALUES (?, ?)');
            $Statement->execute([$in_SchadenID, $Datei]);
        } catch (Throwable $Fehler) {
            unlink(self::ORDNER . $Datei);
            throw $Fehler;
        }

        return self::LoadByID((int) db()->lastInsertId());
    }

    // --- Getter -------------------------------------------------------------

    public function GetID(): int
    {
        return $this->m_ID;
    }

    public function GetSchadenID(): int
    {
        return $this->m_SchadenID;
    }

    /**
     * Dateiname in uploads/schaeden/.
     */
    public function GetDatei(): string
    {
        return $this->m_Datei;
    }

    /**
     * Vollständiger Pfad der Datei, für foto.php.
     */
    public function GetPfad(): string
    {
        return self::ORDNER . $this->m_Datei;
    }

    /**
     * Dateityp für den Content-Type, aus der Endung; null bei einer fremden
     * Endung.
     */
    public function GetTyp(): ?string
    {
        $Endung = pathinfo($this->m_Datei, PATHINFO_EXTENSION);
        $Typ = array_search($Endung, self::TYPEN, true);

        return $Typ !== false ? $Typ : null;
    }
}
