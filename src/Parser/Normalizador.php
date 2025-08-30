<?php

namespace App\Parser;

final class Normalizador {

    /** @return array{0:string,1:bool,2:int} */
    public function normalizar(string $sCodigo): array {
        $iLinhasAdicionadas = 0;
        $sModificado = $sCodigo;
        $bParcial    = false;

        if (!preg_match('/^\s*<\?php/i', $sModificado)) {
            $sModificado = "<?php\n".$sModificado;
            $iLinhasAdicionadas += 1;
        }

        $bSomenteBloco        = preg_match('/^\s*[{].*[}]?\s*$/s', $sCodigo) === 1;
        $bDeclMetodoOuAtribut = preg_match('/^\s*(public|protected|private|static|\s)*(function\s+[A-Za-z_]\w*|\$[A-Za-z_]\w*|const\s+)/', $sCodigo) === 1;
        $bFuncaoLivre         = preg_match('/^\s*function\s+[A-Za-z_]\w*/', $sCodigo) === 1;
        $bJaEstrutural        = preg_match('/\b(class|interface|trait|enum)\b/', $sCodigo) === 1;

        if ($bSomenteBloco) {
            $sModificado .= "\nfunction __tmp__() {\n".$sCodigo."\n}\n";
            $iLinhasAdicionadas += 2; $bParcial = true;
        } elseif ($bDeclMetodoOuAtribut && !$bJaEstrutural) {
            $sModificado .= "\nclass __Tmp__ {\n".$sCodigo."\n}\n";
            $iLinhasAdicionadas += 2; $bParcial = true;
        } elseif (!$bFuncaoLivre && !$bJaEstrutural) {
            $sModificado .= "\nfunction __tmp__() {\n".$sCodigo."\n}\n";
            $iLinhasAdicionadas += 2; $bParcial = true;
        }

        return [$sModificado, $bParcial, $iLinhasAdicionadas];
    }
}
