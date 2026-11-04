<?php
/**
 * Alle Werte einer Schlüsseltabelle, nach ID sortiert.
 *
 * Eine Klasse für alle sieben Tabellen, denn sie sind gleich aufgebaut.
 * Geladen wird beim ersten Zugriff und nur einmal:
 *   $Zwecke = new CListeSchluessel('zwecke');
 *   echo e($Zwecke->GetBezeichnung($zweckId));
 */

declare(strict_types=1);

class CListeSchluessel
{
    // Ein Tabellenname lässt sich nicht als Parameter binden, deshalb sind nur
    // diese Namen erlaubt.
    private const TABELLEN = [
        'rollen',
        'fahrzeugarten',
        'antriebe',
        'buchungsstatus',
        'zwecke',
        'schadensanlaesse',
        'schweregrade',
    ];

    private string $m_Tabelle;
    private array $m_Liste = [];
    private bool $m_ListeGeladen = false;

    /**
     * Merkt sich die Tabelle. Ein unbekannter Name ist ein Programmierfehler.
     */
    public function __construct(string $in_Tabelle)
    {
        if (!in_array($in_Tabelle, self::TABELLEN, true)) {
            throw new InvalidArgumentException('Unbekannte Schlüsseltabelle: ' . $in_Tabelle);
        }

        $this->m_Tabelle = $in_Tabelle;
    }

    /**
     * Liest die Tabelle beim ersten Aufruf.
     */
    private function Laden(): void
    {
        if ($this->m_ListeGeladen) {
            return;
        }

        // SELECT *, weil nur fahrzeugarten die Spalte plural hat.
        $Statement = db()->prepare('SELECT * FROM ' . $this->m_Tabelle . ' ORDER BY id');
        $Statement->execute();

        foreach ($Statement->fetchAll() as $Zeile) {
            $this->m_Liste[] = new CSchluessel((int) $Zeile['id'], $Zeile['bezeichnung'], $Zeile['plural'] ?? null);
        }

        $this->m_ListeGeladen = true;
    }

    /**
     * Alle Werte, für foreach in Auswahllisten und Filtern.
     */
    public function GetAlle(): array
    {
        $this->Laden();

        return $this->m_Liste;
    }

    public function Count(): int
    {
        $this->Laden();

        return count($this->m_Liste);
    }

    /**
     * Der i-te Wert, gezählt ab 0.
     */
    public function Get(int $in_i): ?CSchluessel
    {
        $this->Laden();

        return $this->m_Liste[$in_i] ?? null;
    }

    /**
     * Der Wert mit dieser ID, sonst null.
     */
    public function GetByID(int $in_ID): ?CSchluessel
    {
        foreach ($this->GetAlle() as $Schluessel) {
            if ($Schluessel->GetID() === $in_ID) {
                return $Schluessel;
            }
        }

        return null;
    }

    /**
     * Bezeichnung zur ID, für die Anzeige.
     */
    public function GetBezeichnung(int $in_ID): string
    {
        $Schluessel = $this->GetByID($in_ID);

        return $Schluessel !== null ? $Schluessel->GetBezeichnung() : 'unbekannt';
    }

    /**
     * Mehrzahl zur ID, nur bei Fahrzeugarten belegt.
     */
    public function GetPlural(int $in_ID): ?string
    {
        $Schluessel = $this->GetByID($in_ID);

        return $Schluessel !== null ? $Schluessel->GetPlural() : null;
    }
}
