# UASPMS Project Context

Read this file when starting work in this repository. It captures the project
shape and the current work so a new AI assistant can continue without relying on
chat history. For the full contributor and COA rules, also read
[`/.github/copilot-instructions.md`](.github/copilot-instructions.md).

## Project

- UASPMS is the University of Antique Supply and Property Management System.
- The application lives in `spams/`; it is plain PHP with mysqli and MariaDB,
  not a framework.
- The transport subsystem has a separate trip-ticket database.
- Keep credentials in the ignored `spams/.env`. Never read them into responses,
  commit them, or copy them into documentation. `.env.example` contains only
  placeholders.

## Non-negotiable coding conventions

- Use prepared statements for SQL values. Do not concatenate request data into
  SQL.
- Keep strict request schemas (`request_schema_enforce`) and CSRF validation on
  state-changing requests. Preserve role checks and existing audit logging.
- Put shared business logic in existing helpers; avoid parallel implementations.
- Add numbered migrations under `database/`; never edit an already-applied
  migration.
- Use the existing PHP style and UI conventions. Make the smallest complete
  change, then run `C:\xampp\php\php.exe -l <changed-file>` and focused tests.
- Do not commit unless asked or the task's established workflow requires it.

## COA-sensitive rules

- Classification thresholds come from the active `property_thresholds` row;
  never hardcode the threshold amounts.
- Semi-expendable property is expensed on issuance and is not depreciated.
- PPE depreciation rules and useful-life resolution are centralized in
  `spams/app/helpers/common.php`. Reports must reuse those helpers.
- Treat classifications, account codes, useful lives, depreciation, and
  physical-count/report logic as COA-sensitive. Consult the full instructions
  before changing them.

## Recent work and current task

- Recent UI work rebuilt Employees, Suppliers, Offices, and nine simple
  master-data modules around list/profile/form pages. The shared engine is
  `spams/app/helpers/master_data.php`. Classifications was intentionally kept
  separate to preserve its COA-specific behavior.
- Users now has a read-only profile page at
  `spams/modules/users/view.php`; account-changing actions remain in
  `spams/modules/users/index.php` with the existing security checks.
- Purchase Orders uses an Actions dropdown. Its sticky Actions column and
  dropdown behavior are styled in `spams/assets/css/ua-theme.css`.
- Fuel RIS encoding (`spams/modules/trip_tickets/fuel_ris_create.php`) accepts a
  blank RIS number. The list handler in
  `spams/modules/trip_tickets/fuel_ris.php` is kept consistent. A blank manual
  entry is stored as an empty string; other required fields remain required.
  Odometer reading is not collected on manual entries; the database field is
  stored as NULL. Import behavior is separate and should not be changed unless
  requested. The single Add Gasoline Station button is next to the saved-station
  selector and opens the station modal.
- Fuel RIS entries have a "Fuel is for" selector (`fuel_use`: vehicle,
  powerplant, equipment, other). Non-vehicle uses store `use_description` and
  clear vehicle/driver. `requested_by` (usually the unit head, e.g. GSU) is
  separate from the optional driver. Schema: `database/124_trip_fuel_ris_use_and_requester.sql`
  (apply to the trip DB). Imports still default to `fuel_use = 'vehicle'`.
- Receiving records have a read-only detail page, `spams/modules/receivings/view.php`,
  listing delivered/accepted/rejected items per receiving.
- Do not assume the previous security follow-ups (DB password rotation or
  administrative DB credentials) have been completed. Ask the user before
  relying on their status; do not include secret values in this file.

## Testing guidance

- Check PHP syntax with `C:\xampp\php\php.exe -l <file>`.
- Use the existing local browser only when available and authorized. Validate
  the requested behavior directly, not just page loading.
- For database-backed changes, use throwaway records and clean them up. Avoid
  destructive tests against real operational records.
- Never report a test as passing unless it was actually run.
