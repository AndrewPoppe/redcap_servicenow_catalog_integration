<?php
/**
 * ServiceNow Catalog Integration – Configuration Page
 *
 * @var \ExternalModules\AbstractExternalModule $module
 */

defined('APP_PATH_WEBROOT') || die('Direct access not permitted.');

if (!$module->framework->isSuperUser()) {
    exit($module->framework->tt('This page is only accessible to REDCap administrators.'));
}

$pid = (int) $module->getProjectId();


// ─── Page Data ─────────────────────────────────────────────────────────────────
$savedUrl      = (string) ($module->getProjectSetting('servicenow-instance-url') ?? '');
$savedUsername = (string) ($module->getProjectSetting('servicenow-username') ?? '');
$hasPassword   = !empty($module->getProjectSetting('servicenow-password'));
$savedSysId    = (string) ($module->getProjectSetting('servicenow-catalog-sys-id') ?? '');
if (empty($savedSysId) && !empty($snapshot['sys_id'])) {
    $savedSysId = (string) $snapshot['sys_id'];
}
$fieldMappings = $module->getProjectSetting('field-mappings');
if (is_string($fieldMappings)) {
    // Handle legacy saves where mappings were stored as a JSON string
    $fieldMappings = json_decode($fieldMappings, true) ?? [];
}
if (!is_array($fieldMappings)) {
    $fieldMappings = [];
}

$metaData     = \REDCap::getDataDictionary('array');
$redcapFields = [];
foreach ($metaData as $fieldInfo) {
    if ($fieldInfo['field_type'] === 'descriptive') {
        continue;
    }
    $redcapFields[] = [
        'name'  => $fieldInfo['field_name'],
        'label' => strip_tags($fieldInfo['field_label']),
        'type'  => $fieldInfo['field_type'],
        'form'  => $fieldInfo['form_name'],
    ];
}

