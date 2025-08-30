<?php
namespace Generator;

final class ConstrutorPrompt {

    public function construir(array $aItem, string $sCodigoContexto): string {
        $sTipo  = $aItem['type'] ?? 'desconhecido';
        $sFqn   = $aItem['fqn']  ?? ($aItem['name'] ?? '');
        $iLinha = (int)($aItem['line'] ?? 1);

        $aLinhas = preg_split('/\R/u', $sCodigoContexto);
        $iIni    = max(0, $iLinha - 6);
        $iFim    = min(count($aLinhas), $iLinha + 6);
        $sTrecho = implode("\n", array_slice($aLinhas, $iIni, $iFim - $iIni));

        $sAss = $sTipo.' '.$sFqn;

        $sParams = '';
        if (in_array($sTipo, ['function','method'], true)) {
            $aParams = $aItem['params'] ?? [];
            $sParams = implode(', ', array_map(
                fn($p) => (($p['type'] ?? 'mixed').' '.($p['name'] ?? '')),
                $aParams
            ));
        }
        $sRet = $aItem['returnType'] ?? 'mixed';

        $sComum = "Gere apenas um DocBlock PHPDoc entre /** e */. Não use crases. Alvo: \"{$sAss}\" na linha {$iLinha}.";
        $sTipoRegra = match ($sTipo) {
            'function','method' => "- Descrição curta\n- Linha em branco\n- @param por parâmetro\n- @return {$sRet}\nSem @throws sem evidência.",
            'class','interface','trait','enum' => "- Descrição curta\n- Opcional linha de responsabilidade\nSem @param/@return.",
            'property' => "- Descrição curta\n- @var <tipo> descrição\nSem @param/@return.",
            'constant' => "- Descrição curta\nSem @param/@return.",
            default => "Descrição curta.",
        };

        $sAssDet = in_array($sTipo, ['function','method'], true) ? "{$sAss}({$sParams})" : $sAss;

        return <<<PROMPT
{$sComum}

{$sTipoRegra}

Assine para: {$sAssDet}

Trecho:
{$sTrecho}
PROMPT;
    }
}
