# bpsc-cmo

**BPSC CMO — Chairman’s Office** is a modular Laravel application for Bangladesh Public Service Commission. It provides shared authentication, user and organizational management, role-based permissions and audit logging. Office workflows are implemented as separate modules accessed through the common Dashboard.

## System Requirements

- PHP 8.3 or later, with the extensions required by `composer.lock`.
- Composer 2 and MySQL 8 or later.
- Node.js 22.12 or later and npm for frontend development/builds.
- A web server pointing to the project's `public/` directory, or Laravel's development server.
- Writable `storage/` and `bootstrap/cache/` directories.

Use the same PHP executable and configuration for Composer, Artisan and the web server. On WAMP, verify the active PHP version and `php.ini` before installation.

## Installation

1. Place the complete project in a `bpsc-cmo` directory and open a terminal there.
2. Create an empty MySQL database, such as `bpsc_cmo`.
3. Install PHP dependencies:

   ```bash
   composer install
   ```

4. Copy `.env.example` to `.env`. Set `APP_NAME`, `APP_URL`, database credentials and other environment settings. Use `APP_ENV=local` for initial development setup.
5. Initialize the application:

   ```bash
   php artisan key:generate
   php artisan migrate --seed
   ```

6. Create the first Administrator interactively:

   ```bash
   php artisan cmo:create-admin
   ```

   This command is available only in the `local` or `development` environment. Choose your own credentials; no default account or password is provided.

7. Build frontend assets when they are not supplied, or after changing frontend source:

   ```bash
   npm ci --ignore-scripts
   npm run build
   ```

8. Start the development server:

   ```bash
   php artisan serve
   ```

   Set `APP_URL` to match the address in use. Sign in at `/login`, then open `/dashboard` to access authorized modules. For WAMP/Apache hosting, use the configured application URL and keep the document root at `public/`.

For hosted environments, use the appropriate `APP_ENV`, set `APP_DEBUG=false`, configure HTTPS/session settings and arrange database backups. Configure the system scheduler to execute `php artisan schedule:run` every minute.

## Development & Maintenance Guidelines

- Keep Dashboard, Administration, authentication, account management, permissions and audit services common. Put business workflows in separate modules with their own routes, controllers, views and permissions.
- Enforce permissions on server routes and actions as well as menu visibility. Preserve existing account protections and audit behavior.
- Add database changes through new migrations. Preserve existing data and submitted records unless an approved requirement explicitly changes that behavior.
- Use `composer install` and `npm ci --ignore-scripts` to honor lockfiles. Change dependency versions deliberately and commit their updated lockfiles.
- Run `npm run build` after frontend changes. Include compiled assets in ZIP deliveries when needed.
- Run the **full test suite** with `php artisan test` and resolve failures before delivering a code update. Add meaningful regression tests for changed behavior.
- Keep `.env`, credentials, database files, logs, runtime caches, `vendor/` and `node_modules/` out of source control and update ZIPs.
- Back up the application and database before applying updates. Follow each release's instructions in `docs/`; run `php artisan optimize:clear` and `php artisan migrate` when the update requires them. Never run `migrate:fresh` against an installation containing data.

## Documentation Policy

This `readme.md` is the project's **fixed reference** for its description, installation and development guidelines. Keep it unchanged during routine feature additions, fixes and releases.

Store module specifications, business rules, change logs, release/update instructions, migration notes, test results and known limitations in separate files under `docs/`. Do not append release history or temporary implementation details to this README.
