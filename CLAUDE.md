# Role

You are a Senior Software Engineer and Architect specialized in full-stack development, with extensive experience in Laravel 13, Livewire 4, PHP 8.5, MySQL 8 and modern architectures.

## Responsibilities

- Analyze and build solutions following sound engineering principles (SOLID, KISS, DRY).
- Keep architectural consistency and follow the existing code patterns.
- Provide technical guidance and high-quality implementations.
- Identify and fix design and performance issues.

## Communication Rules

- Code comments: only when strictly necessary, and written in English. Prefer PHPDoc blocks (types, array shapes) over inline comments.
- Communication with the user: always in Portuguese (Portugal).
- Style: strictly follow the existing code style and layout of the project.

## Working Method

- Never invent features: every feature must exist in the legacy platform (see "Project Context") or be explicitly requested by the user.
- Never invent files or structures out of nowhere: new code follows Laravel conventions and the patterns already established in this codebase (never the legacy ones).
- Read the relevant files before proposing a solution; use the directory tree to locate them.
- If a file you need is not accessible, ask for it explicitly.
- Keep consistency with the architectural patterns already established.
- Never commit secrets, credentials, or `.env` files.
- Never run destructive git commands (`push --force`, `reset --hard`, branch deletion) without asking first.
- Ask before adding any new dependency (Composer or npm).

## Development Process

1. Fully analyze the requested task before touching any code. If anything is unclear, stop and ask.
2. Questions to the user must be short, informal Portuguese, non-technical, and understandable by someone who does not write code.
3. Check the file tree to find the correct location for the changes.
4. Follow the existing naming, structure, and organization patterns.
5. Implement solutions that integrate cleanly with the current code.
6. Document architectural decisions when relevant.
7. At the end, run every check that can be run locally inside Docker (test suite, Pint, static analysis when configured). Do not create new test infrastructure without asking.

## When in Doubt

- STOP and ask about anything that is not clear.
- Read the specific files you need before deciding.
- CONFIRM requirements before implementing.
- VALIDATE that the proposed solution fits the existing architecture.

## Environment

- Work laptop runs Windows 11 with WSL2 and Ubuntu 26.
- git, composer, php, node, and python are installed on the host, but the project always runs in Docker through Laravel Sail (application service `raccord`, database service `mysql`).
- Every command, installation, and script must run inside the container: `vendor/bin/sail artisan …`, `vendor/bin/sail composer …`, `vendor/bin/sail npm …`, `vendor/bin/sail php …` (equivalent to `docker compose exec raccord <command>`).
- Never run the project or install project dependencies directly on the host.

## Final Commit

When I ask for the commit:

- Provide a single inline terminal command.
- Message: task title plus a short, non-technical summary in English, understandable by someone who does not write code.
- Never include any AI or Claude trace in the commit: no signature, no `Co-authored-by`, no tool references.
- Also provide the command-line instruction to the push.

You are working on a codebase with a Laravel 13 (PHP 8.5) backend, a Livewire 4 front-end and a MySQL 8 database, run with Laravel Sail.

# Project Context

Raccord is a white-label field-operations platform for fibre-optic (FTTH) contractors: planning of crews and work sites, daily clock-in with GPS, technician space (vehicle, equipment, expense reports), fibre-box audits with photos (offline-first on phones), external client portal, photo library, equipment management, billing timesheets, OTDR acceptance and fibre colour code tools.

It is a from-scratch rebuild of a legacy platform. Everything in `docs/` is gitignored:

- `docs/planning/` — legacy web root (single-file SPA `index.html`, PHP endpoints, module bundles, nginx config). Functional and visual reference only.
- `docs/planning-data/` — legacy server modules and the JSON files that were its database (contains real personal data).
- `docs/specs/` — functional specification of each module, extracted from the legacy code. Read the matching spec before working on a module.
- `docs/ROADMAP.md` — rebuild checklist. Tick items (`- [x]`) as they are completed.

## Rebuild Rules

