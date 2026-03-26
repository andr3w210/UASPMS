document.addEventListener('DOMContentLoaded', function () {
    var toggleButton = document.getElementById('sidebarToggle');

    if (toggleButton) {
        toggleButton.addEventListener('click', function () {
            document.body.classList.toggle('toggle-sidebar');
        });
    }

    function initSelect2(scope) {
        if (!window.jQuery || !jQuery.fn.select2) {
            return;
        }

        var root = scope || document;
        var selects = root.querySelectorAll('select.form-select:not([data-no-select2]):not([data-select2-initialized])');

        selects.forEach(function (select) {
            var $select = jQuery(select);
            var placeholder = select.getAttribute('data-placeholder') || 'Select an option';
            var allowClear = select.hasAttribute('data-allow-clear') || Array.from(select.options).some(function (option) {
                return option.value === '';
            });
            var dropdownParent = jQuery(select.parentElement || document.body);

            $select.select2({
                placeholder: placeholder,
                allowClear: allowClear,
                width: '100%',
                dropdownParent: dropdownParent
            });

            select.setAttribute('data-select2-initialized', 'true');
        });
    }

    function refreshSelect2(target) {
        if (!window.jQuery || !jQuery.fn.select2 || !target) {
            return;
        }

        var elements = [];
        if (target instanceof HTMLElement && target.matches('select.form-select:not([data-no-select2])')) {
            elements = [target];
        } else if (target.querySelectorAll) {
            elements = Array.from(target.querySelectorAll('select.form-select:not([data-no-select2])'));
        }

        elements.forEach(function (select) {
            var $select = jQuery(select);
            if ($select.hasClass('select2-hidden-accessible')) {
                $select.select2('destroy');
                select.removeAttribute('data-select2-initialized');
            }
        });

        initSelect2(target);
    }

    function initTableSearch(scope) {
        var root = scope || document;
        var tables = root.querySelectorAll('.table-responsive table.table:not([data-no-table-search]):not([data-table-search-initialized])');

        tables.forEach(function (table) {
            if (table.closest('form')) {
                return;
            }

            var tbody = table.querySelector('tbody');
            if (!tbody) {
                return;
            }

            var rows = Array.from(tbody.querySelectorAll('tr'));
            if (rows.length < 2) {
                return;
            }

            var toolbar = document.createElement('div');
            toolbar.className = 'table-search-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3';
            toolbar.innerHTML = '' +
                '<div class="table-page-size d-flex align-items-center gap-2">' +
                    '<label class="small text-muted mb-0">Show</label>' +
                    '<select class="form-select form-select-sm table-page-size-select" data-no-select2>' +
                        '<option value="10">10</option>' +
                        '<option value="25" selected>25</option>' +
                        '<option value="50">50</option>' +
                        '<option value="100">100</option>' +
                        '<option value="all">All</option>' +
                    '</select>' +
                    '<span class="small text-muted">entries</span>' +
                '</div>' +
                '<div class="table-search-box">' +
                    '<input type="search" class="form-control form-control-sm table-search-input" placeholder="Search table...">' +
                '</div>' +
                '<div class="table-pagination-summary small text-muted"></div>';

            var wrapper = table.closest('.table-responsive');
            if (wrapper) {
                wrapper.parentNode.insertBefore(toolbar, wrapper);
            }

            var input = toolbar.querySelector('.table-search-input');
            var pageSizeSelect = toolbar.querySelector('.table-page-size-select');
            var summary = toolbar.querySelector('.table-pagination-summary');
            var pagination = document.createElement('div');
            pagination.className = 'table-pagination-controls d-flex justify-content-end align-items-center flex-wrap gap-2 mt-3';
            if (wrapper) {
                wrapper.parentNode.insertBefore(pagination, wrapper.nextSibling);
            }

            var currentPage = 1;

            function getFilteredRows() {
                var term = input.value.trim().toLowerCase();
                return rows.filter(function (row) {
                    var text = (row.textContent || '').toLowerCase();
                    return term === '' || text.indexOf(term) !== -1;
                });
            }

            function renderTableState() {
                var filteredRows = getFilteredRows();
                var pageSizeValue = pageSizeSelect.value;
                var pageSize = pageSizeValue === 'all' ? filteredRows.length || 1 : parseInt(pageSizeValue, 10);
                var totalRows = filteredRows.length;
                var totalPages = pageSizeValue === 'all' ? 1 : Math.max(1, Math.ceil(totalRows / pageSize));

                if (currentPage > totalPages) {
                    currentPage = totalPages;
                }
                if (currentPage < 1) {
                    currentPage = 1;
                }

                rows.forEach(function (row) {
                    row.style.display = 'none';
                });

                var startIndex = pageSizeValue === 'all' ? 0 : (currentPage - 1) * pageSize;
                var endIndex = pageSizeValue === 'all' ? totalRows : Math.min(startIndex + pageSize, totalRows);

                filteredRows.slice(startIndex, endIndex).forEach(function (row) {
                    row.style.display = '';
                });

                var emptyRow = tbody.querySelector('.table-search-empty-row');
                if (!emptyRow && totalRows === 0) {
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'table-search-empty-row';
                    emptyRow.innerHTML = '<td colspan="' + (table.querySelectorAll('thead th').length || 1) + '" class="text-center text-muted py-4">No matching records found.</td>';
                    tbody.appendChild(emptyRow);
                } else if (emptyRow && totalRows > 0) {
                    emptyRow.remove();
                }

                if (totalRows === 0) {
                    summary.textContent = 'Showing 0 of 0 entries';
                } else {
                    summary.textContent = 'Showing ' + (startIndex + 1) + ' to ' + endIndex + ' of ' + totalRows + ' entries';
                }

                pagination.innerHTML = '';
                if (totalPages <= 1) {
                    return;
                }

                function addPageButton(label, page, disabled, active) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn btn-sm ' + (active ? 'btn-primary' : 'btn-outline-secondary');
                    button.textContent = label;
                    button.disabled = !!disabled;
                    button.addEventListener('click', function () {
                        currentPage = page;
                        renderTableState();
                    });
                    pagination.appendChild(button);
                }

                addPageButton('Prev', currentPage - 1, currentPage === 1, false);

                for (var page = 1; page <= totalPages; page += 1) {
                    if (totalPages > 7 && page !== 1 && page !== totalPages && Math.abs(page - currentPage) > 1) {
                        if ((page === 2 && currentPage > 4) || (page === totalPages - 1 && currentPage < totalPages - 3)) {
                            var dots = document.createElement('span');
                            dots.className = 'small text-muted px-1';
                            dots.textContent = '...';
                            pagination.appendChild(dots);
                        }
                        continue;
                    }
                    addPageButton(String(page), page, false, page === currentPage);
                }

                addPageButton('Next', currentPage + 1, currentPage === totalPages, false);
            }

            input.addEventListener('input', function () {
                currentPage = 1;
                renderTableState();
            });

            pageSizeSelect.addEventListener('change', function () {
                currentPage = 1;
                renderTableState();
            });

            renderTableState();

            table.setAttribute('data-table-search-initialized', 'true');
        });
    }

    function buildValidationMessage(field) {
        if (field.dataset.validationMessage) {
            return field.dataset.validationMessage;
        }

        if (field.validity.valueMissing) {
            var label = '';
            var id = field.getAttribute('id');
            if (id) {
                var labelNode = document.querySelector('label[for="' + id + '"]');
                label = labelNode ? labelNode.textContent.trim() : '';
            }
            if (!label) {
                label = field.getAttribute('placeholder') || field.getAttribute('name') || 'This field';
            }
            return label + ' is required.';
        }

        if (field.validity.typeMismatch) {
            return 'Please enter a valid value.';
        }

        if (field.validity.rangeUnderflow || field.validity.rangeOverflow) {
            return 'Please enter a value within the allowed range.';
        }

        if (field.validity.stepMismatch) {
            return 'Please enter a valid increment.';
        }

        if (field.validity.patternMismatch) {
            return 'Please match the requested format.';
        }

        return 'Please review this field.';
    }

    function ensureInvalidFeedback(field) {
        if (!(field instanceof HTMLElement) || field.type === 'hidden') {
            return;
        }

        var nextElement = field.nextElementSibling;
        var feedback = nextElement && nextElement.classList && nextElement.classList.contains('invalid-feedback') ? nextElement : null;
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            field.insertAdjacentElement('afterend', feedback);
        }
        feedback.textContent = buildValidationMessage(field);
    }

    function syncSelect2ValidationState(field) {
        if (!(field instanceof HTMLElement) || !window.jQuery) {
            return;
        }

        var $field = jQuery(field);
        if (!$field.hasClass('select2-hidden-accessible')) {
            return;
        }

        var container = $field.next('.select2-container');
        if (!container.length) {
            return;
        }

        var isInvalid = field.classList.contains('is-invalid');
        container.toggleClass('is-invalid', isInvalid);
    }

    function markFieldValidationState(field) {
        if (!(field instanceof HTMLElement) || field.type === 'hidden') {
            return;
        }

        ensureInvalidFeedback(field);

        var invalid = !field.checkValidity();
        field.classList.toggle('is-invalid', invalid);
        field.classList.toggle('is-valid', !invalid && field.value !== '');
        syncSelect2ValidationState(field);
    }

    function initFormValidation(scope) {
        var root = scope || document;
        var forms = [];

        if (root instanceof HTMLFormElement) {
            forms = [root];
        } else if (root.querySelectorAll) {
            forms = Array.from(root.querySelectorAll('form:not([data-form-validation-initialized])'));
        }

        forms.forEach(function (form) {
            if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
                form.setAttribute('data-form-validation-initialized', 'true');
                return;
            }

            form.setAttribute('novalidate', 'novalidate');

            function getValidatableFields() {
                return Array.from(form.querySelectorAll('input, select, textarea')).filter(function (field) {
                    return field.willValidate;
                });
            }

            getValidatableFields().forEach(function (field) {
                ensureInvalidFeedback(field);
            });

            if (!form.hasAttribute('data-validation-listeners-bound')) {
                ['input', 'change', 'blur'].forEach(function (eventName) {
                    form.addEventListener(eventName, function (event) {
                        var field = event.target;
                        if (!(field instanceof HTMLElement) || !field.willValidate) {
                            return;
                        }
                        ensureInvalidFeedback(field);
                        if (form.classList.contains('was-validated') || field.classList.contains('is-invalid')) {
                            markFieldValidationState(field);
                        }
                    }, true);
                });
                form.setAttribute('data-validation-listeners-bound', 'true');
            }

            form.addEventListener('submit', function (event) {
                var fields = getValidatableFields();
                var invalidFields = fields.filter(function (field) {
                    markFieldValidationState(field);
                    return !field.checkValidity();
                });

                if (invalidFields.length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    form.classList.add('was-validated');

                    var firstInvalid = invalidFields[0];
                    if (window.jQuery && jQuery(firstInvalid).hasClass('select2-hidden-accessible')) {
                        jQuery(firstInvalid).select2('open');
                    } else {
                        firstInvalid.focus();
                    }
                }
            });

            form.setAttribute('data-form-validation-initialized', 'true');
        });
    }

    window.SPAMS = window.SPAMS || {};
    window.SPAMS.initSelect2 = initSelect2;
    window.SPAMS.refreshSelect2 = refreshSelect2;
    window.SPAMS.initTableSearch = initTableSearch;
    window.SPAMS.initFormValidation = initFormValidation;
    window.SPAMS.markFieldValidationState = markFieldValidationState;

    initSelect2(document);
    initTableSearch(document);
    initFormValidation(document);

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (node.matches && node.matches('select.form-select:not([data-no-select2])')) {
                    initSelect2(node.parentElement || document);
                    return;
                }

                if (node.querySelectorAll) {
                    initSelect2(node);
                    initTableSearch(node);
                    initFormValidation(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
});
