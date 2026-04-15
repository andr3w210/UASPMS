# Tools

This folder contains maintenance and one-off project scripts that are not part of the web UI.

Structure:

- `analysis/` ad hoc analysis and matching helpers
- `audits/` audit and repair inspection scripts
- `backfills/` controlled backfill scripts
- `checks/` quick verification and schema checks
- `reconciliation/` RPCPPE/RPCPPEE reconciliation and normalization scripts
- `reports/` export and reporting helpers

Notes:

- Application entry points remain under `spams/`.
- These scripts were moved out of the project root to reduce clutter.
- Some scripts are historical/recovery utilities and are intentionally kept even if they are not linked from the UI.
