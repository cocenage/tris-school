
### `.ai/WORKFLOW.md`

Это самая важная часть всей системы.

```md
# Agent Workflow

This file defines how coding tasks must be executed in TRIS Academy.

## Core loop

For every bug, regression or UX task:

### 1. Understand

Read:

- the user's exact report;
- the directly relevant component/service;
- relevant project memory.

Do not begin with a repository-wide audit.

### 2. Reproduce first

Before editing, reproduce the exact reported scenario whenever technically possible.

Examples:

- if a specific mobility alert incorrectly appears on a specific date, reproduce that same row/date;
- if a Telegram callback fails, reproduce that callback path;
- if an anchor scrolls incorrectly, click that exact anchor in the real interface;
- if Blade fails at runtime, reproduce Blade compilation.

Record what actually fails.

### 3. Find the root cause

Trace only the failing dependency chain.

Determine why the current code produces the wrong result.

Do not patch symptoms before understanding the cause.

### 4. Make the smallest coherent fix

Change only the necessary code.

Do not broaden scope.

Preserve unrelated business behavior.

### 5. Re-run the exact scenario

After the edit, run the SAME scenario that originally failed.

If it still fails:

continue debugging.

Do not stop because another test passes.

### 6. Regression protection

Once the real scenario passes, add or update a test when practical.

The test should represent the actual root cause, not merely a convenient synthetic case.

### 7. Targeted checks

Run the smallest relevant validation first.

Examples:

- targeted Pest test;
- Blade compilation;
- frontend build;
- PHP syntax/lint;
- specific Artisan preview command.

### 8. Broader checks

Only after the reported scenario passes, run appropriate broader quality gates.

Typical:

```bash
php artisan test
vendor/bin/pint --test
npm run build
git diff --check