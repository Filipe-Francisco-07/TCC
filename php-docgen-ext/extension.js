// extension.js
const vscode = require('vscode');
const fs = require('fs');
const path = require('path');
const cp = require('child_process');

function getConfig() {
  const cfg = vscode.workspace.getConfiguration('phpDocgen');
  return {
    phpPath: cfg.get('phpPath', 'php'),
    projectRoot: cfg.get('projectRoot', ''),
    env: {
      OPENAI_API_KEY: cfg.get('env.OPENAI_API_KEY', ''),
      OPENAI_MODEL: cfg.get('env.OPENAI_MODEL', 'gpt-4o-mini'),
      OPENAI_BASE: cfg.get('env.OPENAI_BASE', '')
    }
  };
}

const oc = vscode.window.createOutputChannel('DocGen');
function pfix(p) { return p.replace(/\\/g, path.sep).replace(/\//g, path.sep); }

async function runPhp(editor, isSelection) {
  const { phpPath, projectRoot, env } = getConfig();
  const doc = editor.document;
  const ws = vscode.workspace.workspaceFolders?.[0]?.uri?.fsPath || path.dirname(doc.uri.fsPath);
  const root = projectRoot && projectRoot.trim() ? projectRoot.trim() : ws;

  const script = pfix(path.join(root, 'bin', 'run.php'));
  const inputDir = pfix(path.join(root, 'input'));
  const outputDir = pfix(path.join(root, 'output'));
  const inputFile = pfix(path.join(inputDir, 'entrada.php'));
  const base = 'entrada';

  oc.clear();
  oc.appendLine(`[DocGen] Root: ${root}`);
  oc.appendLine(`[DocGen] PHP: ${phpPath}`);
  oc.appendLine(`[DocGen] Script: ${script}${isSelection ? ' (FRAGMENTO)' : ''}`);
  oc.appendLine(`=> Input:  ${inputFile}`);
  oc.appendLine(`=> Output: ${outputDir}`);
  oc.appendLine(`=> Base:   ${base}`);
  oc.show(true);

  await fs.promises.mkdir(inputDir, { recursive: true });
  await fs.promises.mkdir(outputDir, { recursive: true });

  const sel = editor.selection;
  const isFragment = isSelection && !sel.isEmpty;
  const srcText = isFragment ? doc.getText(sel) : doc.getText();
  await fs.promises.writeFile(inputFile, srcText, 'utf8');

  const args = [script, '--base', base];
  if (isFragment) args.push('--fragment');

  const childEnv = { ...process.env };
  if (env.OPENAI_API_KEY) childEnv.OPENAI_API_KEY = env.OPENAI_API_KEY;
  if (env.OPENAI_MODEL) childEnv.OPENAI_MODEL = env.OPENAI_MODEL;
  if (env.OPENAI_BASE) childEnv.OPENAI_BASE = env.OPENAI_BASE;

  let res;
  try {
    res = cp.spawnSync(phpPath, args, { cwd: root, env: childEnv, encoding: 'utf8' });
  } catch (e) {
    oc.appendLine(String(e));
    vscode.window.showErrorMessage('DocGen: falha ao iniciar o PHP.');
    return;
  }

  if (res.stdout) oc.appendLine(res.stdout.trim());
  if (res.stderr) oc.appendLine(res.stderr.trim());
  if ((res.status ?? 0) !== 0) {
    vscode.window.showErrorMessage('DocGen: erro ao executar run.php. Veja o painel DocGen.');
    return;
  }

  const docFile = pfix(path.join(outputDir, `documentado_${base}.php`));
  const preview = pfix(path.join(outputDir, `preview_patch_${base}.txt`));
  const errors = pfix(path.join(outputDir, 'errors.json'));

  if (fs.existsSync(errors)) {
    try {
      const errs = JSON.parse(await fs.promises.readFile(errors, 'utf8'));
      if (Array.isArray(errs) && errs.length) {
        vscode.window.showWarningMessage('DocGen: parser reportou erros (output/errors.json).');
      }
    } catch {}
  }

  if (isFragment) {
    if (!fs.existsSync(preview)) {
      vscode.window.showWarningMessage('DocGen: nenhum preview gerado para a seleção.');
      return;
    }
    let patch = await fs.promises.readFile(preview, 'utf8');
    patch = patch.replace(/\r?\n/g, '\n');
    const lineStart = sel.start.line;
    const indentCount = doc.lineAt(lineStart).firstNonWhitespaceCharacterIndex;
    const indentStr = doc.getText(new vscode.Range(
      new vscode.Position(lineStart, 0),
      new vscode.Position(lineStart, indentCount)
    ));
    patch = patch.split('\n').map((ln, i) => (i === 0 ? ln : indentStr + ln)).join('\n');
    if (!patch.endsWith('\n')) patch += '\n';

    await editor.edit(ed => ed.insert(new vscode.Position(lineStart, 0), patch));
    vscode.window.setStatusBarMessage('DocGen: documentação inserida para a seleção.', 3000);
    return;
  }

  if (!fs.existsSync(docFile)) {
    vscode.window.showWarningMessage('DocGen: saída documentada não encontrada.');
    return;
  }
  const newContent = await fs.promises.readFile(docFile, 'utf8');
  const fullRange = new vscode.Range(new vscode.Position(0, 0), new vscode.Position(doc.lineCount + 1, 0));
  await editor.edit(ed => ed.replace(fullRange, newContent));
  vscode.window.setStatusBarMessage('DocGen: arquivo documentado aplicado.', 3000);
}

// --- Nova Ação: Push para Git e disparar Workflow ---
async function pushToGit() {
  const ws = vscode.workspace.workspaceFolders?.[0]?.uri?.fsPath;
  if (!ws) {
    vscode.window.showErrorMessage('Nenhum workspace aberto.');
    return;
  }

  const commitMsg = await vscode.window.showInputBox({
    prompt: 'Mensagem do commit',
    value: 'ci: auto commit',
    placeHolder: 'Digite a mensagem do commit...'
  });

  if (commitMsg === undefined) return; // Cancelado

  oc.clear();
  oc.appendLine('[PushToGit] Iniciando commit e push...');
  oc.appendLine(`[PushToGit] Workspace: ${ws}`);
  oc.show(true);

  try {
    // Obtém branch atual
    const branch = cp.execSync('git rev-parse --abbrev-ref HEAD', { cwd: ws, encoding: 'utf8' }).trim();
    oc.appendLine(`[PushToGit] Branch atual: ${branch}`);

    // Adiciona todas as alterações
    cp.execSync('git add -A', { cwd: ws, encoding: 'utf8' });
    oc.appendLine('[PushToGit] Alterações adicionadas.');

    // Faz o commit
    try {
      cp.execSync(`git commit -m "${commitMsg}"`, { cwd: ws, encoding: 'utf8' });
      oc.appendLine(`[PushToGit] Commit realizado: "${commitMsg}"`);
    } catch {
      oc.appendLine('[PushToGit] Nenhuma alteração para commitar.');
    }

    // Faz o push e define upstream
    oc.appendLine('[PushToGit] Enviando alterações para o repositório remoto...');
    const pushOut = cp.execSync(`git push -u origin ${branch}`, { cwd: ws, encoding: 'utf8' });
    oc.appendLine(pushOut);

    vscode.window.showInformationMessage('Código enviado. Workflow de CI/CD iniciado no GitHub.');
    oc.appendLine('[PushToGit] Workflow disparado com sucesso.');
  } catch (err) {
    oc.appendLine(`[PushToGit] Erro: ${err.message}`);
    vscode.window.showErrorMessage('Erro ao enviar código. Veja o painel DocGen.');
  }
}

function activate(context) {
  context.subscriptions.push(
    vscode.commands.registerCommand('docgen.documentFile', async () => {
      const editor = vscode.window.activeTextEditor;
      if (!editor) return;
      await runPhp(editor, false);
    }),

    vscode.commands.registerCommand('docgen.documentSelection', async () => {
      const editor = vscode.window.activeTextEditor;
      if (!editor) return;
      if (editor.selection.isEmpty) {
        vscode.window.showInformationMessage('Selecione um trecho para documentar.');
        return;
      }
      await runPhp(editor, true);
    }),

    vscode.commands.registerCommand('docgen.pushToGit', async () => {
      await pushToGit();
    })
  );
}

function deactivate() {}
module.exports = { activate, deactivate };
