<?php

namespace Parser;

use PhpParser\Lexer\Emulative;
use PhpParser\Parser\Php7;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ErrorHandler\Collecting;
use Analyser\DocTagger;
use Throwable;

/**
 * Class AstService
 * 
 * Serviço responsável por analisar e mapear código fonte em uma estrutura de árvore sintática.
 */
class AstService {

/**
 * Mapeia o código fonte para uma estrutura de árvore abstrata.
 * 
 * @param string $sCode O código fonte a ser mapeado.
 * @param int $iAddedLines O número de linhas adicionadas ao código.
 * @return array A estrutura de árvore abstrata resultante do mapeamento.
 */
    public function map(string $sCode, int $iAddedLines = 0): array {
        $oParser = new Php7(new Emulative());
        $oErrors = new Collecting();

        try {
            $aAst = $oParser->parse($sCode, $oErrors);
        } catch (Throwable $oError) {
            $aAst = null;
        }

        $aErrorList = [];
        foreach ($oErrors->getErrors() as $oError) {
            $aErrorList[] = [
                'message'   => $oError->getMessage(),
                'startLine' => $oError->getStartLine(),
                'endLine'   => $oError->getEndLine(),
            ];
        }

        if ($aAst === null) {
            if (empty($aErrorList)) {
                $aErrorList[] = ['message' => 'Falha no parser.'];
            }
            return [[], $aErrorList];
        }

        $oTraverser = new NodeTraverser();
        $oTraverser->addVisitor(new NameResolver());

        $oTagger = new DocTagger();
        $oTraverser->addVisitor($oTagger);
        $oTraverser->traverse($aAst);
        $iCount = count($oTagger->items);
        for ($i = 0, $n = $iCount; $i < $n; $i++) {
            foreach (['line', 'doc_start', 'doc_end'] as $k) {
                if (isset($oTagger->items[$i][$k]) && is_int($oTagger->items[$i][$k])) {
                    $oTagger->items[$i][$k] = max(1, $oTagger->items[$i][$k] - $iAddedLines);
                }
            }
        }

        return [$oTagger->items, $aErrorList];
    }
}
