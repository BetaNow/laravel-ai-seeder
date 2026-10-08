# Laravel AI Seeder — Design

Date: 2026-10-07
Status: Draft for review

## 1. Goal

A Laravel package where each Eloquent model is described once in a *definition class*. The package asks an LLM (OpenAI or an OpenAI-compatible local server) for realistic values for the fields that need "intelligence", fills the rest with Faker, and inserts the rows through an Artisan command or a `Seeder`-friendly API.

"Finished" means:

1. Installs on the developer's machine (PHP 8.5.10) and on a CI matrix.
2. Covered by automated tests that run without network access or an API key.
3. Proven end to end over real HTTP against a local fake server, and (run by the maintainer, with their own key) against OpenAI.
4. Documented well enough to use from the README alone.

## 2. Current state (what exists)

| File | State |
|---|---|
| `src/AiSeederServiceProvider.php` | Registers `AiFactoryManager`, `AiManager`, alias `ai-seeder`, publishes config. Done. |
| `src/Facades/AiSeeder.php` | Facade → `ai-seeder`. Done. |
| `src/AiFactoryManager.php` | Config reader only (`getDefaultDriver`, `getDriverConfig`, `getDrivers`). Cannot build a driver. |
| `src/AiManager.php` | Config reader (`driver()` returns a config *array*, `definitions()`, `definitionFor()`). Cannot generate or seed. |
| `src/AiModelDefinition.php` | Abstract: `fields()`, `context()`, `faker()`. Field value semantics undefined. |
| `config/ai-seeder.php` | `default_driver`, `openai` and `local` drivers. No `models` key (read by `AiManager` but absent). |
| `tests/ExampleTest.php` | `assertTrue(true)`. |
| Tooling | Pint config, PHPUnit config, Pint and Tests GitHub workflows. |
| Missing | HTTP driver, prompt/parse logic, runner, command, exceptions, real tests, README, `testbench`, `vendor/`. |

Constraint found: `composer.json` pins `illuminate/*: ^10.0`; the local PHP is 8.5.10. Laravel 10 is unlikely to install there.

## 3. Decisions (from brainstorming)

- **Field design: hybrid.** Each field is a literal, a Faker closure, or an `Ai` descriptor. Only `Ai` fields go to the LLM.
- **Laravel support: current + previous.** The newest two (at most three) Laravel majors that actually resolve on PHP 8.2–8.5, verified with `composer` during implementation. `^10` is dropped. `orchestra/testbench` is added.
- **Architecture: pipeline with a driver contract**, not one monolithic class and not queue-based.
- **Local driver** speaks the OpenAI chat-completions wire format (Ollama, LM Studio, llama.cpp all expose it). The default local endpoint changes from `http://localhost:8000/api/ai` to `http://localhost:11434/v1/chat/completions`.
- **Namespace** stays `Vendor\AiSeeder` and the Composer name stays `vendor/ai-seeder`. Renaming for publication is a mechanical change outside this work.

## 4. Architecture

```
Artisan command / AiSeeder facade / Seeder
            │
         AiManager ──────── seed() / generate()
            │
        SeedRunner  ── batches, concurrency, transaction, forceCreate
            │
       RowGenerator ── prompt build, JSON parse, validate, hybrid merge
            │
        AiDriver (contract)
            │
   ChatCompletionsDriver ── Laravel Http client (openai + local)
```

### 4.1 Units

