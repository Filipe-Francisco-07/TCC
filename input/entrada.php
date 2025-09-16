<?php

declare(strict_types=1);

namespace Util;

final class RelatorErros
{
    /**
     * Cria um diretÃ³rio se ele nÃ£o existir e grava um arquivo JSON com os erros fornecidos.
     *
     * @param string $sDir O caminho do diretÃ³rio onde o arquivo serÃ¡ salvo.
     * @param array $aErros A lista de erros a serem gravados no arquivo JSON.
     * @return void
     */
    public function escrever(string $sDir, array $aErros): void
    {
        if (!is_dir($sDir)) {
            mkdir($sDir, 0777, true);
        }
        file_put_contents(
            rtrim($sDir, '/') . '/errors.json',
            json_encode($aErros, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
