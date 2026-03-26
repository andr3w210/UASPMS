<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

// Default page title; will be updated after $form is populated below.
$page_title = 'Edit Purchase Order';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3">Edit Purchase Order</h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>

                <form id="purchaseOrderForm" method="post" action="<?php echo base_url('modules/purchase_orders/edit.php?id=' . $id); ?>">
                    <input type="hidden" name="action" value="update">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="po_number" class="form-label">Hard Copy PO Number</label>
                            <input type="text" class="form-control" id="po_number" name="po_number" value="<?php echo h($form['po_number']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="po_date" class="form-label">PO Date</label>
                            <input type="date" class="form-control" id="po_date" name="po_date" value="<?php echo h($form['po_date']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="supplier_id" class="form-label">Supplier</label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo (int) $supplier['id']; ?>" data-address="<?php echo h($supplier['address'] ?? ''); ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>><?php echo h($supplier['supplier_name'] . ' (' . $supplier['supplier_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="fund_id" class="form-label">Fund</label>
                            <select class="form-select" id="fund_id" name="fund_id" required>
                                <option value="">Select fund</option>
                                <?php foreach ($funds as $fund): ?>
                                    <option value="<?php echo (int) $fund['id']; ?>" <?php echo $form['fund_id'] === (string) $fund['id'] ? 'selected' : ''; ?>><?php echo h($fund['fund_code'] . ' - ' . $fund['fund_name'] . ($fund['fund_source'] !== null && $fund['fund_source'] !== '' ? ' - ' . $fund['fund_source'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="supplier_address" class="form-label">Supplier Address</label>
                            <input type="text" class="form-control" id="supplier_address" name="supplier_address" value="<?php echo h($form['supplier_address']); ?>">
                        </div>

                        <div class="col-md-4">
                            <label for="mode_of_procurement" class="form-label">Mode of Procurement</label>
                            <select class="form-select" id="mode_of_procurement" name="mode_of_procurement_id">
                                <option value="">Select mode</option>
                                <?php foreach ($procurementModes as $procurementMode): ?>
                                    <option value="<?php echo (int) $procurementMode['id']; ?>" <?php echo $form['mode_of_procurement_id'] === (string) $procurementMode['id'] ? 'selected' : ''; ?>><?php echo h($procurementMode['mode_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="place_of_delivery" class="form-label">Place of Delivery</label>
                            <input type="text" class="form-control" id="place_of_delivery" name="place_of_delivery" value="<?php echo h($form['place_of_delivery']); ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="delivery_term_days" class="form-label">Delivery Term (Days)</label>
                            <input type="number" class="form-control" id="delivery_term_days" name="delivery_term_days" min="0" step="1" value="<?php echo h($form['delivery_term_days']); ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="expected_delivery_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="expected_delivery_date" name="expected_delivery_date" value="<?php echo h($form['expected_delivery_date']); ?>" readonly>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                        <h6 class="mb-0">PO Items</h6>
                        <div class="dropdown">
                            <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Add Item</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button class="dropdown-item add-line-btn" type="button" data-type="supply">Supply</button></li>
                                <li><button class="dropdown-item add-line-btn" type="button" data-type="semi_expendable">Semi-Expendable</button></li>
                                <li><button class="dropdown-item add-line-btn" type="button" data-type="equipment">Equipment</button></li>
                            </ul>
                        </div>
                    </div>

                    <div class="row g-3 mb-4" id="poSplitPanel">
                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body p-3 d-flex flex-column" style="gap:10px;">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <div class="input-group input-group-sm" style="max-width:160px;">
                                            <input type="text" class="form-control form-control-sm" id="lineSearchInput" placeholder="Search lines...">
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn active" data-filter="all" type="button">All</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="done" type="button">Done</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="empty" type="button">Empty</button>
                                    </div>

                                    <div id="poLineListScroll" style="flex:1; overflow-y:auto; max-height:380px; display:flex; flex-direction:column; gap:2px;"></div>

                                    <div style="border-top:0.5px solid var(--bs-border-color); padding-top:8px; font-size:12px;">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted">Completed</span>
                                            <span id="lineCompletedCount" class="text-success fw-semibold">0 / 0</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Total so far</span>
                                            <span id="lineTotalSoFar" class="fw-semibold">0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="card h-100">
                                <div class="card-body p-3" id="poLineEditor">
                                    <div id="poEditorEmpty" class="text-center text-muted py-5">
                                        <div class="mb-2">No lines yet.</div>
                                        <div class="small">Use "Add Item" to add your first PO line.</div>
                                    </div>
                                    <div id="poEditorContent" style="display:none;">
                                        <div class="d-flex align-items-center gap-2 mb-3">
                                            <span class="fw-semibold" id="editorLineLabel">Line 1</span>
                                            <span class="badge" id="editorTypeBadge">Supply</span>
                                            <div class="flex-fill"></div>
                                            <span class="small text-muted" id="editorLineCounter">1 of 1</span>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Account Code <span class="text-danger">*</span></label>
                                            <select class="form-select form-select-sm" id="editorAccountCode" name="_editor_account_code" style="font-size:13px;"></select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Inventory Class <span class="text-muted" style="font-size:10px;">(optional)</span></label>
                                            <select class="form-select form-select-sm" id="editorClassification" name="_editor_classification" style="font-size:13px;"></select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Description <span class="text-danger">*</span></label>
                                            <textarea class="form-control form-control-sm" id="editorDescription" rows="3" placeholder="Item description from hard copy PO" style="font-size:13px; border-left:3px solid var(--bs-primary-border-subtle); border-radius:0 4px 4px 0;"></textarea>
                                        </div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-3">
                                                <label class="form-label" style="font-size:11px;">Quantity <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control form-control-sm text-center" id="editorQty" min="0.01" step="0.01" value="1" style="font-size:13px;">
                                            </div>
                                            <div class="col-5">
                                                <label class="form-label" style="font-size:11px;">Unit</label>
                                                <select class="form-select form-select-sm" id="editorUom" style="font-size:13px;"></select>
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label" style="font-size:11px;">Unit Cost</label>
                                                <input type="number" class="form-control form-control-sm text-end" id="editorUnitCost" min="0" step="0.01" value="0.00" style="font-size:13px;">
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end align-items-baseline gap-2 mb-3">
                                            <span class="text-muted small">Amount:</span>
                                            <span id="editorAmount" class="fw-semibold" style="font-size:16px;">0.00</span>
                                        </div>

                                        <div style="border-top:0.5px solid var(--bs-border-color); padding-top:10px;">
                                            <div class="progress mb-2" style="height:4px;">
                                                <div class="progress-bar" id="editorProgress" style="width:0%; transition:width .3s;"></div>
                                            </div>
                                            <div class="d-flex gap-2 align-items-center">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="editorPrev">← Prev</button>
                                                <div class="flex-fill text-center small text-muted" id="editorProgressLabel">0 / 0 completed</div>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="editorNext">Next →</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" id="editorDeleteLine">Remove</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="poHiddenInputs"></div>

                    <div style="position:sticky; bottom:0; z-index:10; background:var(--bs-body-bg); border-top:0.5px solid var(--bs-border-color); padding:10px 0; margin-top:4px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small" id="footerLineCount">0 line(s)</span>
                            <div>
                                <a href="<?php echo base_url('modules/purchase_orders/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                                <button type="submit" class="btn btn-primary btn-sm">Update Purchase Order</button>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function formatNumber(n) { return parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    var accountCodes = <?php echo json_encode($accountCodes, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var classifications = <?php echo json_encode($classifications, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var units = <?php echo json_encode($unitOfMeasures, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];

    var poLinesFromPhp = <?php echo json_encode(array_values($itemRows), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    <?php
    // If the PO was loaded and $form populated server-side, update the page title
    if (!empty($form['system_reference'])) {
        $page_title = 'Edit Purchase Order — ' . h($form['system_reference']);
    }
    ?>
    var poLines = [];
    var activeIndex = -1;
    var currentFilter = 'all';
    var searchTerm = '';

    var el = {
        lineListScroll: document.getElementById('poLineListScroll'),
        lineSearchInput: document.getElementById('lineSearchInput'),
        editorEmpty: document.getElementById('poEditorEmpty'),
        editorContent: document.getElementById('poEditorContent'),
        editorLineLabel: document.getElementById('editorLineLabel'),
        editorTypeBadge: document.getElementById('editorTypeBadge'),
        editorLineCounter: document.getElementById('editorLineCounter'),
        editorAccountCode: document.getElementById('editorAccountCode'),
        editorClassification: document.getElementById('editorClassification'),
        editorDescription: document.getElementById('editorDescription'),
        editorQty: document.getElementById('editorQty'),
        editorUom: document.getElementById('editorUom'),
        editorUnitCost: document.getElementById('editorUnitCost'),
        editorAmount: document.getElementById('editorAmount'),
        editorProgress: document.getElementById('editorProgress'),
        editorProgressLabel: document.getElementById('editorProgressLabel'),
        editorPrev: document.getElementById('editorPrev'),
        editorNext: document.getElementById('editorNext'),
        editorDeleteLine: document.getElementById('editorDeleteLine'),
        lineCompletedCount: document.getElementById('lineCompletedCount'),
        lineTotalSoFar: document.getElementById('lineTotalSoFar'),
        footerLineCount: document.getElementById('footerLineCount'),
        poGrandTotal: document.getElementById('poGrandTotal'),
        poHiddenInputs: document.getElementById('poHiddenInputs')
    };

    function lineIsComplete(line) { return (line.account_code_id || '') !== '' && (String(line.item_description || '').trim() !== '') && (parseFloat(line.quantity || 0) > 0); }
    function typeBadgeClass(t) { if (t === 'equipment') return 'bg-warning text-dark'; if (t === 'semi_expendable') return 'bg-primary'; if (t === 'supply') return 'bg-success'; return 'bg-secondary'; }
    function typeLabel(t) { if (t === 'equipment') return 'Equipment'; if (t === 'semi_expendable') return 'Semi-Expendable'; return 'Supply'; }
    function typeShortLabel(t) { if (t === 'equipment') return 'Equip'; if (t === 'semi_expendable') return 'Semi'; return 'Supply'; }

    function rebuildAccountCodeSelect(itemType, selectedId) {
        var sel = el.editorAccountCode; if (!sel) return; sel.innerHTML = '<option value="">Select account code</option>';
        accountCodes.forEach(function(ac){ var mapped = (ac.account_group === 'asset') ? 'equipment' : ac.account_group; if (mapped !== itemType) return; var opt = document.createElement('option'); opt.value = ac.id; opt.textContent = ac.account_code + ' - ' + ac.account_name; if (String(ac.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); });
        if (window.jQuery && jQuery.fn.select2) { var $sel = window.jQuery(sel); if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy'); $sel.select2({ placeholder: 'Select account code', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) }); }
    }

    function rebuildClassificationSelect(itemType, selectedId) { var sel = el.editorClassification; if (!sel) return; sel.innerHTML = '<option value="">Select inventory class</option>'; classifications.forEach(function(cl){ var mapped = (cl.classification_group === 'asset') ? 'equipment' : cl.classification_group; if (mapped !== itemType) return; var opt = document.createElement('option'); opt.value = cl.id; opt.textContent = cl.classification_name; if (String(cl.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); }); if (window.jQuery && jQuery.fn.select2) { var $sel = window.jQuery(sel); if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy'); $sel.select2({ placeholder: 'Select inventory class', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) }); } }

    function rebuildUomSelect(selectedId) { var sel = el.editorUom; if (!sel) return; sel.innerHTML = '<option value="">Select unit</option>'; units.forEach(function(u){ var opt = document.createElement('option'); opt.value = u.id; opt.textContent = u.uom_name + ' (' + u.abbreviation + ')'; if (String(u.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); }); if (window.jQuery && jQuery.fn.select2) { var $sel = window.jQuery(sel); if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy'); $sel.select2({ placeholder: 'Select unit', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) }); } }

    function escapeHtml(s) { return String(s || '').replace(/[&<>\"]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];}); }

    function renderLineList() { var container = el.lineListScroll; if (!container) return; container.innerHTML = ''; var done = 0; var total = poLines.length; var sum = 0; for (var i = 0; i < poLines.length; i++) { var ln = poLines[i]; ln.is_complete = lineIsComplete(ln); if (ln.is_complete) done++; sum += parseFloat(ln.line_total || 0); if (currentFilter === 'done' && !ln.is_complete) continue; if (currentFilter === 'empty' && ln.is_complete) continue; if (searchTerm && !(ln.item_description || '').toLowerCase().includes(searchTerm)) continue; var dotColor = (i === activeIndex) ? '#0d6efd' : (ln.is_complete ? '#198754' : '#adb5bd'); var badgeClass = (ln.item_type === 'equipment') ? 'text-bg-warning-subtle' : (ln.item_type === 'semi_expendable' ? 'text-bg-primary-subtle' : 'text-bg-success-subtle'); var shortType = typeShortLabel(ln.item_type); var desc = (ln.item_description || 'New item'); var amt = (parseFloat(ln.line_total || 0) !== 0) ? formatNumber(ln.line_total) : '—'; var row = document.createElement('div'); row.className = 'po-line-list-item'; row.setAttribute('data-index', i); row.style.cssText = 'display:flex; align-items:center; gap:6px; padding:6px 8px; border-radius:6px; cursor:pointer; font-size:12px; border:0.5px solid transparent;'; row.innerHTML = '<span style="width:20px; text-align:center; color:var(--bs-body-color); opacity:0.5; font-size:11px;">' + (i+1) + '</span>' + '<span class="po-line-status-dot" style="width:8px; height:8px; border-radius:50%; flex-shrink:0; background:' + dotColor + ';"></span>' + '<span class="badge ' + badgeClass + '" style="font-size:9px; padding:1px 5px; flex-shrink:0;">' + shortType + '</span>' + '<span style="flex:1; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; margin-left:6px; color:var(--bs-body-color);">' + escapeHtml(desc) + '</span>' + '<span style="font-size:11px; color:var(--bs-body-color); opacity:0.65; flex-shrink:0; margin-left:8px;">' + amt + '</span>'; (function(index){ row.addEventListener('click', function(){ loadLineEditor(index); }); })(i); if (i === activeIndex) { row.style.background = 'var(--bs-primary-bg-subtle)'; row.style.borderColor = 'var(--bs-primary-border-subtle)'; } container.appendChild(row); } el.lineCompletedCount && (el.lineCompletedCount.textContent = done + ' / ' + total); el.lineTotalSoFar && (el.lineTotalSoFar.textContent = formatNumber(sum)); el.footerLineCount && (el.footerLineCount.textContent = total + ' line(s)'); el.poGrandTotal && (el.poGrandTotal.textContent = formatNumber(sum)); }

    function loadLineEditor(index) { if (poLines.length === 0) { el.editorEmpty.style.display = ''; el.editorContent.style.display = 'none'; activeIndex = -1; renderLineList(); return; } activeIndex = index; var line = poLines[index]; el.editorEmpty.style.display = 'none'; el.editorContent.style.display = ''; el.editorLineLabel.textContent = 'Line ' + (index + 1); el.editorTypeBadge.className = 'badge ' + typeBadgeClass(line.item_type); el.editorTypeBadge.textContent = typeLabel(line.item_type); el.editorLineCounter.textContent = (index + 1) + ' of ' + poLines.length; rebuildAccountCodeSelect(line.item_type, line.account_code_id); rebuildClassificationSelect(line.item_type, line.classification_id); rebuildUomSelect(line.unit_of_measure_id); el.editorDescription.value = line.item_description || ''; el.editorQty.value = line.quantity || '1'; el.editorUnitCost.value = line.unit_cost || '0.00'; el.editorAmount.textContent = formatNumber(line.line_total || 0); el.editorPrev.disabled = (index === 0); el.editorNext.disabled = (index === poLines.length - 1); var done = poLines.filter(lineIsComplete).length; var pct = poLines.length ? Math.round((done / poLines.length) * 100) : 0; el.editorProgress.style.width = pct + '%'; el.editorProgressLabel.textContent = done + ' / ' + poLines.length + ' completed'; renderLineList(); if (window.SPAMS && typeof window.SPAMS.initSelect2 === 'function') window.SPAMS.initSelect2(document.getElementById('poLineEditor')); if (window.SPAMS && typeof window.SPAMS.refreshSelect2 === 'function') { window.SPAMS.refreshSelect2(document.getElementById('editorAccountCode')); window.SPAMS.refreshSelect2(document.getElementById('editorClassification')); window.SPAMS.refreshSelect2(document.getElementById('editorUom')); }
    }

    function saveCurrentLine() { if (activeIndex < 0 || activeIndex >= poLines.length) return; var ln = poLines[activeIndex]; ln.account_code_id = el.editorAccountCode.value || ''; var currentClassOpt = el.editorClassification ? el.editorClassification.options[el.editorClassification.selectedIndex] : null; var currentClassType = currentClassOpt ? currentClassOpt.getAttribute('data-item-type') : ''; if (currentClassType && currentClassType !== ln.item_type) { el.editorClassification.value = ''; } ln.classification_id = el.editorClassification ? (el.editorClassification.value || '') : ''; ln.item_description = (el.editorDescription.value || '').trim(); ln.quantity = el.editorQty.value || '0'; ln.unit_of_measure_id = el.editorUom.value || ''; ln.unit_cost = el.editorUnitCost.value || '0'; ln.line_total = Math.round((parseFloat(ln.quantity || 0) * parseFloat(ln.unit_cost || 0)) * 100) / 100; ln.is_complete = lineIsComplete(ln); el.editorAmount.textContent = formatNumber(ln.line_total || 0); renderLineList(); updateGrandTotal(); }

    function updateEditorAmount() { var q = parseFloat(el.editorQty.value || 0) || 0; var c = parseFloat(el.editorUnitCost.value || 0) || 0; el.editorAmount.textContent = formatNumber(Math.round(q * c * 100) / 100); }

    function deleteLine(idx) { if (poLines.length <= 1) { alert('At least one line is required.'); return; } poLines.splice(idx,1); poLines.forEach(function(l,i){ l.index = i; }); var nextIndex = Math.min(idx, poLines.length-1); renderLineList(); loadLineEditor(nextIndex); }

    function buildHiddenInputs() { var container = el.poHiddenInputs; if (!container) return; container.innerHTML = ''; poLines.forEach(function(ln,i){ var fields = { item_type: ln.item_type, account_code_id: ln.account_code_id, classification_id: ln.classification_id, item_description: ln.item_description, quantity: ln.quantity, unit_of_measure_id: ln.unit_of_measure_id, unit_cost: ln.unit_cost }; Object.keys(fields).forEach(function(k){ var inp = document.createElement('input'); inp.type='hidden'; inp.name='items['+i+']['+k+']'; inp.value = fields[k] || ''; container.appendChild(inp); }); }); }

    function addLine(itemType) { var validTypes = ['supply', 'semi_expendable', 'equipment']; if (validTypes.indexOf(itemType) === -1) itemType = 'supply'; poLines.push({ index: poLines.length, item_type: itemType, account_code_id: '', classification_id: '', item_description: '', quantity: '1', unit_of_measure_id: '', unit_cost: '0.00', line_total: 0, is_complete: false }); renderLineList(); loadLineEditor(poLines.length - 1); }

    function updateGrandTotal() { var total = poLines.reduce(function(acc,ln){ return acc + (parseFloat(ln.line_total||0)); },0); el.poGrandTotal && (el.poGrandTotal.textContent = formatNumber(total)); el.lineTotalSoFar && (el.lineTotalSoFar.textContent = formatNumber(total)); }

    Array.from(document.querySelectorAll('.add-line-btn')).forEach(function(b){ b.addEventListener('click', function(){ addLine(b.dataset.type || 'supply'); }); });
    el.lineSearchInput && el.lineSearchInput.addEventListener('input', function(){ searchTerm = (this.value||'').trim().toLowerCase(); renderLineList(); });
    Array.from(document.querySelectorAll('.po-filter-btn')).forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('.po-filter-btn').forEach(function(bb){ bb.classList.remove('active'); }); b.classList.add('active'); currentFilter = b.dataset.filter || 'all'; renderLineList(); }); });

    el.editorPrev && el.editorPrev.addEventListener('click', function(){ saveCurrentLine(); if (activeIndex>0) loadLineEditor(activeIndex-1); });
    el.editorNext && el.editorNext.addEventListener('click', function(){ saveCurrentLine(); if (activeIndex < poLines.length-1) loadLineEditor(activeIndex+1); });
    el.editorDeleteLine && el.editorDeleteLine.addEventListener('click', function(){ if (activeIndex>=0) deleteLine(activeIndex); });

    ['editorAccountCode','editorClassification','editorDescription','editorQty','editorUom','editorUnitCost'].forEach(function(id){ var node = document.getElementById(id); if (!node) return; node.addEventListener('change', saveCurrentLine); node.addEventListener('input', function(){ if (id==='editorQty' || id==='editorUnitCost') updateEditorAmount(); saveCurrentLine(); }); });

    if (window.jQuery) { window.jQuery(document).on('select2:select select2:clear','#editorAccountCode, #editorClassification, #editorUom', function() { updateEditorAmount(); saveCurrentLine(); }); }

    var form = document.getElementById('purchaseOrderForm');
    if (form) { form.addEventListener('submit', function(e) { saveCurrentLine(); buildHiddenInputs(); if (poLines.length === 0) { e.preventDefault(); alert('Please add at least one PO line before saving.'); return; } var emptyLines = poLines.filter(function(ln) { return !ln.item_description || ln.item_description.trim() === ''; }); if (emptyLines.length > 0) { e.preventDefault(); alert('Line ' + (emptyLines[0].index + 1) + ' has no description. Please fill in all lines before saving.'); loadLineEditor(emptyLines[0].index); return; } }); }

    if (typeof poLinesFromPhp !== 'undefined' && poLinesFromPhp.length > 0) { poLines = poLinesFromPhp.slice(); renderLineList(); loadLineEditor(0); } else if (poLines.length === 0) { addLine('supply'); }

    if (window.SPAMS && window.SPAMS.initSelect2) { window.SPAMS.initSelect2(document.getElementById('poLineEditor')); }

    var poDateInput = document.getElementById('po_date');
    var deliveryTermInput = document.getElementById('delivery_term_days');
    var expectedDeliveryInput = document.getElementById('expected_delivery_date');
    function computeExpectedDate() { if (!expectedDeliveryInput) return; var pdVal = poDateInput && poDateInput.value ? poDateInput.value : ''; var days = parseInt(deliveryTermInput && deliveryTermInput.value, 10); days = isNaN(days) ? 0 : days; if (!pdVal) { expectedDeliveryInput.value = ''; return; } var parts = pdVal.split('-'); if (parts.length !== 3) { expectedDeliveryInput.value = ''; return; } var d = new Date(parts[0], parseInt(parts[1],10) - 1, parts[2]); d.setDate(d.getDate() + days); var yyyy = d.getFullYear(); var mm = String(d.getMonth() + 1).padStart(2, '0'); var dd = String(d.getDate()).padStart(2, '0'); expectedDeliveryInput.value = yyyy + '-' + mm + '-' + dd; }
    if (poDateInput) poDateInput.addEventListener('change', computeExpectedDate); if (deliveryTermInput) deliveryTermInput.addEventListener('input', computeExpectedDate); computeExpectedDate();

    var supplierSelect = document.getElementById('supplier_id'); var supplierAddressInput = document.getElementById('supplier_address'); function syncSupplierAddress() { if (!supplierSelect || !supplierAddressInput) return; var selectedValue = supplierSelect.value; var addr = ''; Array.from(supplierSelect.options).forEach(function(opt) { if (opt.value === selectedValue) { addr = (opt.getAttribute('data-address') || '').trim(); } }); supplierAddressInput.value = addr; supplierAddressInput.placeholder = addr ? '' : 'No address on file — type manually'; }
    if (supplierSelect && supplierAddressInput) { supplierSelect.addEventListener('change', syncSupplierAddress); setTimeout(function(){ if (window.jQuery && jQuery.fn.select2) { window.jQuery(supplierSelect).off('select2:select select2:clear').on('select2:select select2:clear', syncSupplierAddress); } syncSupplierAddress(); }, 400); }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

