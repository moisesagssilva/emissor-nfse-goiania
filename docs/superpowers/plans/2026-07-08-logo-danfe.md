# Logo da Empresa no DANFE — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the DANFE PDF (generated from an authorized NF-e) show the company's logo, controlled by an optional `LOGO_PATH` config value, with zero change to behavior when it's unset.

**Architecture:** One new optional `.env` key (`LOGO_PATH`) resolved through the existing `Config::path()` helper, consumed at the single call site that builds the DANFE (`public/pages/pedidos/ver.php`). No new classes, no new UI, no schema change.

**Tech Stack:** PHP 8.5, `nfephp-org/sped-da` (`NFePHP\DA\NFe\Danfe`), existing `EmissorGyn\Config`.

## Global Constraints

- Only PNG or JPG accepted for the logo — enforced by the library (`DaCommon::adjustImage()`), not by our code.
- `LOGO_PATH` is optional; unset or missing file must reproduce today's exact output (no logo, no error).
- No automated test suite covers DANFE PDF rendering (spec §5) — verification for this feature is manual, run against the real app (real `.env`, real `storage/nfse.sqlite`, real signed NF-e), not the git worktree.
- No new config for logo alignment/size — library default (`logoAlign = 'C'`, centered) stands.

Spec: `docs/superpowers/specs/2026-07-08-logo-danfe-design.md`

---

### Task 1: Add `LOGO_PATH` to `.env.example`

**Files:**
- Modify: `.env.example`

**Interfaces:**
- Produces: the `LOGO_PATH` key name that Task 2 reads via `Config::path('LOGO_PATH', '')`.

- [ ] **Step 1: Add the key**

In `.env.example`, in the "Dados do emitente para NF-e" section (after the `PRESTADOR_CEP=74210015` line), add:

```
# Logo da empresa no DANFE (opcional; PNG ou JPG). Deixe vazio para gerar sem logo.
LOGO_PATH=
```

- [ ] **Step 2: Commit**

```bash
git add .env.example
git commit -m "docs: document optional LOGO_PATH config for DANFE logo"
```

---

### Task 2: Pass the logo path to `Danfe::render()`

**Files:**
- Modify: `public/pages/pedidos/ver.php:14-16`

**Interfaces:**
- Consumes: `Config::path(string $key, string $default): string` (existing, `src/Config.php:65-72`); `$config` is already in scope in `ver.php` (inherited via `require` from `public/web.php:22`).
- Consumes: `NFePHP\DA\NFe\Danfe::render($logo = '')` (existing library public method, `vendor/nfephp-org/sped-da/src/Common/DaCommon.php:211-218`) — accepts a file path (or `''` for no logo), internally calls the `protected` `monta($logo)` (`vendor/nfephp-org/sped-da/src/NFe/Danfe.php:490-494`, which validates the file is PNG/JPG via `getimagesize()` and throws `\Exception` on any other format), and returns the rendered PDF bytes. `monta()` is `protected` and cannot be called directly from outside the class hierarchy — `render()` is the library's intended public entry point.

- [ ] **Step 1: Read current code**

```php
$xml   = (string) $danfePedido['nfe_xml_autorizado'];
$danfe = new NFePHP\DA\NFe\Danfe($xml);
echo $danfe->render();
```

- [ ] **Step 2: Replace with logo-aware version**

```php
$xml      = (string) $danfePedido['nfe_xml_autorizado'];
$danfe    = new NFePHP\DA\NFe\Danfe($xml);
$logoPath = $config->path('LOGO_PATH', '');
// ... headers ...
echo $danfe->render(is_file($logoPath) ? $logoPath : '');
```

Note: `$config->path('LOGO_PATH', '')` does **not** return `''` when the key is unset — it resolves the empty default against `baseDir`, yielding a directory path like `/opt/.../emissor-nfse-goiania/`. The `is_file()` guard is what makes the fallback correct (a directory is never a file), so do not remove it as a "simplification."

- [ ] **Step 3: Static verification**

There's no unit test for this page (it's a thin HTTP-only script that emits a PDF directly), so verification here is a syntax/lint check rather than a test run:

```bash
php -l public/pages/pedidos/ver.php
```
Expected: `No syntax errors detected in public/pages/pedidos/ver.php`

```bash
composer lint
```
Expected: exits 0, no PSR12 violations reported for `public/pages/pedidos/ver.php`.

- [ ] **Step 4: Commit**

```bash
git add public/pages/pedidos/ver.php
git commit -m "feat: show company logo on DANFE via optional LOGO_PATH config"
```

---

### Task 3: Manual end-to-end verification

**Files:** none (verification only)

**Interfaces:** none — this task exercises Task 1 + Task 2 through the real running app.

This must run against the actual project checkout at `/opt/Lumina/emissor-nfse-goiania` (real `.env`, real `storage/nfse.sqlite`, real cert), **not** this git worktree — the worktree has no `.env` and no populated `storage/nfse.sqlite`, since both are gitignored local state. Merge/apply Task 1 and Task 2's changes there first (or run this task after the branch is merged).

- [ ] **Step 1: Generate a throwaway test PNG logo**

```bash
php -r '
$im = imagecreatetruecolor(300, 100);
$bg = imagecolorallocate($im, 30, 90, 200);
imagefill($im, 0, 0, $bg);
$white = imagecolorallocate($im, 255, 255, 255);
imagestring($im, 5, 90, 40, "TEST LOGO", $white);
imagepng($im, "/tmp/test-logo.png");
imagedestroy($im);
echo "wrote /tmp/test-logo.png" . PHP_EOL;
'
```
Expected output: `wrote /tmp/test-logo.png`

- [ ] **Step 2: Point the real `.env` at the test logo**

In `/opt/Lumina/emissor-nfse-goiania/.env`, set:
```
LOGO_PATH=/tmp/test-logo.png
```

- [ ] **Step 3: Open a DANFE for an already-emitted pedido**

With the app's web server running (see prior session notes: `php -S 127.0.0.1:8080 public/web.php`, restarted fresh so it picks up any config/provider changes), open in a browser:
```
http://127.0.0.1:8080/?p=pedidos&id=<id de um pedido com status "emitido">&acao=danfe
```
Expected: PDF opens inline, showing "TEST LOGO" centered in the header area where the emitente's data is printed.

- [ ] **Step 4: Confirm the no-logo fallback still works**

In `.env`, clear the key:
```
LOGO_PATH=
```
Reload the same DANFE URL from Step 3.
Expected: PDF renders exactly as it did before this feature (no logo, no error, no blank space regression).

- [ ] **Step 5: Confirm invalid-format handling is a clear error, not a crash**

```bash
cp /tmp/test-logo.png /tmp/test-logo.gif
```
Set `LOGO_PATH=/tmp/test-logo.gif` in `.env`, reload the same DANFE URL.
Expected: an uncaught `\Exception` with message "O formato da imagem não é aceitável! Somente PNG ou JPG podem ser usados." surfaces (as a PHP error page/log entry) — confirm the message is the library's clear one, not a generic fatal with no context. This is expected behavior per spec §4 (not something this feature needs to catch).

- [ ] **Step 6: Clean up**

Restore `.env` to whatever `LOGO_PATH` value (or absence) the user wants permanently, and remove the throwaway files:
```bash
rm -f /tmp/test-logo.png /tmp/test-logo.gif
```
