# UASPMS — GitHub Copilot Instructions

This file gives Copilot the COA (Commission on Audit) rules this system must follow,
plus the conventions already established in this codebase, so suggestions stay
consistent instead of re-deriving logic from scratch.

## Project shape

- Plain PHP + mysqli, no framework. Prepared statements throughout — never
  build SQL with string concatenation.
- Numbered migration files in `database/` (`NNN_description.sql`) — always add
  a new numbered migration, never edit an already-applied one.
- Shared business logic (classification, depreciation, useful life resolution)
  lives in `spams/app/helpers/common.php`. Reports call into these helpers
  rather than recomputing rules inline — keep it that way.

## COA classification rules (source of truth: `property_thresholds` table)

Every acquisition is classified by unit cost against the active row in
`property_thresholds` (legal basis: COA Circular No. 2022-004, May 31, 2022):

| Unit cost | Classification |
|---|---|
| ≥ `equipment_min` (₱50,000) | Equipment (PPE) — capitalized, depreciated |
| `semi_hv_min` (₱5,000.01) to just under `equipment_min` | Semi-expendable, High Value |
| < `semi_hv_min` (≤ ₱5,000.00) | Semi-expendable, Low Value |

Never hardcode 50000 or 5000 anywhere. Always read the active
`property_thresholds` row (see `receivings/index.php` and
`distributions/index.php` for the existing pattern) so a future threshold
change (COA does revise these) is a data change, not a code change.

Semi-expendable property is expensed on issuance, not capitalized — it is
still tracked via memorandum/property records (RSEP/property card), split by
`semi_expendable_type` (`high_value` / `low_value`) on `stock_items` and
`distributions`.

## Depreciation (Equipment / PPE only — never applied to semi-expendable)

Governed by the Government Accounting Manual (GAM) Volume III (PPSAS-aligned),
straight-line method. Implemented in
`calculate_accumulated_depreciation()` in `spams/app/helpers/common.php`.

Correct formula:
- Salvage value = 5% of acquisition cost.
  Warning: the function currently uses `$cost * 0.10` (10%) in two places
  (the early-return default and the main calculation). Both must be changed to
  `0.05`. This affects RPCPPE figures, the ledger card printout
  (`property/ledger_card_print.php`), and IIRUP (`reports/iirup.php`) — all
  three call this same helper, so fixing it in one place fixes all of them. Do
  not duplicate the depreciation formula elsewhere; always call this helper.
- Depreciable base = cost − salvage value.
- Monthly depreciation = depreciable base ÷ (useful_life_years × 12).
- Depreciation starts the month after the month of acquisition (already
  correct in the code — preserve this convention).
- Accumulated depreciation is capped at the depreciable base; carrying amount
  never drops below salvage value (already correct — preserve this).

Useful life resolution order (already correct — preserve this):
1. `classifications.useful_life_years` if set.
2. Else `account_codes.default_useful_life_years` for the item's GAM account
   code.
3. Else `null` (no depreciation computed).

Annex A useful-life schedule supplied for this system:
- Land Improvements: 10 years; runways/taxiways: 20; railways: 40; electrification, power and energy structures: 10.
- Buildings: wood 10; mixed 20; concrete 30.
- Leasehold improvements: the shorter of the lease term and the applicable asset life.
- Office Equipment: 5; Furniture and Fixtures: 10; IT Equipment - Hardware: 5; Library Books: 5.
- Machineries and Equipment: machineries, agricultural/fishery/forestry, airport, communication, construction/heavy, hospital, medical/dental/laboratory, military/police, sports, technical/scientific, and other machinery: 10, except firefighting equipment and accessories: 7.
- Transportation Equipment: motor vehicles: 7; trains, aircraft/aircraft ground equipment, watercrafts, and other transportation equipment: 10.
- Other Property, Plant and Equipment: 5.

Use a classification-specific value when the material, subtype, or lease term is
known. Do not infer the building subtype or lease term from a broad account
code alone. The active Annex A values are maintained through numbered database
migrations and the PHP fallback map in `common.php`.

## End-to-end workflow (encode → receive → distribute)

This is the actual, verified flow through the codebase. Copilot should treat
this as the intended shape of the pipeline — new features should slot into
one of these stages, not bypass them.

1. Encode PO (`purchase_orders/`) — header + line items keyed in from the
   hard-copy purchase order.
