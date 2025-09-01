<?php
namespace Generator;

final class AplicadorDocumentacao {

    public function aplicar(string $sConteudo, array $aDocs): string {
        $sConteudo = str_replace("\r\n", "\n", $sConteudo);
        $aLinhas   = explode("\n", $sConteudo);

        $iTotal = count($aLinhas);
        for ($i = 0; $i < $iTotal; $i++) {
            $sLinha = $aLinhas[$i];

            if (!preg_match('/^\s*\{\{\s*(doc_[A-Za-z0-9_-]+)\s*\}\}\s*$/', $sLinha, $m)) {
                continue;
            }
            $sId = $m[1];
            if (!isset($aDocs[$sId])) continue;

            // identação da própria linha do placeholder
            preg_match('/^([ \t]*)/', $sLinha, $mi);
            $sIndent = $mi[1] ?? '';

            // fallback: identação da próxima linha útil
            if ($sIndent === '' && $i + 1 < $iTotal) {
                $j = $i + 1;
                while ($j < $iTotal && trim($aLinhas[$j]) === '') { $j++; }
                if ($j < $iTotal && preg_match('/^([ \t]+)/', $aLinhas[$j], $mn)) {
                    $sIndent = $mn[1];
                }
            }

            $aLinhas[$i] = $this->paraDocblockComIdentacao($aDocs[$sId], $sIndent);
        }

        return implode("\n", $aLinhas);
    }

    private function paraDocblockComIdentacao(string $sTexto, string $sIndent = ''): string {
        $sTexto = trim(str_replace("\r\n", "\n", $sTexto));

        if (!str_starts_with($sTexto, '/**')) {
            $aLinhas = $sTexto === '' ? ['Documentação gerada.'] : explode("\n", $sTexto);
            $aLinhas = array_map(fn($l) => ' * ' . ltrim(preg_replace('/^\*\s*/', '', $l)), $aLinhas);
            $sTexto  = "/**\n" . implode("\n", $aLinhas) . "\n */";
        }

        $aOut = array_map(fn($l) => $sIndent . $l, explode("\n", $sTexto));
        return implode("\n", $aOut);
    }
}
