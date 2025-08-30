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

/* Caminhos */
$sIn  = __DIR__ . '/../input/entrada.php';
$sOut = __DIR__ . '/../output';
@mkdir($sOut, 0777, true);

/* Lê entrada */
$sRaw = @file_get_contents($sIn);
if ($sRaw === false) {
    (new RelatorErros())->escrever($sOut, [['mensagem' => "Arquivo não encontrado: {$sIn}"]]);
    fwrite(STDERR, "Erro: arquivo de entrada ausente.\n");
    exit(1);
}

/* Normaliza fragmento */
[$sNorm, $bParcial, $iLinhasAdd] = (new Normalizador())->normalizar($sRaw);

/* AST + visitantes */
$oServico = new ServicoAst();
[$aAst, $aErros] = $oServico->analisarCodigo($sNorm);

if (!empty($aErros)) {
    (new RelatorErros())->escrever($sOut, $aErros);
}

$oMarcador = new MarcadorDocumentacao();
$oTrav = new NodeTraverser();
$oTrav->addVisitor($oMarcador);
$oTrav->traverse($aAst);

/* Itens ajustados para linhas originais */
$aItensBrutos = $oMarcador->aItens;
$aItens = array_map(function (array $aIt) use ($iLinhasAdd): array {
    $f = fn($v) => is_int($v) ? max(1, $v - $iLinhasAdd) : $v;
    $aIt['line']      = $f($aIt['line'] ?? 1);
    $aIt['doc_start'] = $f($aIt['doc_start'] ?? null);
    $aIt['doc_end']   = $f($aIt['doc_end'] ?? null);
    return $aIt;
}, $aItensBrutos);

/* Salva mapa */
file_put_contents($sOut.'/doc_map.json', json_encode($aItens, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
if (!empty($aErros)) {
    echo "Mapping com avisos → output/doc_map.json (erros em output/errors.json)\n";
} else {
    echo "Mapping → output/doc_map.json\n";
}
if ($bParcial) echo "Seleção/fragmento detectado.\n";

/* Placeholders no arquivo original */
if (!$bParcial) {
    $aMapa = array_map(fn($it) => [
        'id'        => $it['id'],
        'line'      => $it['line'] ?? 1,
        'doc_start' => $it['doc_start'] ?? null,
        'doc_end'   => $it['doc_end'] ?? null,
    ], $aItens);

    $sComPH = (new InjetorPlaceholder())->injetar($sIn, $aMapa);
    file_put_contents($sOut.'/placeholder.php', $sComPH);
    echo "Placeholders → output/placeholder.php\n";
}

/* LLM */
$sApiKey = getenv('OPENAI_API_KEY') ?: '';
$sModel  = getenv('OPENAI_MODEL')   ?: 'gpt-4o-mini';
$sBase   = rtrim(getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1', '/');

if (!$sApiKey) {
    echo "Sem OPENAI_API_KEY. Pulei geração.\n";
    exit(0);
}

/* Prompts por item com base no código ORIGINAL */
$oConstrutor = new ConstrutorPrompt();
$aPrompts = [];
foreach ($aItens as $aIt) {
    $aPrompts[$aIt['id']] = $oConstrutor->construir($aIt, $sRaw);
}
file_put_contents($sOut.'/prompts.jsonl', json_encode($aPrompts, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

/* Geração de DocBlocks */
$oLLM = new ClienteGPT();
$aDocs = [];
foreach ($aItens as $aIt) {
    $sDoc = $oLLM->gerar($sBase, $sApiKey, $sModel, $aPrompts[$aIt['id']]);
    if ($sDoc) $aDocs[$aIt['id']] = $sDoc;
}
file_put_contents($sOut.'/generated_docs.json', json_encode($aDocs, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Gerados → output/generated_docs.json\n";

/* Aplicação ou preview */
if (!$bParcial && file_exists($sOut.'/placeholder.php')) {
    $sFinal = (new AplicadorDocumentacao())->aplicar(file_get_contents($sOut.'/placeholder.php'), $aDocs);
    file_put_contents($sOut.'/documentado.php', $sFinal);
    echo "Documentado → output/documentado.php\n";
} else {
    $sPreview = implode("\n\n", array_values($aDocs));
    file_put_contents($sOut.'/preview_patch.txt', $sPreview);
    echo "Preview → output/preview_patch.txt\n";
}
