<?php
namespace App\Parser;

final class Normalizador
{
    /**
     * @return array{0:string,1:bool,2:int}
     *         [codigo_normalizado, eh_fragmento, linhas_adicionadas]
     */
    public function normalizar(string $raw): array
    {
        $s = ltrim($raw);

        // Já é arquivo PHP completo?
        if (preg_match('/^\<\?php\b/u', $s)) {
            return [$raw, false, 0];
        }

        $linhasAdd = 0;
        $isFrag = true;

        // Heurísticas simples de detecção
        $startsWith = fn(string $re) => (bool)preg_match($re.'u', $s);

        $wrapAsFile = function (string $body) use (&$linhasAdd): string {
            $prefix = "<?php\n";
            $linhasAdd = substr_count($prefix, "\n");
            return $prefix . $body . "\n";
        };

        $wrapAsFunction = function (string $body) use (&$linhasAdd): string {
            $prefix = "<?php\nfunction __tmp__() {\n";
            $suffix = "\n}\n";
            $linhasAdd = substr_count($prefix, "\n");
            return $prefix . rtrim($body) . $suffix;
        };

        $wrapAsClassMethod = function (string $method) use (&$linhasAdd): string {
            $prefix = "<?php\nclass __Tmp__ {\n";
            $suffix = "\n}\n";
            $linhasAdd = substr_count($prefix, "\n");
            return $prefix . rtrim($method) . $suffix;
        };

        // 1) Fragments que já parecem "arquivo" (namespace/declarações topo)
        if ($startsWith('/^(namespace\s+[A-Za-z0-9_\\\\]+;\s*)/')) {
            return [$wrapAsFile($s), true, $linhasAdd];
        }
        if ($startsWith('/^(use\s+[A-Za-z0-9_\\\\]+(?:\s+as\s+[A-Za-z0-9_]+)?\s*;)/')) {
            return [$wrapAsFile($s), true, $linhasAdd];
        }
        if ($startsWith('/^(class|interface|trait|enum)\b/')) {
            return [$wrapAsFile($s), true, $linhasAdd];
        }
        if ($startsWith('/^function\b/')) {
            // Função solta é válida no topo do arquivo
            return [$wrapAsFile($s), true, $linhasAdd];
        }

        // 2) Método de classe selecionado (public/protected/private function …)
        if ($startsWith('/^(public|protected|private)\s+function\b/')) {
            return [$wrapAsClassMethod($s), true, $linhasAdd];
        }

        // 3) Propriedade de classe selecionada (public/private/protected …;)
        if ($startsWith('/^(public|protected|private)\b/')) {
            // ainda que não seja function, tratamos como trecho de classe
            return [$wrapAsClassMethod($s), true, $linhasAdd];
        }

        // 4) Só o corpo (bloco) — embrulhar como função temporária
        // Heurística: contém ; ou {…} mas não declarações conhecidas
        if ($startsWith('/[;{}]/')) {
            return [$wrapAsFunction($s), true, $linhasAdd];
        }

        // 5) Fallback: arquivo simples
        return [$wrapAsFile($s), true, $linhasAdd];
    }
}