2. Receive (`receivings/index.php`) — for each PO line, the receiver records
   `actual_item_description` and compares it against the PO's original
   description. Any mismatch is captured as a `variance_type`
   (`none`, `higher_specs`, `substitution`, `defective`, `short_delivery`,
   `other`) — a note is required whenever `variance_type !== 'none'`
   (see the validation in `receivings/index.php` around the variance fields).
   Do not let a variance be saved silently without a note.
3. Print IAR (`receivings/iar.php`) — gated on the receiving record's
   `status` being `completed` or `partial`. Never expose IAR printing for a
   receiving that hasn't been saved/checked yet — preserve this status check
   when touching this file.
4. Distribute (`distributions/index.php`) — assigns a property number via
   `generate_property_number()` (format: `Year-Fund-AccountGroup-Serial`)
   and routes to the correct output document:
   - Equipment → PAR (`distributions/par.php`)
   - Semi-expendable (both High-Value and Low-Value) → ICS
     (`distributions/ics.php`), toggled by `semi_type` in the UI.
     Per-unit property tagging (brand/model/serial/property number) is
     intentionally applied to Low-Value semi-expendable items too, not just
     High-Value — this is a deliberate choice for this office, not a bug.
     Do not simplify this to bulk-only tracking for Low-Value items without an
     explicit request.
   - Plain supply/consumable items never enter this per-unit path
     (`$isTracked` in `receivings/index.php` only covers `equipment` and
     `semi_expendable`) — they're issued via a separate RIS flow
     (`receivings/ris.php`), not through `distributions/`.
5. Print ICS/PAR/RIS — only distribution detail rows with `is_distributed = 1`
   appear on the printed slip (see the `did.is_distributed = 1` filters in
   `par.php` / `ics.php`) — a slip reflects what's actually been handed over,
   not the full requested quantity.

When adding a new module or report, trace which of these five stages it
belongs to and reuse the existing tables/helpers for that stage rather than
introducing a parallel path.

## Reports required and where they live

| Report | Scope | File |
|---|---|---|
| RPCPPE (Report on the Physical Count of Property, Plant and Equipment) | Equipment only, annual, as-of Dec 31 by default | `reports/rpcppe.php`, `reports/rpcppe_batches.php` |
| RPCSEP-equivalent (physical count of semi-expendable property) | Semi-expendable only, split HV/LV | `reports/semi_physical_count.php` |
| Semi-expendable registry / issued / RRSP / RLSDDP / unserviceable | Supporting semi-expendable reports | `reports/semi_registry.php`, `semi_issued_report.php`, `semi_rrsp.php`, `semi_rlsddp.php`, `semi_unserviceable.php` |
| IIRUP (Inventory and Inspection Report of Unserviceable Property) | Disposal candidates, both equipment and semi-expendable | `reports/iirup.php` |
| PAR / ICS | Issuance acknowledgment — PAR for equipment, ICS for semi-expendable | `modules/distributions/`, `modules/property/` |

Rules Copilot should enforce when touching these:
- RPCPPE must never include semi-expendable items, and the semi-expendable
  reports must never include equipment (PPE) items. Filter by the
  classification derived from `property_thresholds`, not by account code
  string-matching.
- RPCPPE figures (acquisition cost, accumulated depreciation, carrying
  amount) must come from `calculate_accumulated_depreciation()` — never
  recompute depreciation inline in a report file.
- Any report showing "as of" a date must use that date consistently for both
  the depreciation cutoff and the physical-count cutoff — don't mix today's
  date with a historical as-of date.
- Disposal reasons must stay normalized to the COA-standard set (see
  migration `094_normalize_disposal_reasons_coa.sql`) — don't introduce new
  free-text disposal reasons without adding them to that normalization list.

## When suggesting new code

- Match existing helper functions before writing new logic — check
  `spams/app/helpers/common.php` first (`classification_default_useful_life_years`,
  `resolve_effective_useful_life_years`, `calculate_accumulated_depreciation`).
- New migrations go in `database/` with the next sequential number and a
  descriptive name, matching the existing style.
- If a change affects classification, depreciation, or report thresholds,
  flag it explicitly in the PR/commit message as a COA-compliance-affecting
  change, since these numbers ultimately reconcile against the agency's books
  with COA auditors.