$snapshot = $module->getProjectSetting('catalog-item-snapshot');
if (!is_array($snapshot) || empty($snapshot['variables']) || !is_array($snapshot['variables'])) {
    $snapshot = null;
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<style>
    #sn-config-page .card-header {
        font-weight: 600;
        font-size: 0.95rem;
    }
    #mapping-table thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background-color: #f8f9fa;
        border-top: none;
        white-space: nowrap;
    }
    #mapping-table code {
        font-size: 0.82em;
        color: #6f42c1;
        background: #f3f0ff;
        padding: 2px 6px;
        border-radius: 3px;
    }
    #mapping-table tbody tr:hover {
        background-color: #f5f7ff;
    }
    .rc-field-select { min-width: 230px; }
    .select2-container { min-width: 230px; }
    .select2-container .select2-selection--single { height: 31px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 29px; padding-right: 28px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 29px; }
    .sn-badge {
        font-size: 0.7em;
        font-weight: 600;
        padding: 3px 7px;
        border-radius: 3px;
        display: inline-block;
        margin: 1px 1px 1px 0;
        white-space: nowrap;
    }
    .sn-badge-required { background: #fce4e4; color: #c0392b; border: 1px solid #f1c0c0; }
    .sn-badge-readonly  { background: #e8f4fd; color: #1a6fa0; border: 1px solid #b8dff5; }
    #test-status { font-size: 0.9rem; }
    #sn-config-page .input-group-append .btn { border-left: 0; }
</style>

<div id="sn-config-page" class="container-fluid py-4">

    <div class="row mb-3">
        <div class="col">
            <h3 class="mb-1">
                <i class="fas fa-exchange-alt text-primary mr-2"></i>ServiceNow Catalog Integration
            </h3>
            <p class="text-muted mb-0">
                Configure the connection to your ServiceNow instance and map REDCap fields to the selected catalog item.
            </p>
        </div>
    </div>

    <div id="alert-area" class="mb-3"></div>

    <!-- ── Connection Settings ──────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-plug mr-1"></i> Connection Settings
        </div>
        <div class="card-body">

            <div class="form-group">
                <label for="instance-url" class="font-weight-bold">ServiceNow Instance URL</label>
                <input type="url" class="form-control" id="instance-url"
                       style="max-width:540px"
                       placeholder="https://your-instance.service-now.com"
                       value="<?= htmlspecialchars($savedUrl, ENT_QUOTES) ?>">
                <small class="form-text text-muted">Must begin with <code>https://</code>.</small>
            </div>

            <div class="row">
                <div class="col-md-4 form-group">
                    <label for="sn-username" class="font-weight-bold">Username</label>
                    <input type="text" class="form-control" id="sn-username"
                           autocomplete="username"
                           placeholder="service_account_name"
                           value="<?= htmlspecialchars($savedUsername, ENT_QUOTES) ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label for="sn-password" class="font-weight-bold">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="sn-password"
                               autocomplete="current-password"
                               placeholder="<?= $hasPassword ? 'Saved — enter to change' : 'Enter password' ?>"
                               data-has-saved="<?= $hasPassword ? '1' : '0' ?>">
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary" type="button" id="btn-toggle-pw"
                                    title="Show / hide password">
                                <i class="fas fa-eye" id="pw-eye-icon"></i>
                            </button>
                        </div>
                    </div>
                    <?php if ($hasPassword): ?>
                    <small class="form-text text-muted">A password is saved. Leave blank to keep it.</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex align-items-center flex-wrap" style="gap:0.5rem">
                <button class="btn btn-outline-primary" id="btn-test" type="button">
                    <i class="fas fa-satellite-dish mr-1"></i> Test Connection
                </button>
                <button class="btn btn-outline-success" id="btn-save-connection" type="button" disabled>
                    <i class="fas fa-save mr-1"></i> Save Connection Settings
                </button>
                <span id="test-status"></span>
            </div>

        </div>
    </div>

    <!-- ── Catalog Item ──────────────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <i class="fas fa-list-alt mr-1"></i> Catalog Item
        </div>
        <div class="card-body">

            <div class="form-group mb-0">
                <label for="catalog-sys-id" class="font-weight-bold">Catalog Item sys_id</label>
                <div class="input-group" style="max-width:540px">
                    <input type="text" class="form-control" id="catalog-sys-id"
                           style="font-family:monospace"
                           placeholder="e.g. 1a2b3c4d5e6f7890abcdef1234567890"
                           maxlength="32"
                           value="<?= htmlspecialchars($savedSysId, ENT_QUOTES) ?>">
                    <div class="input-group-append">
                        <button class="btn btn-primary" id="btn-fetch" type="button">
                            <i class="fas fa-sync-alt mr-1"></i> Load Fields
                        </button>
                    </div>
                </div>
                <small class="form-text text-muted">
                    The 32-character hexadecimal sys_id of the ServiceNow catalog item.
                </small>
            </div>

        </div>
    </div>

    <!-- ── Field Mapping ─────────────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4" id="mapping-card" style="display:none">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-map-signs mr-1"></i> Field Mapping</span>
            <span class="badge badge-light text-dark px-2 py-1" id="mapping-count"></span>
        </div>
        <div class="card-body p-0">
            <div class="p-3 border-bottom bg-light">
                <p class="mb-0 text-muted small">
                    For each ServiceNow variable, choose the REDCap field whose value should be submitted.
                    Variables marked <span class="sn-badge sn-badge-required">Required</span> must be
                    mapped before saving.
                    Variables marked <span class="sn-badge sn-badge-readonly">Read-only</span> are
                    read-only in the ServiceNow UI but can still be mapped for submission.
                </p>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="mapping-table">
                    <thead>
                        <tr>
                            <th>Variable Name</th>
                            <th>Display Label</th>
                            <th>Type</th>
                            <th>Attributes</th>
                            <th>REDCap Field</th>
                        </tr>
                    </thead>
                    <tbody id="mapping-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Actions ───────────────────────────────────────────────────────── -->
    <div class="mb-5">
        <button class="btn btn-success btn-lg" id="btn-save" type="button" disabled>
            <i class="fas fa-save mr-1"></i> Save Configuration
        </button>
        <button class="btn btn-outline-secondary btn-lg ml-2" id="btn-reload" type="button" style="display:none">
            <i class="fas fa-sync-alt mr-1"></i> Reload Fields
        </button>
    </div>

</div><!-- /#sn-config-page -->

<?= $module->initializeJavascriptModuleObject() ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
$(document).ready(function () {
    'use strict';

    const module = <?= $module->getJavascriptModuleObjectName() ?>;

    const SAVED_MAP    = <?= json_encode($fieldMappings) ?>;
    const RC_FIELDS    = <?= json_encode($redcapFields) ?>;
    let HAS_PASSWORD = <?= $hasPassword ? 'true' : 'false' ?>;
    const SNAPSHOT     = <?= json_encode($snapshot) ?>;

    // ServiceNow catalog variable type codes → labels
    const SN_VAR_TYPES = {
        '1':  'Single Line Text',
        '2':  'Multi Line Text',
        '3':  'Multiple Choice',
        '4':  'Check Box',
        '5':  'Reference',
        '6':  'Date',
        '7':  'Date/Time',
        '8':  'List Collector',
        '9':  'Lookup Select Box',
        '13': 'Label',
        '14': 'Requested Item Variable',
        '15': 'Scale',
        '16': 'Wide Single Line Text',
        '18': 'Masked',
        '19': 'Email',
        '20': 'URL',
        '21': 'Map',
        '23': 'Numeric Scale',
        '24': 'Signature'
    };

    // Structural / display-only variable types that cannot be mapped
    const SKIP_TYPES = ['11', '12', '13', '22'];

    // ── Utilities ─────────────────────────────────────────────────────────
    function escHtml(str) {
        return $('<span>').text(String(str == null ? '' : str)).html();
    }

    function resolveType(t) {
        const key = String(t || '');
        return SN_VAR_TYPES[key] || (key !== '' ? key : 'Unknown');
    }

    function showAlert(type, message, autoDismiss) {
        const icons = { success: 'check-circle', warning: 'exclamation-triangle', danger: 'times-circle', info: 'info-circle' };
        const html  = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
                    '<i class="fas fa-' + (icons[type] || 'info-circle') + ' mr-1"></i> ' + message +
                    '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>' +
                    '</div>';
        $('#alert-area').html(html);
        if (autoDismiss) {
            setTimeout(function () { $('#alert-area .alert').alert('close'); }, 5000);
        }
        $('html, body').animate({ scrollTop: 0 }, 250);
    }

    function getCredentials() {
        const pw = $('#sn-password').val();
        return {
            instanceUrl: $('#instance-url').val().trim(),
            username:    $('#sn-username').val().trim(),
            // Send sentinel when no new password typed but one is saved server-side
            password:    pw !== '' ? pw : (HAS_PASSWORD ? '__SAVED__' : '')
        };
    }

    // ── REDCap field dropdown ─────────────────────────────────────────────
    function buildRcDropdown(selectedValue) {
        const formOrder = [];
        const byForm    = {};
        RC_FIELDS.forEach(function (f) {
            if (!byForm[f.form]) {
                formOrder.push(f.form);
                byForm[f.form] = [];
            }
            byForm[f.form].push(f);
        });

        // Empty first option required for Select2 placeholder + allowClear
        let opts = '<option value=""></option>';
        formOrder.forEach(function (form) {
            const formLabel = form.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            opts += '<optgroup label="' + escHtml(formLabel) + '">';
            byForm[form].forEach(function (f) {
                const sel  = (f.name === selectedValue) ? ' selected' : '';
                const text = escHtml(f.label) + ' [' + escHtml(f.name) + ']';
                opts += '<option value="' + escHtml(f.name) + '"' + sel + '>' + text + '</option>';
            });
            opts += '</optgroup>';
        });

        return '<select class="form-control form-control-sm rc-field-select">' + opts + '</select>';
    }

    // ── Render mapping table ──────────────────────────────────────────────
    function renderMappingTable(fields) {
        const tbody = $('#mapping-tbody').empty();
        let rowCount = 0;

        fields.forEach(function (field) {
            const name     = String(field.name || '');
            const label    = String(field.label || field.display_label || name);
            const typeKey  = String(field.type || '');
            const type     = resolveType(typeKey);
            const required = String(field.mandatory || field.required || 'false').toLowerCase() === 'true';
            const active   = String(field.active || 'true').toLowerCase() !== 'false';
            const readOnly = String(field.read_only || 'false').toLowerCase() === 'true';

            if (SKIP_TYPES.indexOf(typeKey) !== -1 || !active) {
                return; // skip structural / inactive variables
            }

            const savedRcField = SAVED_MAP[name] || '';
            let badges = '';
            if (required) badges += '<span class="sn-badge sn-badge-required">Required</span>';
            if (readOnly) badges += '<span class="sn-badge sn-badge-readonly">Read-only</span>';

            const rcCell = buildRcDropdown(savedRcField);

            tbody.append(
                '<tr data-sn-field="' + escHtml(name) + '"' +
                    ' data-required="' + required + '"' +
                    ' data-readonly="' + readOnly + '">' +
                '<td><code>' + escHtml(name) + '</code></td>' +
                '<td>' + escHtml(label) + '</td>' +
                '<td><small class="text-muted">' + escHtml(type) + '</small></td>' +
                '<td>' + (badges || '<span class="text-muted">—</span>') + '</td>' +
                '<td>' + rcCell + '</td>' +
                '</tr>'
            );
            rowCount++;
        });

        if (rowCount === 0) {
            tbody.append('<tr><td colspan="5" class="text-center text-muted py-3">' +
                         'No mappable variables found for this catalog item.</td></tr>');
        }

        $('#mapping-count').text(rowCount + ' variable' + (rowCount !== 1 ? 's' : ''));
        $('#mapping-card').fadeIn(200);
        $('#btn-save').prop('disabled', false);
        $('#btn-reload').show();

        // Initialise Select2 on all dropdowns, then restore saved mappings.
        // Select2 does not reliably read the HTML `selected` attribute when
        // allowClear/placeholder are active, so we set the value explicitly.
        $('#mapping-tbody .rc-field-select').select2({
            width:          '100%',
            placeholder:    '\u2014 Do not map \u2014',
            allowClear:     true,
            dropdownParent: $('#sn-config-page')
        });

        $('#mapping-tbody tr[data-sn-field]').each(function () {
            const snField    = $(this).data('sn-field');
            const savedValue = SAVED_MAP[snField] || null;
            $(this).find('.rc-field-select').val(savedValue).trigger('change');
        });
    }

    // ── Collect current mappings from table ───────────────────────────────
    function collectMappings() {
        const map = {};
        $('#mapping-tbody tr[data-sn-field]').each(function () {
            const snField = $(this).data('sn-field');
            const rcField = $(this).find('.rc-field-select').val();
            map[snField] = rcField || '';
        });
        return map;
    }

    // ── Validate that all required fields are mapped ───────────────────────
    function validateMappings() {
        const errors = [];
        $('#mapping-tbody tr[data-required="true"]').each(function () {
            if ($(this).data('readonly') === true) return;
            const rcField = $(this).find('.rc-field-select').val();
            if (!rcField) {
                errors.push($(this).data('sn-field'));
            }
        });
        return errors;
    }

    // ── Test Connection ───────────────────────────────────────────────────
    $('#btn-test').on('click', function () {
        const creds = getCredentials();
        console.log('Testing connection with credentials:', creds);
        if (!creds.instanceUrl || !creds.username || !creds.password) {
            showAlert('warning', 'Please fill in the Instance URL, username, and password before testing.');
            return;
        }
        const $btn = $(this).prop('disabled', true)
                          .html('<i class="fas fa-spinner fa-spin mr-1"></i> Testing…');
        $('#test-status').html('');
    
        module.ajax('testConnection', {
            instanceUrl: creds.instanceUrl,
            username:    creds.username,
            password:    creds.password
        }).then(function (resp) {
            if (resp.success) {
                $('#test-status').html(
                    '<span class="text-success"><i class="fas fa-check-circle mr-1"></i>Connected successfully</span>'
                );
                $('#btn-save-connection').prop('disabled', false);
            } else {
                $('#test-status').html(
                    '<span class="text-danger"><i class="fas fa-times-circle mr-1"></i>' +
                    escHtml(resp.error || 'Connection failed') + '</span>'
                );
                $('#btn-save-connection').prop('disabled', true);
            }
        }).catch(function () {
            $('#test-status').html(
                '<span class="text-danger"><i class="fas fa-times-circle mr-1"></i>Request error</span>'
            );
            $('#btn-save-connection').prop('disabled', true);
        }).finally(function () {
            $btn.prop('disabled', false)
                .html('<i class="fas fa-satellite-dish mr-1"></i> Test Connection');
        });
    });

    // ── Save Connection Settings ──────────────────────────────────────────
    $('#btn-save-connection').on('click', function () {
        const creds = getCredentials();
        const $btn  = $(this).prop('disabled', true)
                             .html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving…');

        module.ajax('saveConnectionSettings', {
            instanceUrl: creds.instanceUrl,
            username:    creds.username,
            password:    creds.password
        }).then(function (resp) {
            if (resp.success) {
                showAlert('success', 'Connection settings saved.', true);
                if ($('#sn-password').val() !== '') {
                    HAS_PASSWORD = true;
                    $('#sn-password').val('').attr('placeholder', 'Saved — enter to change');
                }
            } else {
                showAlert('danger', 'Save failed: ' + escHtml(resp.error || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Save request failed. Please try again.');
        }).finally(function () {
            $btn.prop('disabled', false)
                .html('<i class="fas fa-save mr-1"></i> Save Connection Settings');
        });
    });

    // ── Fetch ServiceNow Fields ───────────────────────────────────────────
    function fetchFields() {
        const creds = getCredentials();
        const sysId = $('#catalog-sys-id').val().trim();

        if (!creds.instanceUrl || !creds.username || !creds.password) {
            showAlert('warning', 'Please fill in all Connection Settings before loading fields.');
            return;
        }
        if (!sysId) {
            showAlert('warning', 'Please enter the catalog item sys_id.');
            return;
        }

        const $btn = $('#btn-fetch').prop('disabled', true)
                                  .html('<i class="fas fa-spinner fa-spin mr-1"></i> Loading…');
        $('#mapping-card').hide();
        $('#btn-save').prop('disabled', true);
        $('#alert-area').empty();

        module.ajax('getServiceNowFields', {
            instanceUrl: creds.instanceUrl,
            username:    creds.username,
            password:    creds.password,
            sysId:       sysId
        }).then(function (resp) {
            console.log('getServiceNowFields response:', resp);
            const fields = resp?.data?.result;
            if (resp.success && Array.isArray(fields)) {
                if (fields.length === 0) {
                    showAlert('warning',
                        'No variables were returned for this catalog item. ' +
                        'Verify the sys_id and confirm the item has variables configured.');
                } else {
                    renderMappingTable(fields);
                }
            } else {
                showAlert('danger', 'Could not load fields: ' + escHtml(resp.error || 'Unknown error.'));
            }
        }).catch(function (error) {
            console.log('getServiceNowFields request failed:', error);
            showAlert('danger', 'Request failed. Check your connection settings and try again.');
        }).finally(function () {
            $btn.prop('disabled', false)
                .html('<i class="fas fa-sync-alt mr-1"></i> Load Fields');
        });
    }

    $('#btn-fetch').on('click', fetchFields);
    $('#btn-reload').on('click', fetchFields);

    // ── Save Configuration ────────────────────────────────────────────────
    $('#btn-save').on('click', function () {
        const errors = validateMappings();
        if (errors.length > 0) {
            showAlert('warning',
                'The following required ServiceNow variables are not mapped to a REDCap field: ' +
                '<strong>' + errors.map(escHtml).join(', ') + '</strong>. ' +
                'Please map all required variables before saving.');
            return;
        }
        const mappings = collectMappings();
        const $btn = $(this).prop('disabled', true)
                          .html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving…');

        module.ajax('saveConfiguration', {
            sysId:         $('#catalog-sys-id').val().trim(),
            fieldMappings: mappings
        }).then(function (resp) {
            if (resp.success) {
                showAlert('success', 'Configuration saved successfully.', true);
                // Clear password field; a new value is now saved
                if ($('#sn-password').val() !== '') {
                    HAS_PASSWORD = true;
                    $('#sn-password').val('').attr('placeholder', 'Saved — enter to change');
                    $('#sn-password').siblings('small').text('A password is saved. Leave blank to keep it.');
                }
            } else {
                showAlert('danger', 'Save failed: ' + escHtml(resp.error || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Save request failed. Please try again.');
        }).finally(function () {
            $btn.prop('disabled', false)
                .html('<i class="fas fa-save mr-1"></i> Save Configuration');
        });
    });

    // ── Password visibility toggle ────────────────────────────────────────
    $('#btn-toggle-pw').on('click', function () {
        const $input = $('#sn-password');
        const show   = $input.attr('type') === 'password';
        $input.attr('type', show ? 'text' : 'password');
        $('#pw-eye-icon').toggleClass('fa-eye fa-eye-slash');
    });

    // ── Populate mapping table from snapshot on page load ─────────────────
    if (SNAPSHOT && Array.isArray(SNAPSHOT.variables) && SNAPSHOT.variables.length > 0) {
        renderMappingTable(SNAPSHOT.variables);
    }
});
</script>
