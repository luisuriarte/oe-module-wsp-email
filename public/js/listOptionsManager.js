/**
 * ListOptionsManager — Reusable CRUD frontend for OpenEMR list_options.
 *
 * Backend-agnostic: accepts an endpointUrl so it works from any module.
 * Uses fetch() + jQuery DOM manipulation.
 *
 * Usage:
 *   ListOptionsManager.init(listId, '#container', csrfToken, endpointUrl);
 *
 * To include a list picker step before editing:
 *   ListOptionsManager.initPicker('#container', csrfToken, endpointUrl);
 *
 * @package   OpenEMR\Modules\WspEmail
 */
const ListOptionsManager = (function () {

    let currentListId = null;
    let containerSelector = null;
    let csrfToken = null;
    let endpointUrl = null;
    let extraColumns = null;

    // ---- Public API -------------------------------------------------------

    function init(listId, container, token, url, extraCols) {
        currentListId = listId;
        containerSelector = container;
        csrfToken = token;
        endpointUrl = url;
        extraColumns = extraCols || null;
        loadOptions();
    }

    function initPicker(container, token, url) {
        containerSelector = container;
        csrfToken = token;
        endpointUrl = url;
        currentListId = null;
        extraColumns = null;
        loadLists();
    }

    // ---- List Picker ------------------------------------------------------

    function loadLists() {
        fetch(endpointUrl + '?action=get_lists')
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.success) {
                    renderListPicker(json.data);
                } else {
                    showError(json.message);
                }
            })
            .catch(function (err) { showError('Network error: ' + err.message); });
    }

    function renderListPicker(lists) {
        var container = document.querySelector(containerSelector);
        container.innerHTML = '';

        var heading = document.createElement('h5');
        heading.className = 'mb-3';
        heading.textContent = 'Select List to Manage';
        container.appendChild(heading);

        var row = document.createElement('div');
        row.className = 'row g-3';

        lists.forEach(function (list) {
            var col = document.createElement('div');
            col.className = 'col-md-4 col-sm-6';

            var card = document.createElement('div');
            card.className = 'card h-100 cursor-pointer';
            card.style.cursor = 'pointer';

            var body = document.createElement('div');
            body.className = 'card-body';

            var title = document.createElement('h6');
            title.className = 'card-title';
            title.textContent = list.display_name;

            body.appendChild(title);

            if (list.description) {
                var desc = document.createElement('p');
                desc.className = 'card-text small text-muted';
                desc.textContent = list.description;
                body.appendChild(desc);
            }

            card.appendChild(body);

            card.addEventListener('click', function () {
                currentListId = list.list_id;
                extraColumns = list.extra_columns || null;
                container.innerHTML = '';
                loadOptions();
            });

            col.appendChild(card);
            row.appendChild(col);
        });

        container.appendChild(row);
    }

    // ---- Options Table ----------------------------------------------------

    function loadOptions() {
        var url = endpointUrl + '?action=get_options&list_id=' + encodeURIComponent(currentListId);
        fetch(url)
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.success) {
                    renderTable(json.data);
                } else {
                    showError(json.message);
                }
            })
            .catch(function (err) { showError('Network error: ' + err.message); });
    }

    function renderTable(options) {
        var container = document.querySelector(containerSelector);
        container.innerHTML = '';

        var hasExtra = extraColumns !== null && extraColumns !== '';

        // Toolbar
        var toolbar = document.createElement('div');
        toolbar.className = 'd-flex justify-content-between align-items-center mb-3';

        var heading = document.createElement('h5');
        heading.className = 'mb-0';
        heading.textContent = currentListId;
        toolbar.appendChild(heading);

        var btnGroup = document.createElement('div');

        var backBtn = document.createElement('button');
        backBtn.className = 'btn btn-outline-secondary btn-sm me-2';
        backBtn.innerHTML = '<i class="fa fa-arrow-left me-1"></i> Lists';
        backBtn.addEventListener('click', function () {
            currentListId = null;
            extraColumns = null;
            loadLists();
        });
        btnGroup.appendChild(backBtn);

        var addBtn = document.createElement('button');
        addBtn.className = 'btn btn-success btn-sm';
        addBtn.innerHTML = '<i class="fa fa-plus me-1"></i> Add';
        addBtn.addEventListener('click', function () {
            showAddForm();
        });
        btnGroup.appendChild(addBtn);

        toolbar.appendChild(btnGroup);
        container.appendChild(toolbar);

        // Table
        var table = document.createElement('table');
        table.className = 'table table-sm table-hover table-bordered mb-0';

        var thead = document.createElement('thead');
        thead.className = 'table-light';

        var hRow = document.createElement('tr');

        var isApptstat = currentListId === 'apptstat';
        var headers = isApptstat
            ? ['Seq', 'Option ID', 'Title', 'Color', 'Alert Time', 'Check In', 'Check Out', 'Code(s)', 'Default', 'Active', 'Actions']
            : ['Seq', 'Option ID', 'Title', 'Notes', 'Codes', 'Default', 'Active', 'Actions'];

        headers.forEach(function (h) {
            var th = document.createElement('th');
            th.textContent = h;
            hRow.appendChild(th);
        });

        thead.appendChild(hRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        tbody.id = 'lom-tbody';

        if (options.length === 0) {
            var emptyRow = document.createElement('tr');
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = headers.length;
            emptyCell.className = 'text-center text-muted py-3';
            emptyCell.textContent = 'No options found for ' + currentListId;
            emptyRow.appendChild(emptyCell);
            tbody.appendChild(emptyRow);
        } else {
            options.forEach(function (opt, idx) {
                var row = buildRow(opt, idx + 1);
                tbody.appendChild(row);
            });
        }

        table.appendChild(tbody);
        container.appendChild(table);

        bindEvents();
    }

    function buildRow(opt, seq) {
        var row = document.createElement('tr');
        row.dataset.optionId = opt.option_id;

        // Seq
        var tdSeq = document.createElement('td');
        tdSeq.className = 'text-center';
        tdSeq.textContent = seq;
        row.appendChild(tdSeq);

        // Option ID
        var tdId = document.createElement('td');
        tdId.textContent = opt.option_id;
        tdId.style.fontFamily = 'monospace';
        row.appendChild(tdId);

        // Title (editable inline)
        var tdTitle = document.createElement('td');
        var titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'form-control form-control-sm lom-title';
        titleInput.value = opt.title || '';
        tdTitle.appendChild(titleInput);
        row.appendChild(tdTitle);

        var isApptstat = currentListId === 'apptstat';

        if (isApptstat) {
            // Color
            var tdColor = document.createElement('td');
            var colorInput = document.createElement('input');
            colorInput.type = 'text';
            colorInput.className = 'form-control form-control-sm lom-color';
            colorInput.value = opt.color || '';
            colorInput.placeholder = '#hexcolor';
            tdColor.appendChild(colorInput);
            row.appendChild(tdColor);

            // Alert Time
            var tdAlert = document.createElement('td');
            var alertInput = document.createElement('input');
            alertInput.type = 'number';
            alertInput.className = 'form-control form-control-sm lom-alert-time';
            alertInput.value = opt.alert_time || 0;
            alertInput.min = 0;
            alertInput.style.width = '70px';
            tdAlert.appendChild(alertInput);
            row.appendChild(tdAlert);

            // Check In (toggle_setting_1)
            var tdCI = document.createElement('td');
            tdCI.className = 'text-center';
            var ciCheck = document.createElement('input');
            ciCheck.type = 'checkbox';
            ciCheck.className = 'lom-checkin';
            if (String(opt.toggle_setting_1) === '1') ciCheck.checked = true;
            tdCI.appendChild(ciCheck);
            row.appendChild(tdCI);

            // Check Out (toggle_setting_2)
            var tdCO = document.createElement('td');
            tdCO.className = 'text-center';
            var coCheck = document.createElement('input');
            coCheck.type = 'checkbox';
            coCheck.className = 'lom-checkout';
            if (String(opt.toggle_setting_2) === '1') coCheck.checked = true;
            tdCO.appendChild(coCheck);
            row.appendChild(tdCO);

            // Code(s)
            var tdCodes = document.createElement('td');
            var codesInput = document.createElement('input');
            codesInput.type = 'text';
            codesInput.className = 'form-control form-control-sm lom-codes';
            codesInput.value = opt.codes || '';
            tdCodes.appendChild(codesInput);
            row.appendChild(tdCodes);
        } else {
            // Notes
            var tdNotes = document.createElement('td');
            var notesInput = document.createElement('input');
            notesInput.type = 'text';
            notesInput.className = 'form-control form-control-sm lom-notes';
            notesInput.value = opt.notes || '';
            tdNotes.appendChild(notesInput);
            row.appendChild(tdNotes);

            // Codes
            var tdCodes = document.createElement('td');
            var codesInput = document.createElement('input');
            codesInput.type = 'text';
            codesInput.className = 'form-control form-control-sm lom-codes';
            codesInput.value = opt.codes || '';
            tdCodes.appendChild(codesInput);
            row.appendChild(tdCodes);
        }

        // Default checkbox
        var tdDef = document.createElement('td');
        tdDef.className = 'text-center';
        var defCheck = document.createElement('input');
        defCheck.type = 'checkbox';
        defCheck.className = 'lom-default';
        if (String(opt.is_default) === '1') defCheck.checked = true;
        tdDef.appendChild(defCheck);
        row.appendChild(tdDef);

        // Active checkbox
        var tdAct = document.createElement('td');
        tdAct.className = 'text-center';
        var actCheck = document.createElement('input');
        actCheck.type = 'checkbox';
        actCheck.className = 'lom-active';
        if (String(opt.activity) === '1' || opt.activity === null) actCheck.checked = true;
        tdAct.appendChild(actCheck);
        row.appendChild(tdAct);

        // Actions
        var tdActs = document.createElement('td');
        tdActs.className = 'text-center';

        var saveBtn = document.createElement('button');
        saveBtn.className = 'btn btn-sm btn-primary me-1 lom-save';
        saveBtn.innerHTML = '<i class="fa fa-save"></i>';
        saveBtn.title = 'Save';
        tdActs.appendChild(saveBtn);

        var delBtn = document.createElement('button');
        delBtn.className = 'btn btn-sm btn-outline-danger lom-delete';
        delBtn.innerHTML = '<i class="fa fa-ban"></i>';
        delBtn.title = 'Deactivate';
        tdActs.appendChild(delBtn);

        row.appendChild(tdActs);

        return row;
    }

    // ---- Events -----------------------------------------------------------

    function bindEvents() {
        var container = document.querySelector(containerSelector);

        container.querySelectorAll('.lom-save').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = this.closest('tr');
                saveOption(row);
            });
        });

        container.querySelectorAll('.lom-delete').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = this.closest('tr');
                var optionId = row.dataset.optionId;
                if (confirm('Deactivate "' + optionId + '"?')) {
                    deleteOption(row);
                }
            });
        });

        // Enter key on title input triggers save
        container.querySelectorAll('.lom-title').forEach(function (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var row = this.closest('tr');
                    saveOption(row);
                }
            });
        });
    }

    // ---- CRUD calls -------------------------------------------------------

    function saveOption(row) {
        var data = new URLSearchParams();
        data.append('action', 'save_option');
        data.append('list_id', currentListId);
        data.append('option_id', row.dataset.optionId);
        data.append('title', row.querySelector('.lom-title').value);
        data.append('seq', row.rowIndex);
        data.append('is_default', row.querySelector('.lom-default').checked ? '1' : '0');
        data.append('activity', row.querySelector('.lom-active').checked ? '1' : '0');

        if (currentListId === 'apptstat') {
            data.append('color', row.querySelector('.lom-color').value);
            data.append('alert_time', row.querySelector('.lom-alert-time').value);
            data.append('toggle_setting_1', row.querySelector('.lom-checkin').checked ? '1' : '0');
            data.append('toggle_setting_2', row.querySelector('.lom-checkout').checked ? '1' : '0');
            data.append('codes', row.querySelector('.lom-codes').value);
        } else {
            data.append('notes', row.querySelector('.lom-notes').value);
            data.append('codes', row.querySelector('.lom-codes').value);
        }
        data.append('csrf_token_form', csrfToken);

        fetch(endpointUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.success) {
                    loadOptions();
                } else {
                    showError(json.message);
                }
            })
            .catch(function (err) { showError('Network error: ' + err.message); });
    }

    function deleteOption(row) {
        var data = new URLSearchParams();
        data.append('action', 'delete_option');
        data.append('list_id', currentListId);
        data.append('option_id', row.dataset.optionId);
        data.append('csrf_token_form', csrfToken);

        fetch(endpointUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data
        })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (json.success) {
                    loadOptions();
                } else {
                    showError(json.message);
                }
            })
            .catch(function (err) { showError('Network error: ' + err.message); });
    }

    // ---- Add new option ---------------------------------------------------

    function showAddForm() {
        var container = document.querySelector(containerSelector);
        var form = document.createElement('div');
        form.id = 'lom-add-form';
        form.className = 'card mb-3 border-success';
        form.innerHTML =
            '<div class="card-body p-3">' +
            '  <h6 class="card-title text-success mb-3">New Option</h6>' +
            '  <div class="row g-2 align-items-end">' +
            '    <div class="col-auto">' +
            '      <label class="small mb-1">Option ID</label>' +
            '      <input type="text" id="lom-new-id" class="form-control form-control-sm" placeholder="e.g. my_option">' +
            '    </div>' +
            '    <div class="col">' +
            '      <label class="small mb-1">Title</label>' +
            '      <input type="text" id="lom-new-title" class="form-control form-control-sm" placeholder="Display title">' +
            '    </div>' +
            (currentListId === 'apptstat' ?
            '    <div class="col-auto">' +
            '      <label class="small mb-1">Color</label>' +
            '      <input type="text" id="lom-new-color" class="form-control form-control-sm" placeholder="#hexcolor" style="width:100px">' +
            '    </div>' +
            '    <div class="col-auto">' +
            '      <label class="small mb-1">Alert Time</label>' +
            '      <input type="number" id="lom-new-alert" class="form-control form-control-sm" value="0" min="0" style="width:70px">' +
            '    </div>' +
            '    <div class="col-auto">' +
            '      <label class="small mb-1"><input type="checkbox" id="lom-new-checkin"> Check In</label>' +
            '    </div>' +
            '    <div class="col-auto">' +
            '      <label class="small mb-1"><input type="checkbox" id="lom-new-checkout"> Check Out</label>' +
            '    </div>' +
            '    <div class="col">' +
            '      <label class="small mb-1">Code(s)</label>' +
            '      <input type="text" id="lom-new-codes" class="form-control form-control-sm" placeholder="Code(s)">' +
            '    </div>' :
            '    <div class="col">' +
            '      <label class="small mb-1">Notes</label>' +
            '      <input type="text" id="lom-new-notes" class="form-control form-control-sm" placeholder="Notes">' +
            '    </div>' +
            '    <div class="col">' +
            '      <label class="small mb-1">Codes</label>' +
            '      <input type="text" id="lom-new-codes" class="form-control form-control-sm" placeholder="Codes">' +
            '    </div>') +
            '    <div class="col-auto">' +
            '      <label class="small mb-1">&nbsp;</label>' +
            '      <div>' +
            '        <button class="btn btn-sm btn-success me-1" id="lom-new-save"><i class="fa fa-check"></i> Save</button>' +
            '        <button class="btn btn-sm btn-outline-secondary" id="lom-new-cancel">Cancel</button>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '</div>';

        container.insertBefore(form, container.firstChild.nextSibling);

        document.getElementById('lom-new-save').addEventListener('click', function () {
            var optionId = document.getElementById('lom-new-id').value.trim();
            var title = document.getElementById('lom-new-title').value.trim();

            if (!optionId) {
                alert('Option ID is required');
                return;
            }

            var data = new URLSearchParams();
            data.append('action', 'save_option');
            data.append('list_id', currentListId);
            data.append('option_id', optionId);
            data.append('title', title);
            data.append('seq', '0');
            data.append('is_default', '0');
            data.append('activity', '1');
            data.append('csrf_token_form', csrfToken);

            if (currentListId === 'apptstat') {
                data.append('color', document.getElementById('lom-new-color').value.trim());
                data.append('alert_time', document.getElementById('lom-new-alert').value);
                data.append('toggle_setting_1', document.getElementById('lom-new-checkin').checked ? '1' : '0');
                data.append('toggle_setting_2', document.getElementById('lom-new-checkout').checked ? '1' : '0');
                data.append('codes', document.getElementById('lom-new-codes').value.trim());
            } else {
                data.append('notes', document.getElementById('lom-new-notes').value.trim());
                data.append('codes', document.getElementById('lom-new-codes').value.trim());
            }

            fetch(endpointUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data
            })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json.success) {
                        loadOptions();
                    } else {
                        showError(json.message);
                    }
                })
                .catch(function (err) { showError('Network error: ' + err.message); });
        });

        document.getElementById('lom-new-cancel').addEventListener('click', function () {
            var el = document.getElementById('lom-add-form');
            if (el) el.remove();
        });
    }

    // ---- Error display ----------------------------------------------------

    function showError(msg) {
        if (typeof alert !== 'undefined') {
            alert(msg);
        }
    }

    // ---- Public API -------------------------------------------------------

    return {
        init: init,
        initPicker: initPicker
    };

})();
