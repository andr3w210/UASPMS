window.__spamsPendingInitDataTables = window.__spamsPendingInitDataTables || [];
window.__spamsPendingMasterDataLists = window.__spamsPendingMasterDataLists || [];

function normalizeToastType(type) {
    var value = (type || 'info').toString().toLowerCase();
    if (value === 'error') {
        return 'danger';
    }

    return value === 'success' || value === 'danger' || value === 'warning' || value === 'info'
        ? value
        : 'info';
}

function ensureToastContainer() {
    var container = document.getElementById('globalToastContainer');
    if (container) {
        return container;
    }

    container = document.createElement('div');
    container.id = 'globalToastContainer';
    container.className = 'toast-container position-fixed top-0 end-0 p-3';
    container.style.zIndex = '1080';
    document.body.appendChild(container);
    return container;
}

function showToast(message, type) {
    if (!window.bootstrap || !bootstrap.Toast || !message) {
        return null;
    }

    var normalizedType = normalizeToastType(type);
    var container = ensureToastContainer();
    var toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-bg-' + normalizedType + ' border-0 shadow';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    var toastBody = document.createElement('div');
    toastBody.className = 'd-flex';

    var bodyContent = document.createElement('div');
    bodyContent.className = 'toast-body';
    bodyContent.textContent = message;

    var closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn-close btn-close-white me-2 m-auto';
    closeButton.setAttribute('data-bs-dismiss', 'toast');
    closeButton.setAttribute('aria-label', 'Close');

    toastBody.appendChild(bodyContent);
    toastBody.appendChild(closeButton);
    toastEl.appendChild(toastBody);
    container.appendChild(toastEl);

    var toast = new bootstrap.Toast(toastEl, { delay: 5000 });
    toastEl.addEventListener('hidden.bs.toast', function () {
        toastEl.remove();
    });

    toast.show();
    return toast;
}

window.showToast = showToast;

