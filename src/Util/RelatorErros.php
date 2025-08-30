<?php
namespace Util;

final class RelatorErros {

    public function escrever(string $sDir, array $aErros): void {
        if (!is_dir($sDir)) mkdir($sDir, 0777, true);
        file_put_contents(
            rtrim($sDir, '/').'/errors.json',
            json_encode($aErros, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