| Unit | Responsibility | Depends on |
|---|---|---|
| `Contracts\AiDriver` | `complete(string $system, string $user): string` and `completeMany(array $requests): array` (results keyed like the input). | — |
| `Drivers\ChatCompletionsDriver` | POST to the configured endpoint in OpenAI chat-completions format; auth header only when `api_key` is set; `response_format: json_object` only when `json_mode` is true; `timeout`; retry with backoff on 429/5xx/connection errors; `completeMany` uses `Http::pool`, and items that fail in the pool are retried one by one. | `Http` facade, driver config |
| `AiFactoryManager` (extended) | Adds `make(?string $driver = null): AiDriver`. Existing config methods unchanged. | config |
| `Ai` (field descriptor) | `Ai::text($hint)`, `Ai::integer($hint, $min, $max)`, `Ai::float($hint, $min, $max)`, `Ai::boolean($hint)`, `Ai::date($hint)`, `Ai::enum(array $options, $hint)`. Immutable value object; exposes type, hint and constraints. | — |
| `AiModelDefinition` (extended) | Keeps `fields()`, `context()`, `faker()`. Adds `aiFields(): array` and `localFields(): array` splitting `fields()` by value kind. | `Ai` |
| `RowGenerator` | `generate(AiModelDefinition $def, int $count, AiDriver $driver): array` — builds the prompt, calls the driver, parses and validates, merges Faker fields. | `AiDriver`, definition |
| `SeedRunner` | `generate(...)` returns rows without writing; `seed(...)` generates all rows first, then inserts them in one transaction. Splits `count` by `batch_size`, runs batches in groups of `max_concurrency`. | `RowGenerator`, `AiFactoryManager` |
| `AiManager` (extended) | Adds `generate(string $model, ?int $count, ?string $driver)` and `seed(...)`. Constructor gains a `SeedRunner` argument (third position); the provider is updated. | `SeedRunner` |
| `Console\SeedCommand` | `ai-seeder:seed {model?} {--count=} {--driver=} {--dry-run}`. | `AiManager` |
| `Exceptions\*` | `AiSeederException` (base), `MissingApiKey`, `GenerationFailed`, `InvalidDefinition`. | — |

### 4.2 Field semantics (hybrid)

`fields()` returns `array<string, mixed>` keyed by column name. Each value is exactly one of:

- **`Ai` instance** — generated by the LLM.
- **`Closure`** — called as `fn (Generator $faker, array $row): mixed` after the AI values are known. `$row` holds everything resolved so far (AI values, then closures in declaration order). So `'slug' => fn ($f, $row) => Str::slug($row['title'])` works.
- **Anything else** — used as a literal.

`context()` is a free-text theme sent with the prompt (for example "an online shop selling outdoor gear"). If a definition has no `Ai` fields, no LLM call is made.

### 4.3 Prompt and response contract

- **System message:** instructs the model to return only a JSON object `{"rows": [...]}`, with exactly the requested keys, no prose and no markdown.
- **User message:** a JSON document with `count`, `context`, and `fields` (name → type, hint, options, min, max).
- **Parser** accepts: raw JSON; JSON wrapped in a markdown code fence; a bare array (treated as `rows`). Anything else is a parse failure.
- **Validation per row, per `Ai` field:** key present; `text` is a non-empty string; `integer` is an int (or integer-like string) within min/max; `float` numeric within min/max; `boolean` is a bool; `date` parses with Carbon and is normalised to `Y-m-d`; `enum` is one of the options. Invalid rows are dropped; extra keys are dropped; surplus rows beyond the requested count are truncated.
- **Shortfall:** if a batch yields fewer valid rows than requested, one corrective request asks for exactly the missing number, with a stricter instruction. If it is still short, `GenerationFailed` is thrown.

### 4.4 Persistence

Rows are written with `Model::query()->forceCreate($attributes)` inside one transaction on the model's own connection. This honours casts, mutators, events and timestamps (seeders are small-scale, so row-by-row is acceptable) and ignores `$fillable`. Generation completes for *all* batches before the transaction opens, so a failed run leaves no partial data.

### 4.5 Configuration changes

`config/ai-seeder.php` gains:

- `default_count` (default `10`)
- `models` (default `[]`) — ordered map `Model::class => Definition::class`. Command order = config order, so users control foreign-key dependencies.
- Per driver: `json_mode` (`true` for openai, `false` for local), `timeout` (`60`), `retries` (`3`), `requires_api_key` (`true` for openai, `false` for local).
- Local default endpoint → `http://localhost:11434/v1/chat/completions`.

### 4.6 Command behaviour