function attachImagePreview(inputSelector, previewContainerSelector) {
    var input = typeof inputSelector === 'string' ? document.querySelector(inputSelector) : inputSelector;
    var previewContainer = typeof previewContainerSelector === 'string' ? document.querySelector(previewContainerSelector) : previewContainerSelector;

    if (!input || !previewContainer) {
        return;
    }

    input.addEventListener('change', function () {
        var file = input.files && input.files[0] ? input.files[0] : null;
        if (!file || !file.type || !file.type.startsWith('image/')) {
            previewContainer.innerHTML = '';
            previewContainer.classList.remove('d-block');
            return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
            previewContainer.innerHTML = '';
            previewContainer.classList.add('d-block');

            var img = document.createElement('img');
            img.src = event.target.result;
            img.alt = 'Selected image preview';
            img.className = 'img-fluid rounded border shadow-sm';
            img.style.maxHeight = '220px';
            img.style.objectFit = 'cover';
            previewContainer.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
}

window.attachImagePreview = attachImagePreview;

if (typeof window.initDataTable !== 'function') {
    window.initDataTable = function () {
        window.__spamsPendingInitDataTables.push(Array.prototype.slice.call(arguments));
        return null;
    };
}

if (typeof window.initMasterDataList !== 'function') {
    window.initMasterDataList = function () {
        window.__spamsPendingMasterDataLists.push(Array.prototype.slice.call(arguments));
        return null;
    };
}

document.addEventListener('DOMContentLoaded', function () {
    var sessionTimeoutMinutes = 30;
    var sessionTimeoutMeta = document.querySelector('meta[name="spams-session-timeout-minutes"]');
    if (sessionTimeoutMeta) {
        var parsedTimeout = parseInt(sessionTimeoutMeta.getAttribute('content'), 10);
        if (!isNaN(parsedTimeout) && parsedTimeout >= 5 && parsedTimeout <= 480) {
            sessionTimeoutMinutes = parsedTimeout;
        }
    }

    var warningBeforeMinutes = Math.min(2, Math.max(1, sessionTimeoutMinutes - 1));
    var warningThresholdMs = Math.max(60000, (sessionTimeoutMinutes - warningBeforeMinutes) * 60 * 1000);
    var idleWarningShown = false;
    var idleWarningModal = null;
    var idleWarningTimer = null;
    var idleResetTimer = null;
    var idleWarningCountdownTimer = null;
    var idleWarningSecondsRemaining = 0;

    function createIdleWarningModal() {
        if (idleWarningModal) {
            return idleWarningModal;
        }

        var modalElement = document.getElementById('idleSessionWarningModal');
        if (modalElement) {
            idleWarningModal = bootstrap.Modal.getOrCreateInstance(modalElement);
            return idleWarningModal;
        }

        var modalHtml = [
            '<div class="modal fade" id="idleSessionWarningModal" tabindex="-1" aria-labelledby="idleSessionWarningModalLabel" aria-hidden="true">',
            '    <div class="modal-dialog modal-dialog-centered">',
            '        <div class="modal-content">',
            '            <div class="modal-header">',
            '                <h5 class="modal-title" id="idleSessionWarningModalLabel">Session timeout warning</h5>',
            '                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>',
            '            </div>',
            '            <div class="modal-body">',
            '                <p class="mb-2">You have been inactive for a while.</p>',
            '                <p class="mb-0">Your session will expire in <span id="idleSessionWarningCountdown">' + warningBeforeMinutes + '</span> minute(s).',
            '                <span class="d-block mt-2 text-muted">Choose “Stay signed in” to continue working.</span>',
            '                </p>',
            '            </div>',
            '            <div class="modal-footer">',
            '                <button type="button" class="btn btn-primary" id="idleSessionKeepAliveButton">Stay signed in</button>',
            '            </div>',
            '        </div>',
            '    </div>',
            '</div>'
        ].join('');

        var wrapper = document.createElement('div');
        wrapper.innerHTML = modalHtml;
        document.body.appendChild(wrapper.firstElementChild);
        modalElement = document.getElementById('idleSessionWarningModal');
        idleWarningModal = bootstrap.Modal.getOrCreateInstance(modalElement);
        return idleWarningModal;
    }

    function resetIdleWarning() {
        idleWarningShown = false;
        idleWarningSecondsRemaining = 0;
        if (idleWarningCountdownTimer) {
            window.clearInterval(idleWarningCountdownTimer);
            idleWarningCountdownTimer = null;
        }
        if (idleWarningModal) {
            try {
                idleWarningModal.hide();
            } catch (error) {
                // Ignore modal hide errors.
            }
        }
        if (idleResetTimer) {
            window.clearTimeout(idleResetTimer);
        }
        idleResetTimer = window.setTimeout(function () {
            startIdleWarningTimer();
        }, warningThresholdMs);
    }

    function updateIdleWarningCountdown() {
        var countdownEl = document.getElementById('idleSessionWarningCountdown');
        if (!countdownEl) {
            return;
        }

        if (idleWarningSecondsRemaining <= 0) {
            countdownEl.textContent = '0';
            return;
        }

        var minutes = Math.floor(idleWarningSecondsRemaining / 60);
        var seconds = idleWarningSecondsRemaining % 60;
        countdownEl.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    }

    function startIdleWarningTimer() {
        if (idleWarningTimer) {
            window.clearTimeout(idleWarningTimer);
        }

        idleWarningShown = false;
        idleWarningTimer = window.setTimeout(function () {
            idleWarningShown = true;
            idleWarningSecondsRemaining = warningBeforeMinutes * 60;
            createIdleWarningModal();
            var countdownEl = document.getElementById('idleSessionWarningCountdown');
            if (countdownEl) {
                countdownEl.textContent = idleWarningSecondsRemaining + '';
            }
            idleWarningModal.show();
            idleWarningCountdownTimer = window.setInterval(function () {
                if (idleWarningSecondsRemaining <= 0) {
                    window.clearInterval(idleWarningCountdownTimer);
                    idleWarningCountdownTimer = null;
                    return;
                }
                idleWarningSecondsRemaining -= 1;
                updateIdleWarningCountdown();
            }, 1000);
        }, warningThresholdMs);
    }

    function pingSessionKeepAlive() {
        if (window.fetch) {
            fetch(window.SPAMS_KEEP_ALIVE_URL || 'auth/keep_alive.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (response) {
                if (response.ok) {
                    resetIdleWarning();
                }
            }).catch(function () {
                // Ignore keep-alive errors.
            });
        }
    }

    document.addEventListener('mousemove', resetIdleWarning);
    document.addEventListener('keydown', resetIdleWarning);
    document.addEventListener('touchstart', resetIdleWarning);
    document.addEventListener('click', resetIdleWarning);
    document.addEventListener('scroll', resetIdleWarning);

    document.addEventListener('click', function (event) {
        var keepAliveButton = event.target.closest('#idleSessionKeepAliveButton');
        if (!keepAliveButton) {
            return;
        }
        event.preventDefault();
        pingSessionKeepAlive();
    }, true);

    startIdleWarningTimer();

    var toggleButton = document.getElementById('sidebarToggle');
    var toggleIcon = toggleButton ? toggleButton.querySelector('i') : null;
    var sidebar = document.getElementById('sidebar');
    var SIDEBAR_PREF_KEY = 'spams.sidebar.desktop';
    var sidebarTooltipInstances = [];
    var sidebarTransitionTimer = null;

    function isMobileViewport() {
        return window.matchMedia('(max-width: 991.98px)').matches;
    }

    function isCompactDesktopViewport() {
        return window.matchMedia('(min-width: 992px)').matches && window.matchMedia('(max-width: 1366px)').matches;
    }

    function sidebarIsHidden() {
        return isMobileViewport()
            ? !document.body.classList.contains('toggle-sidebar')
            : document.body.classList.contains('toggle-sidebar');
    }

    function disposeSidebarTooltips() {
        sidebarTooltipInstances.forEach(function (instance) {
            if (instance && typeof instance.dispose === 'function') {
                instance.dispose();
            }
        });
        sidebarTooltipInstances = [];

        if (!sidebar) {
            return;
        }

        Array.from(sidebar.querySelectorAll('[data-sidebar-tooltip="1"]')).forEach(function (link) {
            link.removeAttribute('title');
            link.removeAttribute('data-bs-placement');
            link.removeAttribute('data-bs-trigger');
            link.removeAttribute('data-sidebar-tooltip');

            if (link.classList.contains('menu-toggle') && link.hasAttribute('data-bs-target')) {
                link.setAttribute('data-bs-toggle', 'collapse');
            }
        });
    }

    function resolveSidebarLinkLabel(link) {
        if (!link) {
            return '';
        }

        var existing = (link.getAttribute('data-sidebar-tooltip-label') || '').trim();
        if (existing !== '') {
            return existing;
        }

        var span = link.querySelector('span');
        var label = span ? (span.textContent || '').trim() : '';
        if (label === '') {
            label = (link.getAttribute('aria-label') || '').trim();
        }
        if (label === '') {
            label = (link.textContent || '').trim();
        }

        if (label !== '') {
            link.setAttribute('data-sidebar-tooltip-label', label);
        }

        return label;
    }

    function syncSidebarTooltips() {
        disposeSidebarTooltips();

        if (!sidebar || isMobileViewport() || !document.body.classList.contains('toggle-sidebar')) {
            return;
        }
        if (!window.bootstrap || !bootstrap.Tooltip) {
            return;
        }

        var links = Array.from(sidebar.querySelectorAll('.sidebar-nav > .nav-item > a.nav-link, .sidebar-nav .menu-toggle'));
        links.forEach(function (link) {
            var label = resolveSidebarLinkLabel(link);
            if (label === '') {
                return;
            }

            link.setAttribute('title', label);
            link.setAttribute('data-sidebar-tooltip', '1');

            var instance = new bootstrap.Tooltip(link, {
                container: 'body',
                boundary: 'window',
                placement: 'right',
                trigger: 'hover focus'
            });
            sidebarTooltipInstances.push(instance);
        });
    }

    function syncSidebarToggleUI() {
        if (!toggleButton) {
            syncSidebarTooltips();
            return;
        }

        var hidden = sidebarIsHidden();
        toggleButton.setAttribute('aria-label', hidden ? 'Show sidebar' : 'Hide sidebar');
        toggleButton.setAttribute('title', hidden ? 'Show sidebar' : 'Hide sidebar');

        if (toggleIcon) {
            toggleIcon.className = hidden ? 'bi bi-layout-sidebar-inset fs-3' : 'bi bi-layout-sidebar fs-3';
        }

        syncSidebarTooltips();
    }

    function rememberDesktopSidebarPreference(value) {
        if (isMobileViewport()) {
            return;
        }

        try {
            if (window.localStorage) {
                localStorage.setItem(SIDEBAR_PREF_KEY, value);
            }
        } catch (error) {
            // Ignore storage access errors.
        }
    }

    function markSidebarTransitioning() {
        document.body.classList.add('sidebar-is-transitioning');
        if (sidebarTransitionTimer) {
            window.clearTimeout(sidebarTransitionTimer);
        }
        sidebarTransitionTimer = window.setTimeout(function () {
            document.body.classList.remove('sidebar-is-transitioning');
            sidebarTransitionTimer = null;
            syncSidebarToggleUI();
        }, 280);
    }

    function closeSidebarGroups() {
        if (!sidebar) {
            return;
        }

        Array.from(sidebar.querySelectorAll('.nav-content.show')).forEach(function (content) {
            if (window.bootstrap && bootstrap.Collapse) {
                bootstrap.Collapse.getOrCreateInstance(content, { toggle: false }).hide();
            } else {
                content.classList.remove('show');
            }
        });

        Array.from(sidebar.querySelectorAll('.menu-toggle')).forEach(function (toggle) {
            toggle.classList.add('collapsed');
            toggle.setAttribute('aria-expanded', 'false');
        });
    }

    function setDesktopSidebarCollapsed(collapsed) {
        if (isMobileViewport()) {
            return;
        }

        if (collapsed) {
            closeSidebarGroups();
        }

        document.body.classList.toggle('toggle-sidebar', collapsed);
        rememberDesktopSidebarPreference(collapsed ? 'collapsed' : 'open');
        markSidebarTransitioning();
        syncSidebarToggleUI();
    }

    function openSidebarGroupFromCollapsed(toggle) {
        if (!toggle) {
            return;
        }

        setDesktopSidebarCollapsed(false);

        var targetSelector = toggle.getAttribute('data-bs-target');
        var target = targetSelector ? document.querySelector(targetSelector) : null;
        if (!target) {
            return;
        }

        window.requestAnimationFrame(function () {
            if (window.bootstrap && bootstrap.Collapse) {
                bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).show();
            } else {
                target.classList.add('show');
            }

            toggle.classList.remove('collapsed');
            toggle.setAttribute('aria-expanded', 'true');
        });
    }

    function applyCompactMonitorClass() {
        document.body.classList.toggle('compact-monitor', isCompactDesktopViewport());
    }

    function applyDesktopSidebarPreference() {
        if (isMobileViewport()) {
            syncSidebarToggleUI();
            return;
        }

        var storedPref = null;
        try {
            storedPref = window.localStorage ? localStorage.getItem(SIDEBAR_PREF_KEY) : null;
        } catch (error) {
            storedPref = null;
        }

        if (storedPref === 'collapsed') {
            document.body.classList.add('toggle-sidebar');
            syncSidebarToggleUI();
            return;
        }
        if (storedPref === 'open') {
            document.body.classList.remove('toggle-sidebar');
            syncSidebarToggleUI();
            return;
        }

        document.body.classList.remove('toggle-sidebar');
        syncSidebarToggleUI();
    }

    if (toggleButton) {
        toggleButton.addEventListener('click', function () {
            if (isMobileViewport()) {
                document.body.classList.toggle('toggle-sidebar');
                syncSidebarToggleUI();
                return;
            }

            setDesktopSidebarCollapsed(!document.body.classList.contains('toggle-sidebar'));
        });
    }

    if (sidebar) {
        sidebar.addEventListener('click', function (event) {
            var link = event.target.closest('a');
            if (!link) {
                return;
            }

            var isGroupToggle = link.classList.contains('menu-toggle');
            var href = (link.getAttribute('href') || '').trim();
            var isNavigableLink = href !== '' && href !== '#' && !href.toLowerCase().startsWith('javascript:');
            if (!isMobileViewport()) {
                if (document.body.classList.contains('toggle-sidebar') && isGroupToggle) {
                    event.preventDefault();
                    event.stopPropagation();
                    openSidebarGroupFromCollapsed(link);
                } else if (!isGroupToggle && isNavigableLink) {
                    rememberDesktopSidebarPreference('collapsed');
                }
            }

            syncSidebarToggleUI();
        });
    }

    document.addEventListener('click', function (event) {
        if (!isMobileViewport() || !document.body.classList.contains('toggle-sidebar')) {
            return;
        }

        var clickedToggle = toggleButton && (event.target === toggleButton || toggleButton.contains(event.target));
        var clickedInsideSidebar = sidebar && (event.target === sidebar || sidebar.contains(event.target));

        if (!clickedToggle && !clickedInsideSidebar) {
            document.body.classList.remove('toggle-sidebar');
            syncSidebarToggleUI();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isMobileViewport()) {
            document.body.classList.remove('toggle-sidebar');
            syncSidebarToggleUI();
        }
    });

    window.addEventListener('resize', function () {
        if (!isMobileViewport()) {
            applyDesktopSidebarPreference();
        } else {
            document.body.classList.remove('toggle-sidebar');
            syncSidebarToggleUI();
        }
        applyCompactMonitorClass();
    });

    applyCompactMonitorClass();
    applyDesktopSidebarPreference();
    syncSidebarToggleUI();

    function initSelect2(scope) {
        if (!window.jQuery || !jQuery.fn.select2) {
            return;
        }

        var root = scope || document;
        var selector = 'select.form-select:not([data-no-select2]):not([data-select2-initialized])';
        var selects = [];

        if (root instanceof HTMLElement && root.matches(selector)) {
            selects = [root];
        } else if (root.querySelectorAll) {
            selects = Array.from(root.querySelectorAll(selector));
        }

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

    function initMobileTableFrames(scope) {
        var root = scope || document;
        if (document.body && document.body.classList && document.body.classList.contains('po-print-page')) {
            return;
        }

        var wrappers = root.querySelectorAll
            ? root.querySelectorAll('.table-responsive:not(.mobile-table-frame)')
            : [];

        wrappers.forEach(function (wrapper) {
            if (wrapper.hasAttribute('data-no-mobile-frame')) {
                return;
            }
            if (!wrapper.querySelector('table')) {
                return;
            }
            wrapper.classList.add('mobile-table-frame');
        });
    }

    function initTableSearch(scope) {
        var root = scope || document;
        var tables = root.querySelectorAll('.table-responsive table.table:not([data-no-table-search]):not([data-table-search-initialized])');

        tables.forEach(function (table) {
            if (table.id === 'dataTable' || table.hasAttribute('data-managed-datatable')) {
                return;
            }

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

    function initDataTable(tableId, options) {
        var settings = Object.assign({
            searchInputId: 'tableSearch',
            statusFilterId: 'statusFilter',
            prevButtonId: 'prevPage',
            nextButtonId: 'nextPage',
            pageInfoId: 'pageInfo',
            perPageSelectId: 'perPageSelect',
            recordCountId: 'recordCount',
            clearButtonId: null,
            extraFilterIds: [],
            rowFilter: null,
            rowSelector: 'tbody tr',
            includeSingleCellRows: false,
            pageInfoFormatter: null,
            recordCountFormatter: null
        }, options || {});

        var table = document.getElementById(tableId);
        if (!table) {
            return null;
        }

        table.setAttribute('data-no-table-search', 'true');
        table.setAttribute('data-table-search-initialized', 'true');
        table.setAttribute('data-managed-datatable', 'true');

        var tbody = table.querySelector('tbody');
        if (!tbody) {
            return null;
        }

        var searchInput = settings.searchInputId ? document.getElementById(settings.searchInputId) : null;
        var statusFilter = settings.statusFilterId ? document.getElementById(settings.statusFilterId) : null;
        var prevButton = settings.prevButtonId ? document.getElementById(settings.prevButtonId) : null;
        var nextButton = settings.nextButtonId ? document.getElementById(settings.nextButtonId) : null;
        var pageInfo = settings.pageInfoId ? document.getElementById(settings.pageInfoId) : null;
        var perPageSelect = settings.perPageSelectId ? document.getElementById(settings.perPageSelectId) : null;
        var recordCount = settings.recordCountId ? document.getElementById(settings.recordCountId) : null;
        var clearButton = settings.clearButtonId ? document.getElementById(settings.clearButtonId) : null;
        var sortCol = -1;
        var sortDir = 'asc';
        var currentPage = 1;
        var perPage = parseInt(perPageSelect && perPageSelect.value, 10) || 25;

        function getRows() {
            return Array.from(tbody.querySelectorAll(settings.rowSelector)).filter(function (row) {
                return settings.includeSingleCellRows || row.cells.length > 1;
            });
        }

        function buildFilterState() {
            var extraFilters = {};
            settings.extraFilterIds.forEach(function (filterId) {
                extraFilters[filterId] = (document.getElementById(filterId) || {}).value || '';
            });

            return {
                term: ((searchInput || {}).value || '').toLowerCase(),
                status: ((statusFilter || {}).value || ''),
                extraFilters: extraFilters
            };
        }

        function updateRecordCount(totalVisible, totalOverall, rangeStart, rangeEnd, totalPages) {
            if (!recordCount) {
                return;
            }

            if (typeof settings.recordCountFormatter === 'function') {
                recordCount.textContent = settings.recordCountFormatter({
                    totalVisible: totalVisible,
                    totalOverall: totalOverall,
                    rangeStart: rangeStart,
                    rangeEnd: rangeEnd,
                    currentPage: currentPage,
                    totalPages: totalPages
                });
                return;
            }

            recordCount.textContent = 'Showing ' + totalVisible + ' of ' + totalOverall + ' records';
        }

        function renderPage() {
            var allRows = getRows();
            var visibleRows = allRows.filter(function (row) {
                return row.dataset.visible !== '0';
            });
            var total = visibleRows.length;
            var pages = Math.max(1, Math.ceil(total / perPage));
            var start = 0;
            var end = 0;

            currentPage = Math.min(currentPage, pages);

            allRows.forEach(function (row) {
                row.style.display = 'none';
            });

            if (total > 0) {
                start = (currentPage - 1) * perPage;
                end = Math.min(start + perPage, total);
                visibleRows.slice(start, end).forEach(function (row) {
                    row.style.display = '';
                });
            }

            updateRecordCount(total, allRows.length, total > 0 ? start + 1 : 0, end, pages);

            if (pageInfo) {
                if (typeof settings.pageInfoFormatter === 'function') {
                    pageInfo.textContent = settings.pageInfoFormatter({
                        totalVisible: total,
                        totalOverall: allRows.length,
                        rangeStart: total > 0 ? start + 1 : 0,
                        rangeEnd: end,
                        currentPage: currentPage,
                        totalPages: pages
                    });
                } else {
                    pageInfo.textContent = 'Page ' + currentPage + ' of ' + pages + ' (' + total + ' records)';
                }
            }

            if (prevButton) {
                prevButton.disabled = currentPage <= 1;
            }
            if (nextButton) {
                nextButton.disabled = currentPage >= pages;
            }
        }

        function applyFilters() {
            var filterState = buildFilterState();

            getRows().forEach(function (row) {
                var textMatch = !filterState.term || row.textContent.toLowerCase().indexOf(filterState.term) !== -1;
                var statusMatch = !filterState.status || row.dataset.status === filterState.status;
                var customMatch = typeof settings.rowFilter === 'function' ? settings.rowFilter(row, filterState) : true;

                row.dataset.visible = textMatch && statusMatch && customMatch ? '1' : '0';
            });

            currentPage = 1;
            renderPage();
        }

        function resetFilters() {
            if (searchInput) {
                searchInput.value = '';
            }
            if (statusFilter) {
                statusFilter.value = '';
            }

            settings.extraFilterIds.forEach(function (filterId) {
                var filterNode = document.getElementById(filterId);
                if (filterNode) {
                    filterNode.value = '';
                }
            });

            applyFilters();
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', applyFilters);
        }
        settings.extraFilterIds.forEach(function (filterId) {
            var filterNode = document.getElementById(filterId);
            if (filterNode) {
                filterNode.addEventListener('change', applyFilters);
                if (filterNode.tagName === 'INPUT' && filterNode.type === 'search') {
                    filterNode.addEventListener('input', applyFilters);
                }
            }
        });
        if (clearButton) {
            clearButton.addEventListener('click', resetFilters);
        }
        if (prevButton) {
            prevButton.addEventListener('click', function () {
                currentPage -= 1;
                renderPage();
            });
        }
        if (nextButton) {
            nextButton.addEventListener('click', function () {
                currentPage += 1;
                renderPage();
            });
        }
        if (perPageSelect) {
            perPageSelect.addEventListener('change', function () {
                perPage = parseInt(this.value, 10) || 25;
                currentPage = 1;
                renderPage();
            });
        }

        table.querySelectorAll('th[data-sort]').forEach(function (th, idx) {
            th.setAttribute('data-sort-initialized', '1');
            th.style.cursor = 'pointer';
            th.addEventListener('click', function () {
                var rows = getRows();
                var dir = sortCol === idx && sortDir === 'asc' ? 'desc' : 'asc';
                sortCol = idx;
                sortDir = dir;

                rows.sort(function (a, b) {
                    var at = a.cells[idx] ? a.cells[idx].textContent.trim().toLowerCase() : '';
                    var bt = b.cells[idx] ? b.cells[idx].textContent.trim().toLowerCase() : '';
                    return dir === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at);
                });

                rows.forEach(function (row) {
                    tbody.appendChild(row);
                });

                table.querySelectorAll('th[data-sort] i').forEach(function (icon) {
                    icon.className = 'bi bi-arrow-down-up text-muted small';
                });

                var icon = th.querySelector('i');
                if (icon) {
                    icon.className = 'bi bi-arrow-' + (dir === 'asc' ? 'up' : 'down') + ' text-primary small';
                }

                renderPage();
            });
        });

        applyFilters();

        return {
            applyFilters: applyFilters,
            renderPage: renderPage,
            resetFilters: resetFilters
        };
    }
    function initMasterDataList(tableId, options) {
        var settings = Object.assign({
            searchInputId: 'tableSearch',
            statusFilterId: 'statusFilter',
            prevButtonId: 'prevPage',
            nextButtonId: 'nextPage',
            pageInfoId: 'pageInfo',
            perPageSelectId: 'perPageSelect',
            recordCountId: 'recordCount',
            rowMatcher: null,
            recordCountFormatter: null,
            pageInfoFormatter: null,
            emptyMessage: 'No matching records found.'
        }, options || {});

        var table = document.getElementById(tableId);
        var tbody = table ? table.querySelector('tbody') : null;
        if (!table || !tbody) {
            return null;
        }

        var rows = Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
            return row.cells.length > 1;
        });
        var searchInput = settings.searchInputId ? document.getElementById(settings.searchInputId) : null;
        var statusFilter = settings.statusFilterId ? document.getElementById(settings.statusFilterId) : null;
        var prevButton = settings.prevButtonId ? document.getElementById(settings.prevButtonId) : null;
        var nextButton = settings.nextButtonId ? document.getElementById(settings.nextButtonId) : null;
        var pageInfo = settings.pageInfoId ? document.getElementById(settings.pageInfoId) : null;
        var perPageSelect = settings.perPageSelectId ? document.getElementById(settings.perPageSelectId) : null;
        var recordCount = settings.recordCountId ? document.getElementById(settings.recordCountId) : null;
        var currentPage = 1;
        var perPage = parseInt(perPageSelect && perPageSelect.value, 10) || 25;
        var emptyRow = null;
        var toolbar = searchInput
            ? searchInput.closest('.master-data-toolbar, .workspace-filter-panel, .d-flex.flex-wrap.gap-2.align-items-center.mb-3')
            : null;
        var resetButton = null;

        [statusFilter, perPageSelect].forEach(function (select) {
            if (!select) {
                return;
            }
            select.setAttribute('data-no-select2', 'true');
            if (window.jQuery && jQuery.fn.select2) {
                var $select = jQuery(select);
                if ($select.hasClass('select2-hidden-accessible')) {
                    $select.select2('destroy');
                    select.removeAttribute('data-select2-initialized');
                }
            }
        });

        (function movePaginationControlsAboveTable() {
            var wrapper = table.closest('.table-responsive') || table.parentElement;
            var controlContainer = null;
            if (pageInfo && pageInfo.parentElement) {
                controlContainer = pageInfo.parentElement;
            } else if (perPageSelect && perPageSelect.parentElement) {
                controlContainer = perPageSelect.parentElement;
            }

            if (!wrapper || !controlContainer || !wrapper.parentNode) {
                return;
            }

            controlContainer.classList.add('master-data-pagination-inline');

            if (toolbar && toolbar.parentNode) {
                if (controlContainer === toolbar.nextElementSibling) {
                    return;
                }
                if (toolbar.nextSibling) {
                    toolbar.parentNode.insertBefore(controlContainer, toolbar.nextSibling);
                } else {
                    toolbar.parentNode.appendChild(controlContainer);
                }
                return;
            }

            if (controlContainer === wrapper.previousElementSibling) {
                return;
            }

            wrapper.parentNode.insertBefore(controlContainer, wrapper);
        })();

        table.setAttribute('data-no-table-search', 'true');
        table.setAttribute('data-table-search-initialized', 'true');
        table.setAttribute('data-managed-datatable', 'true');

        function ensureResetButton() {
            if (!toolbar || resetButton) {
                return;
            }

            var actionHost = toolbar.querySelector('.master-data-toolbar-actions');
            if (!actionHost) {
                actionHost = document.createElement('div');
                actionHost.className = 'master-data-toolbar-actions';
                toolbar.appendChild(actionHost);
            }

            resetButton = document.createElement('button');
            resetButton.type = 'button';
            resetButton.className = 'btn btn-sm btn-outline-secondary master-data-reset-btn';
            resetButton.textContent = 'Reset Filters';
            resetButton.addEventListener('click', function () {
                if (searchInput) {
                    searchInput.value = '';
                }
                if (statusFilter) {
                    statusFilter.value = '';
                }
                resetToFirstPage();
            });
            actionHost.appendChild(resetButton);
        }

        function syncToolbarState() {
            var hasSearch = !!(((searchInput && searchInput.value) || '').trim());
            var hasStatus = !!(((statusFilter && statusFilter.value) || '').trim());
            var hasActiveFilters = hasSearch || hasStatus;

            if (toolbar) {
                toolbar.classList.toggle('master-data-toolbar-active', hasActiveFilters);
            }
            if (searchInput) {
                searchInput.classList.toggle('master-data-search-active', hasSearch);
            }
            if (statusFilter) {
                statusFilter.classList.toggle('master-data-filter-active', hasStatus);
            }
            if (resetButton) {
                resetButton.classList.toggle('d-none', !hasActiveFilters);
            }
        }

        function getVisibleRows() {
            var term = ((searchInput && searchInput.value) || '').toLowerCase().trim();
            var status = ((statusFilter && statusFilter.value) || '').trim();

            return rows.filter(function (row) {
                if (typeof settings.rowMatcher === 'function') {
                    return settings.rowMatcher(row, {
                        term: term,
                        status: status
                    });
                }

                var matchesTerm = !term || row.textContent.toLowerCase().indexOf(term) !== -1;
                var matchesStatus = !status || row.getAttribute('data-status') === status;
                return matchesTerm && matchesStatus;
            });
        }

        function updateRecordCount(totalVisible, totalOverall) {
            if (!recordCount) {
                return;
            }
            if (typeof settings.recordCountFormatter === 'function') {
                recordCount.textContent = settings.recordCountFormatter(totalVisible, totalOverall);
                return;
            }
            recordCount.textContent = 'Showing ' + totalVisible + ' of ' + totalOverall + ' records';
        }

        function ensureEmptyRow() {
            if (emptyRow) {
                return emptyRow;
            }

            emptyRow = document.createElement('tr');
            emptyRow.className = 'master-data-empty-row';

            var cell = document.createElement('td');
            cell.colSpan = table.querySelectorAll('thead th').length || 1;
            cell.className = 'text-center text-muted py-4';
            cell.textContent = settings.emptyMessage;
            emptyRow.appendChild(cell);

            return emptyRow;
        }

        function renderRows() {
            var visibleRows = getVisibleRows();
            var totalVisible = visibleRows.length;
            var totalPages = Math.max(1, Math.ceil(totalVisible / perPage));

            currentPage = Math.min(currentPage, totalPages);

            rows.forEach(function (row) {
                row.style.display = 'none';
            });

            if (totalVisible > 0) {
                if (emptyRow && emptyRow.parentNode) {
                    emptyRow.parentNode.removeChild(emptyRow);
                }
                var start = (currentPage - 1) * perPage;
                var end = Math.min(start + perPage, totalVisible);
                visibleRows.slice(start, end).forEach(function (row) {
                    row.style.display = '';
                });
            } else {
                tbody.appendChild(ensureEmptyRow());
            }

            updateRecordCount(totalVisible, rows.length);

            if (pageInfo) {
                if (typeof settings.pageInfoFormatter === 'function') {
                    pageInfo.textContent = settings.pageInfoFormatter({
                        currentPage: currentPage,
                        totalPages: totalPages,
                        totalVisible: totalVisible,
                        totalOverall: rows.length
                    });
                } else {
                    pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages;
                }
            }
            if (prevButton) {
                prevButton.disabled = currentPage <= 1;
            }
            if (nextButton) {
                nextButton.disabled = currentPage >= totalPages;
            }
        }

        function resetToFirstPage() {
            currentPage = 1;
            renderRows();
            syncToolbarState();
        }

        ensureResetButton();
        syncToolbarState();

        if (searchInput) {
            searchInput.addEventListener('input', resetToFirstPage);
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && searchInput.value !== '') {
                    event.preventDefault();
                    searchInput.value = '';
                    resetToFirstPage();
                }
            });
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', resetToFirstPage);
        }
        if (perPageSelect) {
            perPageSelect.addEventListener('change', function () {
                perPage = parseInt(perPageSelect.value, 10) || 25;
                resetToFirstPage();
            });
        }
        if (prevButton) {
            prevButton.addEventListener('click', function () {
                if (currentPage > 1) {
                    currentPage -= 1;
                    renderRows();
                }
            });
        }
        if (nextButton) {
            nextButton.addEventListener('click', function () {
                var visibleCount = getVisibleRows().length;
                var totalPages = Math.max(1, Math.ceil(visibleCount / perPage));
                if (currentPage < totalPages) {
                    currentPage += 1;
                    renderRows();
                }
            });
        }

        renderRows();

        return {
            refresh: renderRows
        };
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

    function repairOrphanedMasterDataForms() {
        document.querySelectorAll('.master-data-editor').forEach(function (editor) {
            if (editor.querySelector('form')) {
                return;
            }

            var actionInput = editor.querySelector('input[name="action"][value="save"]');
            if (!actionInput) {
                return;
            }

            var form = document.createElement('form');
            form.method = 'post';
            while (editor.firstChild) {
                form.appendChild(editor.firstChild);
            }
            editor.appendChild(form);
        });

        document.querySelectorAll('.modal-content').forEach(function (modalContent) {
            if (modalContent.querySelector('form') || !modalContent.querySelector('input[name="action"]')) {
                return;
            }

            var form = document.createElement('form');
            form.method = 'post';
            while (modalContent.firstChild) {
                form.appendChild(modalContent.firstChild);
            }
            modalContent.appendChild(form);
        });
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
                    return;
                }

                if (form.getAttribute('data-submit-loading') !== '1') {
                    return;
                }

                var submitter = event.submitter || null;
                var submitButtons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));

                submitButtons.forEach(function (button) {
                    if (button.disabled) {
                        return;
                    }

                    button.disabled = true;

                    if (!submitter || button === submitter) {
                        if (button.tagName === 'BUTTON') {
                            if (!button.hasAttribute('data-submit-original-html')) {
                                button.setAttribute('data-submit-original-html', button.innerHTML);
                            }
                            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';
                        } else {
                            if (!button.hasAttribute('data-submit-original-value')) {
                                button.setAttribute('data-submit-original-value', button.value);
                            }
                            button.value = 'Saving...';
                        }
                    }
                });
            });

            form.setAttribute('data-form-validation-initialized', 'true');
        });
    }

    function setupRequiredSummaryValidation(options) {
        var config = options || {};
        var form = config.form || (config.formId ? document.getElementById(config.formId) : null);
        var summary = config.summary || (config.summaryId ? document.getElementById(config.summaryId) : null);
        var requiredFields = Array.isArray(config.requiredFields) ? config.requiredFields : [];
        var showAttribute = config.showAttribute || 'data-show-required-summary';
        var summaryPrefix = config.summaryPrefix || 'Please complete required fields: ';

        if (!form || !summary || !requiredFields.length) {
            return null;
        }

        function getFieldState(fieldConfig) {
            var field = fieldConfig.field || (fieldConfig.id ? document.getElementById(fieldConfig.id) : null);
            if (!field) {
                return null;
            }

            var requiredWhenResult = true;
            if (typeof fieldConfig.requiredWhen === 'function') {
                requiredWhenResult = !!fieldConfig.requiredWhen(field, form, fieldConfig);
            }

            var isMissing = false;
            if (requiredWhenResult) {
                if (typeof fieldConfig.isMissing === 'function') {
                    isMissing = !!fieldConfig.isMissing(field, form, fieldConfig);
                } else if (field.type === 'checkbox' || field.type === 'radio') {
                    isMissing = !field.checked;
                } else {
                    isMissing = String(field.value || '').trim() === '';
                }
            }

            return {
                field: field,
                label: fieldConfig.label || field.getAttribute('name') || 'Field',
                feedback: fieldConfig.feedbackId ? document.getElementById(fieldConfig.feedbackId) : null,
                useSelect2: fieldConfig.useSelect2 !== false,
                isMissing: isMissing
            };
        }

        function render(showSummary) {
            var show = !!showSummary;
            var states = requiredFields.map(getFieldState).filter(Boolean);
            var missingStates = states.filter(function (state) {
                return state.isMissing;
            });

            states.forEach(function (state) {
                var showFieldInvalid = show && state.isMissing;
                state.field.classList.toggle('is-invalid', showFieldInvalid);
                if (state.feedback) {
                    state.feedback.classList.toggle('d-none', !showFieldInvalid);
                }
                if (state.useSelect2) {
                    syncSelect2ValidationState(state.field);
                }
            });

            if (!show || !missingStates.length) {
                summary.classList.add('d-none');
                summary.textContent = '';
            } else {
                summary.textContent = summaryPrefix + missingStates.map(function (state) {
                    return state.label;
                }).join(', ') + '.';
                summary.classList.remove('d-none');
            }

            return missingStates;
        }

        requiredFields.forEach(function (fieldConfig) {
            var field = fieldConfig.field || (fieldConfig.id ? document.getElementById(fieldConfig.id) : null);
            if (!field) {
                return;
            }

            var events = Array.isArray(fieldConfig.events) && fieldConfig.events.length
                ? fieldConfig.events
                : ['change'];

            events.forEach(function (eventName) {
                field.addEventListener(eventName, function () {
                    render(form.getAttribute(showAttribute) === '1');
                });
            });

            if (fieldConfig.useSelect2 !== false && window.jQuery) {
                window.jQuery(field).on('select2:select select2:clear', function () {
                    render(form.getAttribute(showAttribute) === '1');
                });
            }
        });

        form.addEventListener('submit', function (event) {
            if (typeof config.beforeValidate === 'function') {
                config.beforeValidate(form, event);
            }

            form.setAttribute(showAttribute, '1');
            var missingStates = render(true);
            if (!missingStates.length) {
                if (typeof config.onValid === 'function') {
                    config.onValid(form, event);
                }
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            var firstMissing = missingStates[0] && missingStates[0].field ? missingStates[0].field : null;
            if (firstMissing) {
                if (window.jQuery && jQuery(firstMissing).hasClass('select2-hidden-accessible')) {
                    jQuery(firstMissing).select2('open');
                } else {
                    firstMissing.focus();
                }
            }

            if (typeof config.onInvalid === 'function') {
                config.onInvalid(missingStates, form, event);
            }
        });

        render(false);

        return {
            render: render
        };
    }

    function initGlobalSortableHeaders(root) {
        var context = root || document;
        context.querySelectorAll('th[data-sort]').forEach(function (th) {
            if (th.getAttribute('data-sort-initialized')) { return; }
            th.setAttribute('data-sort-initialized', '1');
            th.style.cursor = 'pointer';
            var table = th.closest('table');
            if (!table) { return; }
            th.addEventListener('click', function () {
                var tbody = table.querySelector('tbody');
                if (!tbody) { return; }
                var idx = Array.from(th.parentElement.children).indexOf(th);
                var currentDir = th.getAttribute('data-sort-dir') || '';
                var dir = currentDir === 'asc' ? 'desc' : 'asc';
                table.querySelectorAll('th[data-sort]').forEach(function (t) {
                    t.removeAttribute('data-sort-dir');
                    var i = t.querySelector('i.bi');
                    if (i) { i.className = 'bi bi-arrow-down-up text-muted small'; }
                });
                th.setAttribute('data-sort-dir', dir);
                var icon = th.querySelector('i.bi');
                if (icon) { icon.className = 'bi bi-arrow-' + (dir === 'asc' ? 'up' : 'down') + ' text-primary small'; }
                var rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function (a, b) {
                    var at = (a.cells[idx] ? a.cells[idx].textContent : '').trim().toLowerCase();
                    var bt = (b.cells[idx] ? b.cells[idx].textContent : '').trim().toLowerCase();
                    return dir === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at);
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    }

    window.SPAMS = window.SPAMS || {};
    window.SPAMS.initSelect2 = initSelect2;
    window.SPAMS.refreshSelect2 = refreshSelect2;
    window.SPAMS.initTableSearch = initTableSearch;
    window.SPAMS.initDataTable = initDataTable;
    window.SPAMS.initMasterDataList = initMasterDataList;
    window.SPAMS.initFormValidation = initFormValidation;
    window.SPAMS.initGlobalSortableHeaders = initGlobalSortableHeaders;
    window.SPAMS.markFieldValidationState = markFieldValidationState;
    window.SPAMS.setupRequiredSummaryValidation = setupRequiredSummaryValidation;
    window.initDataTable = initDataTable;
    window.initMasterDataList = initMasterDataList;

    if (Array.isArray(window.__spamsPendingInitDataTables) && window.__spamsPendingInitDataTables.length > 0) {
        window.__spamsPendingInitDataTables.forEach(function (args) {
            try {
                initDataTable.apply(window, args || []);
            } catch (error) {
                console.error('Queued initDataTable failed', error);
            }
        });
        window.__spamsPendingInitDataTables = [];
    }

    if (Array.isArray(window.__spamsPendingMasterDataLists) && window.__spamsPendingMasterDataLists.length > 0) {
        window.__spamsPendingMasterDataLists.forEach(function (args) {
            try {
                initMasterDataList.apply(window, args || []);
            } catch (error) {
                console.error('Queued initMasterDataList failed', error);
            }
        });
        window.__spamsPendingMasterDataLists = [];
    }

    repairOrphanedMasterDataForms();
    initSelect2(document);
    initMobileTableFrames(document);
    initTableSearch(document);
    initFormValidation(document);
    initGlobalSortableHeaders(document);

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
