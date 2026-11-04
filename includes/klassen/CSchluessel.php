<?php
/**
 * Ein Wert aus einer Schlüsseltabelle, z. B. Zweck 1 „Montage“.
 */

declare(strict_types=1);

class CSchluessel
{
    private int $m_ID;
    private string $m_Bezeichnung;
    private ?string $m_Plural;

    /**
     * Legt den Wert an. Die Mehrzahl gibt es nur bei Fahrzeugarten.
     */
    public function __construct(int $in_ID, string $in_Bezeichnung, ?string $in_Plural = null)
    {
        $this->m_ID          = $in_ID;
        $this->m_Bezeichnung = $in_Bezeichnung;
        $this->m_Plural      = $in_Plural;
    }

    public function GetID(): int
    {
        return $this->m_ID;
    }

    public function GetBezeichnung(): string
    {
        return $this->m_Bezeichnung;
    }

    public function GetPlural(): ?string
    {
        return $this->m_Plural;
    }
}
