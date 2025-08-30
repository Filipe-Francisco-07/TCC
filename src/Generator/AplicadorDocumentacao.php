<?php
namespace Generator;

final class AplicadorDocumentacao {

    public function aplicar(string $sConteudo, array $aDocs): string {
        foreach ($aDocs as $sId => $sDoc) {
            $sPH = '{{' . $sId . '}}';
            $sConteudo = str_replace($sPH, $this->paraDocblock($sDoc), $sConteudo);
        }
        return $sConteudo;
    }

    private function paraDocblock(string $sTexto): string {
        $sTexto = trim(str_replace("\r\n", "\n", $sTexto));
        if (str_starts_with($sTexto, '/**')) return $sTexto;

        $aLinhas = explode("\n", $sTexto);
        $aCorpo  = array_map(fn($l) => ' * ' . ltrim(preg_replace('/^\*\s*/', '', $l)), $aLinhas);
        return "/**\n" . implode("\n", $aCorpo) . "\n */";
    }
}