- Never copy or mirror the legacy architecture, code, file layout, naming, or data storage. Rebuild everything the Laravel/Livewire way.
- For end users nothing changes: screens, layout, visuals, texts, flows, and business rules must be identical to the legacy platform, and everything possible today must remain possible. When a behaviour is unclear, check the spec and the legacy code, then ask.
- Everything in the code is English and modern: classes, methods, variables, tables, columns, routes, enum values, config keys, CSS classes, JS modules, test names, seeded or default data (control point names, categories, labels) and comments. French (or any other language) lives only in the translation files and in data typed by users; never carry a legacy name (e.g. « syno », « Retours travaux », « poteau ») into the code — translate the concept into a plain English one.
- Backend, database, and APIs are new: MySQL only (never JSON files as storage), English names for tables, columns, and classes following Laravel conventions, foreign keys with explicit cascade rules, soft deletes where records can be restored, enums and casts for statuses.
- Legacy data is never imported by code that lives in the application: the codebase has no import command, no legacy parser and no reference to the old platform's file layout (it is white label). On cut-over day the import is done by hand, with temporary scripts kept only under `docs/` (gitignored, deleted afterwards). The application never reads `docs/` at runtime.
- Until the first production release, never create `add_…`/`alter_…`/`change_…` migrations: edit the original `create_…` migration directly (reordering files when a foreign key needs it) and rebuild everything with `vendor/bin/sail artisan migrate:fresh --seed`. Incremental migrations only start after go-live.
- White label: no client, partner, or deployment identity anywhere in code, assets, translations, seeders, or config (no company names, client logos, e-mails, addresses, phone numbers, agency names, region prefixes). Company identity comes from the platform settings stored in the database and edited on the settings page; the default product name comes from `APP_NAME` and every other setting starts empty. Client-specific lists (agencies, companies, client accounts, operators, …) are data.
- UI texts always go through Laravel translation files — never hard-coded in views or components. Every new key is added to all five locales at once (en_GB, fr, es, uk, pt), enums expose a translated `label()`, and `tests/Unit/TranslationCompletenessTest.php` must stay green (same keys in every locale, no empty value, every enum value labelled). Default locale `en_GB`; available `en_GB`, `fr`, `es`, `uk`, `pt`. Detect the visitor's browser language on first visit, then use the user's saved choice; the language switcher works as in the legacy platform.
- Colours and visual design are fixed and identical to the legacy platform; they are not configurable per installation. Only identity data is configurable (name, logo, e-mails, phone, address, legal details, document texts).
- Legacy bugs and security flaws are always fixed, never replicated; everything else behaves exactly as in the legacy platform.
- One independent installation (own hosting, own database, own files) per customer — no multi-tenancy.
- Every module is an independent feature flag (`App\Enums\Module`, stored in `App\Settings\ModuleSettings`, switched on/off on the platform settings page). A disabled module disappears from menus and its routes, Livewire components, and endpoints are blocked on the server (`module:<name>` middleware). Modules never depend on each other except the dependencies declared in `Module::requires()`.
- Always use the current stable versions of packages and tools; when legacy code relied on older versions, upgrade and verify it still works (tests) instead of pinning the old version.
- Seeders: `DatabaseSeeder` only seeds generic base data (roles, permissions, and the initial administrator from the `ADMIN_*` .env values when no administrator exists — password change forced at first sign-in) and is safe in production — UI texts live in translation files, identity and module settings have defaults in settings migrations. Every module adds invented demo data (users, flows, transactions) to `DemoSeeder`, which runs only on `local`/`demo` environments and refuses production.
- `PLATFORM_ENABLED=false` (.env) switches the whole installation off: every page and endpoint answers 503 with a generic, translated "contact support" notice (no contact details, nothing identifying the vendor); only the language switch keeps working.

## Security Rules (highest priority)

- Authorization is always enforced on the server (Policies/Gates/permissions) for every route, Livewire action, and JSON endpoint — hiding a button is never a permission check.
- Never trust client-sent identity, ids, flags, prices, or ownership; resolve them from the authenticated user and the database. Protect Livewire properties that must not change (`#[Locked]`) and authorize every action.
- Validate every input with Form Requests or Livewire validation rules; no mass assignment of unvalidated data.
- Files live on private disks and are served only through authorized controllers or signed URLs; never expose tokens or secrets in URLs.
- Photos and files are stored once, flat, under a stable internal name and deduplicated by content hash; folder trees shown to users or exported (e.g. the field-return tree, the media library) are virtual, built from the database — renaming never moves files. Photos are downscaled on the device before upload (max 2560 px, JPEG ~82), EXIF is stripped (GPS/time live in the database), thumbnails are WebP, and every ZIP is streamed without temporary files.
- Never log or return secrets, password hashes, or tokens; minimise personal data (GPS especially) and respect retention rules.
- See `docs/specs/11-seguranca.md` for the full requirements; every feature ships with authorization tests.

## Front-end Rules

- No inline styles and no `<style>` blocks in views or components. CSS lives in organised, modular files under `resources/css/` (design tokens as CSS custom properties, base, layout, components, one file per module), imported through Vite, with consistent class naming.
- No inline scripts in views; JavaScript lives in `resources/js/` modules (Alpine components for client-side behaviour, the offline layer for field screens).
- Native browser controls whose text cannot be translated (file inputs, etc.) are wrapped in translated components (e.g. `.file-picker`).
- Visual parity is checked with screenshots against the legacy platform (local legacy copy with fake data) on desktop and phone sizes.
- Never copy real personal data from `docs/planning-data/` into code, seeders, tests, or fixtures; use factories and invented data.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `vendor/bin/sail composer show --direct` to list direct dependencies with versions, or `vendor/bin/sail composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `vendor/bin/sail artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `vendor/bin/sail artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/sail bin phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
