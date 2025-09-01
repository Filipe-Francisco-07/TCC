<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Parser\Normalizador;
use App\Parser\ServicoAst;
use PhpParser\NodeTraverser;
use Analyser\MarcadorDocumentacao;
use Util\InjetorPlaceholder;
use Util\RelatorErros;
use Generator\ConstrutorPrompt;
use Generator\ClienteGPT;
use Generator\AplicadorDocumentacao;


$sIn = __DIR__ . '/../input/entrada.php';
$sIn  = realpath($sIn) ?: $sIn;
$base = pathinfo($sIn, PATHINFO_FILENAME);

/* ===== Saída ===== */
$sOut = __DIR__ . '/../output';
@mkdir($sOut, 0777, true);

/* Limpa arquivos antigos (opcional: só os com prefixo do base) */
foreach (glob($sOut . '/*') as $f) { @unlink($f); }

echo "=> Input:  {$sIn}\n";
echo "=> Output: {$sOut}\n";
echo "=> Base:   {$base}\n";

/* ===== Leitura ===== */
$sRaw = @file_get_contents($sIn);
if ($sRaw === false) {
    (new RelatorErros())->escrever($sOut, [['mensagem' => "Arquivo não encontrado: {$sIn}"]]);
    fwrite(STDERR, "Erro: arquivo de entrada ausente: {$sIn}\n");
    exit(1);
}

/* ===== Normalização ===== */
[$sNorm, $bParcial, $iLinhasAdd] = (new Normalizador())->normalizar($sRaw);

/* ===== AST ===== */
[$aAst, $aErros] = (new ServicoAst())->analisarCodigo($sNorm);
if (!empty($aErros)) {
    (new RelatorErros())->escrever($sOut, $aErros);
    // não prossegue: evita placeholders/LLM/documentado
    file_put_contents("{$sOut}/doc_map_{$base}.json", json_encode([], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo "Erros → {$sOut}/errors.json\n";
    exit(1);
}

/* ===== Coleta itens ===== */
$oMarc = new MarcadorDocumentacao();
$oTrav = new NodeTraverser();
$oTrav->addVisitor($oMarc);
$oTrav->traverse($aAst);

/* Ajuste de linhas (inclui endLine) */
$aItens = array_map(function (array $aIt) use ($iLinhasAdd): array {
    $f = fn($v) => is_int($v) ? max(1, $v - $iLinhasAdd) : $v;
    $aIt['line']      = $f($aIt['line'] ?? 1);
    $aIt['endLine']   = $f($aIt['endLine'] ?? null);
    $aIt['doc_start'] = $f($aIt['doc_start'] ?? null);
    $aIt['doc_end']   = $f($aIt['doc_end'] ?? null);
    return $aIt;
}, $oMarc->aItens);

/* ===== Seleção (fragmento) =====
 * Em fragmento, NÃO documentar ClassLike (class/interface/trait/enum).
 * Mantém apenas function/method/property/constant dentro do trecho.
 */
if ($bParcial) {
    $aItens = array_values(array_filter($aItens, function($it) {
        return in_array($it['type'], ['function','method','property','constant'], true);
    }));
}

/* ===== Persistências ===== */
file_put_contents("{$sOut}/doc_map_{$base}.json", json_encode($aItens, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Mapping → {$sOut}/doc_map_{$base}.json\n";
if ($bParcial) echo "Seleção/fragmento detectado.\n";

/* Placeholders (não insere antes da 1ª linha) */
if (!$bParcial) {
    $aMapa = array_map(fn($it) => [
        'id'        => $it['id'],
        'line'      => max(2, (int)($it['line'] ?? 1)), // evita posição 1
        'doc_start' => $it['doc_start'] ?? null,
        'doc_end'   => $it['doc_end'] ?? null,
    ], $aItens);

    $sComPH = (new InjetorPlaceholder())->injetar($sIn, $aMapa);
    file_put_contents("{$sOut}/placeholder_{$base}.php", $sComPH);
    echo "Placeholders → {$sOut}/placeholder_{$base}.php\n";
}

/* ===== LLM ===== */
$sApiKey = getenv('OPENAI_API_KEY') ?: '';
$sModel  = getenv('OPENAI_MODEL')   ?: 'gpt-4o-mini';
$sBase   = rtrim(getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1', '/');

$oConstr = new ConstrutorPrompt();
$aPrompts = [];
foreach ($aItens as $aIt) {
    $aPrompts[$aIt['id']] = $oConstr->construir($aIt, $sRaw);
}
file_put_contents("{$sOut}/prompts_{$base}.jsonl", json_encode($aPrompts, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

$aDocs = [];
if ($sApiKey !== '') {
    $oLLM = new ClienteGPT();
    foreach ($aItens as $aIt) {
        $sDoc = $oLLM->gerar($sBase, $sApiKey, $sModel, $aPrompts[$aIt['id']]);
        if ($sDoc) $aDocs[$aIt['id']] = $sDoc;
    }
    $aIdsMapa   = array_column($aItens, 'id');
    $aIdsSemDoc = array_values(array_diff($aIdsMapa, array_keys($aDocs)));
    if ($aIdsSemDoc) file_put_contents("{$sOut}/missing_docs_{$base}.log", implode("\n", $aIdsSemDoc));
    file_put_contents("{$sOut}/generated_docs_{$base}.json", json_encode($aDocs, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo "Gerados via API → {$sOut}/generated_docs_{$base}.json\n";
} else {
    echo "Sem OPENAI_API_KEY. Pulei geração.\n";
}

/* ===== Aplicação ===== */
if (!$bParcial && file_exists("{$sOut}/placeholder_{$base}.php")) {
    $sFonte = file_get_contents("{$sOut}/placeholder_{$base}.php");

    // preserva 1ª linha
    $aLin = explode("\n", str_replace("\r\n","\n",$sFonte));
    $sHead = $aLin[0] ?? '';

    $sFinal = (new AplicadorDocumentacao())->aplicar($sFonte, $aDocs);

    if ($sHead !== '' && strpos($sHead, '<?php') === 0) {
        $aFinal = explode("\n", str_replace("\r\n","\n",$sFinal));
        $aFinal[0] = $sHead;
        $sFinal = implode("\n", $aFinal);
    }

    file_put_contents("{$sOut}/documentado_{$base}.php", $sFinal);
    echo "Documentado → {$sOut}/documentado_{$base}.php\n";
} else {
    if (!empty($aDocs)) {
        $sPreview = implode("\n\n", array_values($aDocs));
        file_put_contents("{$sOut}/preview_patch_{$base}.txt", $sPreview);
        echo "Preview → {$sOut}/preview_patch_{$base}.txt\n";
    }
}