- `ai-seeder:seed Product --count=25` seeds one model; the argument may be the model class or its short name matching a key in `models`.
- Without `{model}`, seeds every configured model in config order, applying `--count` (or `default_count`) to each.
- `--driver=local` overrides the default driver for the run.
- `--dry-run` calls `generate()` and prints a table, with no database writes.
- Exit code is non-zero on any `AiSeederException`, and the message is printed without a stack trace.

### 4.7 Error handling summary

| Situation | Behaviour |
|---|---|
| `requires_api_key` is true and key is empty | `MissingApiKey` before any HTTP call |
| 429 / 5xx / connection error | Retry with backoff up to `retries`, then `GenerationFailed` |
| Unparseable response | One corrective re-request, then `GenerationFailed` |
| Fewer valid rows than requested | One top-up request, then `GenerationFailed` |
| Unknown model, definition not an `AiModelDefinition`, unknown driver | `InvalidDefinition` |
| Any failure during generation | No rows written |

### 4.8 Security and privacy

- The API key is read from config/env only; it is never logged, printed or included in exception messages.
- Only the definition's `context()` and `Ai` field descriptors are sent to the provider — no database content.
- Model output is treated as data: it is validated and written through Eloquent, never evaluated.

## 5. Testing strategy

Quality gates, all of which must pass: `vendor/bin/phpunit`, `vendor/bin/pint --test`.

| Layer | What it proves | Tools |
|---|---|---|
| Unit | Prompt content, parser (raw / fenced / bare-array / garbage), per-type validation, batch splitting, hybrid field resolution order, `Ai` descriptors, config splitting in `AiModelDefinition` | PHPUnit |
| Feature | Command → rows in DB; `--dry-run` writes nothing; `--driver` override; seeding all configured models in order; retry on 5xx; malformed-JSON recovery; shortfall top-up; failure leaves zero rows; missing key fails fast; no-AI-fields definition makes zero HTTP calls | Testbench, in-memory SQLite, `Http::fake()` |
| Real-HTTP smoke | The actual HTTP + `Http::pool` + concurrency path against a real socket, without an API key | Tiny PHP fake server (`php -S`) mimicking `/v1/chat/completions`, started from the test |
| Live (opt-in) | Real OpenAI output is accepted by the parser/validator | One test in group `live`, excluded from the default suite, skipped unless `OPENAI_API_KEY` is set; run by the maintainer |
| Demo | A human-readable end-to-end run | `workbench/` (Testbench): sample `Product` model, definition, migration; `vendor/bin/testbench ai-seeder:seed Product --count=25` |

Prerequisites verified during implementation: `pdo_sqlite` is available, and `Http::pool` behaves as specified on every supported Laravel version.

CI: `tests.yml` becomes a matrix over supported PHP × Laravel combinations; the Pint workflow is kept.

## 6. Deliverables

1. Source units in section 4.1 and the config changes in 4.5.
2. `composer.json`: widened Laravel constraints, `orchestra/testbench` in `require-dev`, command registered through the provider.
3. Tests as in section 5, replacing `ExampleTest`.
4. `workbench/` demo and `testbench.yaml`, marked dev-only.
5. `README.md`: install, publish config, write a definition (with `Ai` and Faker examples), register in `models`, run the command, configure OpenAI and local drivers, testing.
6. Updated CI workflow.
7. A final verification report stating what ran and what the results were, including anything not run (notably the live OpenAI test).

## 7. Out of scope

Cross-model relationship resolution (a closure may look up existing IDs itself), queue/job batching, streaming, response caching, providers with a non-OpenAI wire format, and renaming the package namespace.

## 8. Success criteria

1. `composer install` succeeds locally on PHP 8.5.10.
2. `vendor/bin/phpunit` is green, including the smoke test, and `vendor/bin/pint --test` is clean.
3. The workbench command against the fake server inserts exactly the requested number of valid rows, and a forced failure inserts none.
4. README instructions work as written.
5. The live test passes when the maintainer runs it with their own key; if it has not been run, the report says so.
