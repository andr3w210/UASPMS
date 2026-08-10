# UI patterns for SPAMS modules

These snippets capture the shared conventions now used across the app.

## Breadcrumbs for a new module page

Use the shared navigation data and render the breadcrumb trail from the current page path.

```php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
```

The top bar and breadcrumb shell will pick up the current module context automatically when the page is included under the normal layout.

## Inline field validation errors

Keep server-side validation, then map errors to each field and render Bootstrap feedback under the relevant input.

```php
$fieldErrors['supplier_id'][] = 'Supplier is required.';
```

```php
<div class="mb-3">
    <label class="form-label">Supplier</label>
    <select class="form-select <?php echo !empty($fieldErrors['supplier_id']) ? 'is-invalid' : ''; ?>" name="supplier_id">
        ...
    </select>
    <?php echo render_field_errors($fieldErrors, 'supplier_id'); ?>
</div>
```

This gives the form a consistent inline validation experience without replacing the existing server-side checks.

## Shared confirm modal

Use the shared helper instead of a native browser confirm dialog.

```php
<button type="submit" class="btn btn-danger"
    onclick="if (window.confirmAction) { window.confirmAction({ title: 'Confirm action', message: 'Delete this record?', confirmText: 'Delete', onConfirm: function () { this.form.submit(); }.bind(this) }); return false; }">
    Delete
</button>
```

The helper opens the shared Bootstrap modal and only continues when the user confirms.

## Toast / flash message

Set a flash message in PHP and let the shared footer render it as a dismissible toast after reload.

```php
set_flash('success', 'Record saved successfully.');
redirect('modules/receivings/index.php');
```

Use one of the supported types: `success`, `danger`, `warning`, or `info`.
