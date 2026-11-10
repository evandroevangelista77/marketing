<?php

namespace GlpiPlugin\Marketing;

/** TCPDF com rodapé (título e página) para o PDF executivo. */
class ReportPdf extends \TCPDF
{
    public string $footerText = '';

    public function Header()
    {
        // sem cabeçalho automático: a capa e as seções desenham o próprio título
    }

    public function Footer()
    {
        $this->SetY(-9);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, $this->footerText, 0, 0, 'L');
        $this->Cell(0, 4, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}
