<?php
namespace Generator;

final class ConstrutorPrompt {

    public function construir(array $aItem, string $sCodigoContexto): string {
        $sTipo  = $aItem['type'] ?? 'desconhecido';
        $sFqn   = $aItem['fqn']  ?? ($aItem['name'] ?? '');
        $iIniLn = (int)($aItem['line'] ?? 1);
        $iFimLn = (int)($aItem['endLine'] ?? ($iIniLn + 1));

        // corpo completo do elemento
        $aLinhas = preg_split('/\R/u', $sCodigoContexto);
        $iIni0   = max(0, $iIniLn - 1);
        $iFim0   = min(count($aLinhas), $iFimLn);
        $sTrecho = implode("\n", array_slice($aLinhas, $iIni0, $iFim0 - $iIni0));

        // metadados estruturais
        $aMeta = [
            'type'        => $sTipo,
            'fqn'         => $sFqn,
            'params'      => $aItem['params'] ?? [],
            'returnType'  => $aItem['returnType'] ?? null,
            'modificadores' => $aItem['modificadores'] ?? [],
            'atributos'   => $aItem['atributos'] ?? [],
            'heranca'     => $aItem['heranca'] ?? null,
            'tipos_uso'   => $aItem['tipos_uso'] ?? [],
            'operadores'  => $aItem['operadores'] ?? [],
            'operacao_principal' => $aItem['operacao_principal'] ?? null,
            'throws'      => $aItem['throws'] ?? [],
            'efeitos'     => $aItem['efeitos_colaterais'] ?? [],
            'retornos'    => $aItem['retornos'] ?? [],
            'complexidade'=> $aItem['complexidade'] ?? [],
            'checagens'   => $aItem['checagens'] ?? [],
            'linhas'      => ['start'=>$iIniLn, 'end'=>$iFimLn, 'loc'=> max(1, $iFimLn - $iIniLn + 1)],
            'chamadas'    => array_values(array_slice($aItem['chamadas'] ?? [], 0, 10)),
        ];
        $sMetaJson = json_encode($aMeta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $sAss = $sTipo.' '.$sFqn;
        $sRet = $aItem['returnType'] ?? 'mixed';

        $sRegras = match ($sTipo) {
            'function','method' =>
                "- Descreva objetivamente o que o corpo FAZ, não o nome.\n"
                . "- Uma frase de descrição. Linha em branco.\n"
                . "- @param para cada parâmetro na ordem, com propósito.\n"
                . "- @return {$sRet} coerente com o corpo.\n"
                . "- Não invente @throws. Só inclua se houver throw/declaração visível.",
            'class','interface','trait','enum' =>
                "- Papel/responsabilidade em 1–2 linhas. Sem @param/@return.",
            'property' =>
                "- Descrição curta. Use @var <tipo> descrição. Sem @param/@return.",
            'constant' =>
                "- Descrição curta. Sem @param/@return.",
            default =>
                "- Descrição curta baseada no corpo/metadata.",
        };

        return <<<PROMPT
Gere APENAS um DocBlock PHPDoc válido entre /** e */. Não use crases.
Se metadados e nomes divergirem do corpo, documente PELO CORPO.

Alvo: {$sAss} (linhas {$iIniLn}-{$iFimLn})

REGRAS:
{$sRegras}

METADADOS (JSON):
{$sMetaJson}

TRECHO DO CÓDIGO (início→fim do elemento):
{$sTrecho}
PROMPT;
    }
}
