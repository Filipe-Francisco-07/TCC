<?php
namespace Analyser;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Comment\Doc;

final class MarcadorDocumentacao extends NodeVisitorAbstract {

    public array $aItens = [];
    private int  $iProxId = 1;
    private array $aPilhaClasse = [];

    public function enterNode(Node $oNo): void {
        if ($oNo instanceof Node\Stmt\ClassLike) {
            $this->aPilhaClasse[] = $this->fqnDe($oNo);
        }

        $sRotulo = $this->rotuloDe($oNo);
        if ($sRotulo === null) return;

        $aEntrada = $this->entradaBase($sRotulo, $oNo);

        if ($oNo instanceof Node\FunctionLike) {
            foreach ($oNo->getParams() as $oP) {
                $aEntrada['params'][] = [
                    'name'     => '$' . $oP->var->name,
                    'type'     => $this->tipoParaString($oP->type) ?? 'mixed',
                    'byRef'    => (bool)$oP->byRef,
                    'variadic' => (bool)$oP->variadic,
                    'default'  => $oP->default ? $oP->default->getType() : null,
                ];
            }
            $aEntrada['returnType'] = $this->tipoParaString($oNo->getReturnType()) ?? 'mixed';
        }

        $this->aItens[] = $aEntrada;
    }

    public function leaveNode(Node $oNo): void {
        if ($oNo instanceof Node\Stmt\ClassLike) array_pop($this->aPilhaClasse);
    }

    private function rotuloDe(Node $oNo): ?string {
        if ($oNo instanceof Node\Stmt\Class_)      return 'class';
        if ($oNo instanceof Node\Stmt\Interface_)  return 'interface';
        if ($oNo instanceof Node\Stmt\Trait_)      return 'trait';
        if ($oNo instanceof Node\Stmt\Enum_)       return 'enum';
        if ($oNo instanceof Node\Stmt\Function_)   return 'function';
        if ($oNo instanceof Node\Stmt\ClassMethod) return 'method';
        if ($oNo instanceof Node\Stmt\Property)    return 'property';
        if ($oNo instanceof Node\Stmt\ClassConst)  return 'constant';
        return null;
    }

    private function entradaBase(string $sRotulo, Node $oNo): array {
        $oDoc = $oNo->getDocComment();
        return [
            'id'         => 'doc_' . $this->iProxId++,
            'type'       => $sRotulo,
            'name'       => $this->nomeCurtoDe($oNo),
            'fqn'        => $this->fqnDe($oNo),
            'doc'        => $oDoc ? $oDoc->getText() : null,
            'doc_start'  => $oDoc instanceof Doc ? $oDoc->getStartLine() : null,
            'doc_end'    => $oDoc instanceof Doc ? $oDoc->getEndLine()   : null,
            'params'     => [],
            'returnType' => null,
            'line'       => $oNo->getStartLine(),
        ];
    }

    private function nomeCurtoDe(Node $oNo): ?string {
        if (property_exists($oNo, 'name') && $oNo->name instanceof Node\Identifier) {
            return $oNo->name->name;
        }
        if (property_exists($oNo, 'name') && $oNo->name instanceof Node\Name) {
            return $oNo->name->toString();
        }
        return null;
    }

    private function fqnDe(Node $oNo): ?string {
        if (property_exists($oNo, 'namespacedName') && $oNo->namespacedName) {
            return $oNo->namespacedName->toString();
        }
        if ($oNo instanceof Node\Stmt\ClassMethod) {
            $sClasse = end($this->aPilhaClasse) ?: null;
            $sMetodo = $oNo->name->toString();
            return $sClasse ? ($sClasse . '::' . $sMetodo) : $sMetodo;
        }
        return $this->nomeCurtoDe($oNo);
    }

    private function tipoParaString($mTipo): ?string {
        if ($mTipo === null) return null;
        if ($mTipo instanceof Node\NullableType)     return '?' . $this->tipoParaString($mTipo->type);
        if ($mTipo instanceof Node\UnionType)        return implode('|', array_map(fn($t) => $this->tipoParaString($t), $mTipo->types));
        if ($mTipo instanceof Node\IntersectionType) return implode('&', array_map(fn($t) => $this->tipoParaString($t), $mTipo->types));
        if ($mTipo instanceof Node\Identifier || $mTipo instanceof Node\Name) return $mTipo->toString();
        return (string)$mTipo;
    }
}
