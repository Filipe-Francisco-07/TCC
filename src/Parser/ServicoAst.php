<?php
namespace App\Parser;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser\Php7;

final class ServicoAst {

    public function analisarCodigo(string $sCodigo): array {
        $oParser = new Php7(new Emulative());
        $oErros  = new Collecting();

        try { $aAst = $oParser->parse($sCodigo, $oErros); }
        catch (\Throwable $oEx) { $aAst = null; }

        $aMsgs = [];
        foreach ($oErros->getErrors() as $oErr) {
            $aMsgs[] = [
                'mensagem'     => $oErr->getMessage(),
                'linha_inicio' => $oErr->getStartLine(),
                'linha_fim'    => $oErr->getEndLine(),
            ];
        }

        if ($aAst === null) {
            return [[], $aMsgs ?: [['mensagem' => 'Falha ao parsear.']]];
        }

        $oTrav = new NodeTraverser();
        $oTrav->addVisitor(new NameResolver());
        $oTrav->traverse($aAst);

        return [$aAst, $aMsgs];
    }

    public function analisarArquivo(string $sCaminho): array {
        $sCodigo = @file_get_contents($sCaminho);
        if ($sCodigo === false) {
            return [[], [['mensagem' => "Arquivo não encontrado: {$sCaminho}"]]];
        }
        return $this->analisarCodigo($sCodigo);
    }
}
