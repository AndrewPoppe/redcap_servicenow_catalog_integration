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

$recordHomeUrl = APP_PATH_WEBROOT . 'DataEntry/record_home.php?pid=' . $pid;


// ─── Page Data ─────────────────────────────────────────────────────────────────
$savedUrl      = (string) ($module->getProjectSetting('servicenow-instance-url') ?? '');
$savedUsername = (string) ($module->getProjectSetting('servicenow-username') ?? '');
$hasPassword   = !empty($module->getProjectSetting('servicenow-password'));

$catalogItems = $module->getCatalogItems();

$metaData     = \REDCap::getDataDictionary('array');
$redcapFields = [];
foreach ($metaData as $fieldInfo) {
    if ($fieldInfo['field_type'] === 'descriptive') {
        continue;
    }
    $fieldChoices = [];
    switch ($fieldInfo['field_type']) {
        case 'yesno':
            $fieldChoices = [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']];
            break;
        case 'truefalse':
            $fieldChoices = [['value' => '1', 'label' => 'True'], ['value' => '0', 'label' => 'False']];
            break;
        case 'radio':
        case 'select':
        case 'dropdown':
        case 'checkbox':
            foreach (explode('|', $fieldInfo['select_choices_or_calculations'] ?? '') as $part) {
                $part = trim($part);
                if ($part === '') continue;
                $comma = strpos($part, ',');
                if ($comma !== false) {
                    $fieldChoices[] = [
                        'value' => trim(substr($part, 0, $comma)),
                        'label' => trim(substr($part, $comma + 1)),
                    ];
                }
            }
            break;
    }
    $redcapFields[] = [
        'name'    => $fieldInfo['field_name'],
        'label'   => strip_tags($fieldInfo['field_label']),
        'type'    => $fieldInfo['field_type'],
        'form'    => $fieldInfo['form_name'],
        'choices' => $fieldChoices,
    ];
}

$instrumentNames = \REDCap::getInstrumentNames();
$redcapForms     = [];
foreach ($instrumentNames as $formName => $formLabel) {
    $redcapForms[] = ['name' => $formName, 'label' => $formLabel];
}

// Append _complete status fields
foreach ($redcapForms as $form) {
    $redcapFields[] = [
        'name'    => $form['name'] . '_complete',
        'label'   => $form['label'] . ' (completion status)',
        'type'    => 'complete',
        'form'    => $form['name'],
        'choices' => [
            ['value' => '0', 'label' => 'Incomplete'],
            ['value' => '1', 'label' => 'Unverified'],
            ['value' => '2', 'label' => 'Complete'],
        ],
    ];
}
?>
<!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css"> -->
<style>
    #sn-config-page {
        width: 100%;
        box-sizing: border-box;
    }
    #sn-config-page .card-header {
        font-weight: 600;
        font-size: 0.95rem;
    }
    #mapping-table thead th,
    #modal-mapping-table thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background-color: #f8f9fa;
        border-top: none;
        white-space: nowrap;
    }
    #modal-mapping-table code,
    #mapping-table code {
        font-size: 0.82em;
        color: #6f42c1;
        background: #f3f0ff;
        padding: 2px 6px;
        border-radius: 3px;
    }
    #modal-mapping-table tbody tr:hover {
        background-color: #f5f7ff;
    }
    .rc-field-select { min-width: 230px; }
    .select2-container { min-width: 230px; }
    .select2-container .select2-selection--single { height: 31px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 29px; padding-right: 28px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 29px; }
    .criterion-field-wrap .select2-container { min-width: 0; }
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
    #test-status { font-size: 0.9rem; }
    /* #sn-config-page .input-group-append .btn { border-left: 0; } */
    #catalog-items-table { margin-bottom: 0; }
    #catalog-items-table th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; background: #f8f9fa; }
    #catalog-items-empty { padding: 2.5rem 1rem; }
    #toast-container {
        position: fixed;
        bottom: 1.5rem;
        right:  1.5rem;
        z-index: 1200;
        min-width: 300px;
        max-width: 440px;
    }
    #toast-container .toast { width: 100%; }
    /* Make field values modal appear above catalog item modal */
    #field-values-modal { z-index: 1210; }
    #field-values-modal .modal-dialog {
        max-width: 640px;
    }
    /* Value matching modal appears above the catalog item modal */
    #value-match-modal { z-index: 1215; }
    #value-match-modal .modal-dialog { max-width: 660px; }
    #value-match-table thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        background: #f8f9fa;
        white-space: nowrap;
    }
    #value-match-table code {
        font-size: 0.82em;
        color: #6f42c1;
        background: #f3f0ff;
        padding: 2px 6px;
        border-radius: 3px;
    }
    .btn-match-values { white-space: nowrap; }
    .value-map-badge {
        font-size: 0.7em;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 3px;
        background: #e2f0d9;
        color: #2e6b1f;
        border: 1px solid #cfe6c0;
    }
    /* #field-values-modal .modal-content {
        border: 1px solid rgba(13, 42, 74, 0.18);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 1.5rem 3.5rem rgba(7, 28, 52, 0.35), 0 0 0 4px rgba(255, 255, 255, 0.7);
        background: #ffffff;
    }
    #field-values-modal .modal-header {
        background: linear-gradient(135deg, #123a63 0%, #1d5d8f 100%);
        color: #fff;
        border-bottom: none;
        padding: 0.9rem 1rem;
    } */
    #field-values-modal .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
        opacity: 0.95;
    }
    /* #field-values-modal .modal-body {
        background: linear-gradient(180deg, #f7fbff 0%, #ffffff 100%);
    }
    #field-values-modal .table thead th {
        background: #eaf3fb;
        color: #123a63;
        border-bottom-color: #c9dced;
    }
    #field-values-modal .table tbody td {
        vertical-align: middle;
    }
    #field-values-modal .table tbody tr:nth-child(even) {
        background: rgba(18, 58, 99, 0.03);
    }
    #field-values-modal .table code {
        background: #e9f4ff;
        color: #0d4f82;
        padding: 2px 6px;
        border-radius: 4px;
    } */
</style>

<div id="sn-config-page" class="py-4 px-3">

    <div class="row mb-3">
        <div class="col">
            <h3 class="mb-1">
                <i class="fas fa-exchange-alt text-primary mr-2"></i>ServiceNow Catalog Integration
            </h3>
            <p class="text-muted mb-0">
                Configure the connection to your ServiceNow instance and manage catalog item integrations.
            </p>
        </div>
    </div>

    <div id="alert-area" class="mb-3" style="display:none"></div>

    <!-- ── Tab Navigation ──────────────────────────────────────────────── -->
    <ul class="nav nav-tabs mb-3" id="sn-config-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-config-btn" data-bs-toggle="tab"
                    data-bs-target="#tab-config" type="button" role="tab"
                    aria-controls="tab-config" aria-selected="true">
                <i class="fas fa-sliders-h mr-1"></i> Configuration
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-log-btn" data-bs-toggle="tab"
                    data-bs-target="#tab-log" type="button" role="tab"
                    aria-controls="tab-log" aria-selected="false">
                <i class="fas fa-clipboard-list mr-1"></i> Submission Log
            </button>
        </li>
    </ul>

    <div class="tab-content" id="sn-config-tab-content">

    <!-- ════ Configuration Tab ═══════════════════════════════════════════ -->
    <div class="tab-pane fade show active" id="tab-config" role="tabpanel" aria-labelledby="tab-config-btn">

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
                        <button class="btn btn-outline-secondary" type="button" id="btn-toggle-pw"
                                title="Show / hide password">
                            <i class="fas fa-eye" id="pw-eye-icon"></i>
                        </button>
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

    <!-- ── Catalog Items ─────────────────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list mr-1"></i> Catalog Items</span>
            <button class="btn btn-sm btn-light" id="btn-add-catalog-item" type="button">
                <i class="fas fa-plus mr-1"></i> Add Catalog Item
            </button>
        </div>
        <div class="card-body p-0">
            <div id="catalog-items-empty" class="text-center text-muted" style="display:none">
                <i class="fas fa-inbox fa-2x mb-2 d-block text-secondary"></i>
                No catalog items configured. Click <strong>Add Catalog Item</strong> to get started.
            </div>
            <table class="table table-sm table-hover mb-0" id="catalog-items-table" style="display:none">
                <thead>
                    <tr>
                        <th style="width:35%" class="ps-3">Label</th>
                        <th style="width:12%">Status</th>
                        <th>Trigger Form</th>
                        <th style="width:160px" class="text-right pr-3">Actions</th>
                    </tr>
                </thead>
                <tbody id="catalog-items-tbody"></tbody>
            </table>
        </div>
    </div>

    </div><!-- /#tab-config -->

    <!-- ════ Submission Log Tab ══════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-log" role="tabpanel" aria-labelledby="tab-log-btn">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span><i class="fas fa-clipboard-list mr-1"></i> RITM Submission Log</span>
                <button class="btn btn-sm btn-light" id="btn-refresh-log" type="button">
                    <i class="fas fa-sync-alt mr-1"></i> Refresh
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Every successful ServiceNow request item (RITM) submission is recorded below.
                </p>
                <table id="submission-log-table" class="hover w-100">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Record</th>
                            <th>Catalog Item</th>
                            <th>Trigger Form</th>
                            <th>Request Number</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

    </div><!-- /#tab-log -->

    </div><!-- /#sn-config-tab-content -->

</div><!-- /#sn-config-page -->


<!-- ── Catalog Item Modal (XL) ───────────────────────────────────────────── -->
<div class="modal fade" id="catalog-item-modal" tabindex="-1"
     aria-labelledby="catalog-item-modal-title" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="catalog-item-modal-title">Add Catalog Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">

                <!-- General Settings -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <i class="fas fa-info-circle mr-1"></i> General Settings
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="modal-item-label" class="font-weight-bold">
                                Label <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="modal-item-label"
                                   style="max-width:420px"
                                   placeholder="e.g. IT Access Request">
                            <small class="form-text text-muted">A descriptive name to identify this catalog item configuration.</small>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="modal-item-active" checked>
                            <label class="form-check-label font-weight-bold" for="modal-item-active">Active</label>
                            <div><small class="text-muted">When unchecked, this catalog item will not be triggered even if its conditions are met.</small></div>
                        </div>
                    </div>
                </div>

                <!-- Catalog Item & Field Mapping -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-map-signs mr-1"></i> Catalog Item &amp; Field Mapping</span>
                        <span class="badge bg-light text-dark px-2 py-1" id="modal-mapping-count" style="display:none"></span>
                    </div>
                    <div class="card-body border-bottom">
                        <div class="form-group mb-0">
                            <label for="modal-item-sys-id" class="font-weight-bold">
                                Catalog Item sys_id <span class="text-danger">*</span>
                            </label>
                            <div class="input-group" style="max-width:540px">
                                <input type="text" class="form-control" id="modal-item-sys-id"
                                       style="font-family:monospace"
                                       placeholder="e.g. 1a2b3c4d5e6f7890abcdef1234567890"
                                       maxlength="32">
                                <button class="btn btn-primary" id="btn-modal-fetch" type="button">
                                    <i class="fas fa-sync-alt mr-1"></i> Load Fields
                                </button>
                            </div>
                            <small class="form-text text-muted">The 32-character hexadecimal sys_id of the ServiceNow catalog item.</small>
                        </div>
                    </div>
                    <div id="modal-mapping-table-section" style="display:none">
                        <div class="p-3 border-bottom bg-light">
                            <p class="mb-0 text-muted small">
                                For each ServiceNow variable, choose the REDCap field whose value should be submitted.
                                Variables marked <span class="sn-badge sn-badge-required">Required</span> must be
                                mapped before saving.
                            </p>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0" id="modal-mapping-table">
                                <thead>
                                    <tr>
                                        <th>Variable Name</th>
                                        <th>Display Label</th>
                                        <th>Type</th>
                                        <th>Attributes</th>
                                        <th>REDCap Field</th>
                                    </tr>
                                </thead>
                                <tbody id="modal-mapping-tbody"></tbody>
                            </table>
                        </div>
                        <div class="card-body border-top">
                            <button class="btn btn-sm btn-outline-secondary" id="btn-modal-reload" type="button">
                                <i class="fas fa-sync-alt mr-1"></i> Reload Fields
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Trigger Settings -->
                <div class="card shadow-sm mb-2">
                    <div class="card-header">
                        <i class="fas fa-bolt mr-1"></i> Trigger Settings
                    </div>
                    <div class="card-body">

                        <div class="form-group">
                            <label for="modal-trigger-form-select" class="font-weight-bold">Trigger Form</label>
                            <select class="form-control" id="modal-trigger-form-select" style="max-width:420px">
                                <option value="">— Select a form —</option>
                                <?php foreach ($redcapForms as $form): ?>
                                <option value="<?= htmlspecialchars($form['name'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($form['label'], ENT_QUOTES) ?> [<?= htmlspecialchars($form['name'], ENT_QUOTES) ?>]
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                When this instrument is saved, the module evaluates the criteria below and
                                pushes the record to ServiceNow if all conditions are met.
                            </small>
                        </div>

                        <hr class="my-3">

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="font-weight-bold mb-0">
                                Push Criteria
                                <small class="font-weight-normal text-muted ml-1">(optional — all conditions must pass)</small>
                            </label>
                            <button class="btn btn-sm btn-outline-secondary" id="btn-modal-add-criterion" type="button">
                                <i class="fas fa-plus mr-1"></i> Add Criterion
                            </button>
                        </div>

                        <div id="modal-criteria-container"></div>
                        <p id="modal-criteria-empty" class="text-muted small mb-0">
                            No criteria — the push will fire on every save of the trigger form.
                        </p>

                    </div>
                </div>

            </div><!-- /.modal-body -->

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btn-modal-save">
                    <i class="fas fa-save mr-1"></i> Save Catalog Item
                </button>
            </div>

        </div>
    </div>
</div>


<!-- ── Delete Confirmation Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="delete-confirm-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the catalog item
                   "<strong id="confirm-delete-label"></strong>"?</p>
                <p class="text-danger mb-0"><small><i class="fas fa-exclamation-triangle mr-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete">
                    <i class="fas fa-trash mr-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ── Possible Values Modal ──────────────────────────────────────────────── -->
<div class="modal fade bg-black bg-opacity-50" id="field-values-modal" tabindex="-1"
     aria-labelledby="field-values-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="field-values-modal-title">Possible Values</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="field-values-modal-body"></div>
        </div>
    </div>
</div>

<!-- ── Value Matching Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="value-match-modal" tabindex="-1"
     aria-labelledby="value-match-modal-title" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="value-match-modal-title">Match Values</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="value-match-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btn-value-match-save">
                    <i class="fas fa-check mr-1"></i> Apply
                </button>
            </div>
        </div>
    </div>
</div>

<div id="toast-container" aria-live="polite" aria-atomic="true"></div>

<?= $module->initializeJavascriptModuleObject() ?>
<!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script> -->
<script>
$(document).ready(function () {
    'use strict';

    const module = <?= $module->getJavascriptModuleObjectName() ?>;

    // ── Page-level data from PHP ──────────────────────────────────────────
    let CATALOG_ITEMS = <?= json_encode($catalogItems) ?>;
    const RC_FIELDS   = <?= json_encode($redcapFields) ?>;
    const RC_FORMS_JSON    = <?= json_encode($redcapForms) ?>;
    const RC_FORMS = new Map(RC_FORMS_JSON.map(f => [f.name, f.label]));
    let HAS_PASSWORD  = <?= $hasPassword ? 'true' : 'false' ?>;
    const RECORD_HOME_URL = <?= json_encode($recordHomeUrl) ?>;

    // Structural / display-only types that have no submittable value
    const SKIP_TYPES = ['11', '12', '14', '15', '17', '19', '20', '24', '32'];

    // Fixed choices for types whose options never change
    const FIXED_VALUE_TYPES = {
        '1': [ { label: 'Yes', value: 'true'  },
               { label: 'No',  value: 'false' } ],
        '7': [ { label: 'Checked',   value: 'true'  },
               { label: 'Unchecked', value: 'false' } ]
    };

    // Types that MAY have choices supplied by the API
    const CHOICE_TYPES = ['3', '5', '18', '22'];

    // Quick lookup: RC field name → choices array
    const RC_FIELD_CHOICES = {};
    RC_FIELDS.forEach(function (f) {
        if (Array.isArray(f.choices) && f.choices.length > 0) {
            RC_FIELD_CHOICES[f.name] = f.choices;
        }
    });

    // ── Modal state ───────────────────────────────────────────────────────
    let pendingModalItem    = null;   // { index, label, active, sysId, ... } or { index: null }
    let modalCurrentSnapshot = null; // { sys_id, variables, fetched_at }
    let pendingDeleteIndex  = null;

    // Bootstrap modal instances
    const catalogItemModal  = new bootstrap.Modal(document.getElementById('catalog-item-modal'), { backdrop: 'static', keyboard: false });
    const deleteConfirmModal = new bootstrap.Modal(document.getElementById('delete-confirm-modal'));
    let fieldValuesModal    = null;
    let valueMatchModal     = null;
    let pendingValueMatchRow = null;

    const CRITERION_OPERATORS = [
        { value: 'eq',        label: 'equals' },
        { value: 'neq',       label: 'does not equal' },
        { value: 'contains',  label: 'contains' },
        { value: 'not_empty', label: 'is not empty' },
        { value: 'empty',     label: 'is empty' }
    ];

    // ── Utilities ─────────────────────────────────────────────────────────
    function escHtml(str) {
        return $('<span>').text(String(str == null ? '' : str)).html();
    }

    function showAlert(type, message, autoDismiss) {
        const icons  = { success: 'check-circle', warning: 'exclamation-triangle', danger: 'times-circle', info: 'info-circle' };
        const bgs    = { success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', info: 'bg-info' };
        const titles = { success: 'Success', warning: 'Warning', danger: 'Error', info: 'Info' };

        const icon      = icons[type]  || 'info-circle';
        const bg        = bgs[type]    || 'bg-secondary';
        const title     = titles[type] || 'Notice';
        const whiteText = type !== 'warning';
        const headerCls = bg + (whiteText ? ' text-white' : '');
        const closeCls  = 'btn-close ms-2' + (whiteText ? ' btn-close-white' : '');

        const $toast = $(
            '<div class="toast shadow mb-2" role="alert" aria-live="assertive" aria-atomic="true">' +
            '<div class="toast-header ' + headerCls + '">' +
            '<i class="fas fa-' + icon + ' me-2"></i>' +
            '<strong class="me-auto">' + title + '</strong>' +
            '<button type="button" class="' + closeCls + '" data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>' +
            '<div class="toast-body">' + message + '</div>' +
            '</div>'
        );

        $('#toast-container').append($toast);
        const bsToast = new bootstrap.Toast($toast[0], { autohide: !!autoDismiss, delay: 5000 });
        bsToast.show();
        $toast[0].addEventListener('hidden.bs.toast', function () { $(this).remove(); });
    }

    function getCredentials() {
        const pw = $('#sn-password').val();
        return {
            instanceUrl: $('#instance-url').val().trim(),
            username:    $('#sn-username').val().trim(),
            password:    pw !== '' ? pw : (HAS_PASSWORD ? '__SAVED__' : '')
        };
    }

    // ── REDCap field dropdown builders ────────────────────────────────────
    function buildFormGroupedOptions(selectedValue) {
        const formOrder = [];
        const byForm    = {};
        RC_FIELDS.forEach(function (f) {
            if (!byForm[f.form]) {
                formOrder.push(f.form);
                byForm[f.form] = [];
            }
            byForm[f.form].push(f);
        });
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
        return opts;
    }

    function buildRcDropdown(selectedValue) {
        return '<select class="form-select form-select-sm rc-field-select">' + buildFormGroupedOptions(selectedValue) + '</select>';
    }

    function buildValueInput(fieldName, currentValue, hide) {
        const style   = 'max-width:200px' + (hide ? ';display:none' : '');
        const choices = fieldName ? (RC_FIELD_CHOICES[fieldName] || null) : null;
        if (choices) {
            let opts = '<option value=""></option>';
            choices.forEach(function (c) {
                const val = String(c.value);
                const sel = val === String(currentValue) ? ' selected' : '';
                opts += '<option value="' + escHtml(val) + '"' + sel + '>' + escHtml(c.label) + '</option>';
            });
            return '<select class="form-select form-select-sm mr-2 criterion-value" style="' + style + '">' + opts + '</select>';
        }
        return '<input type="text" class="form-control form-control-sm mr-2 criterion-value" ' +
               'style="' + style + '" placeholder="Value" value="' + escHtml(String(currentValue)) + '">';
    }

    function buildValuesButton(field, typeKey) {
        const hasFixed   = Object.prototype.hasOwnProperty.call(FIXED_VALUE_TYPES, typeKey);
        const hasChoices = CHOICE_TYPES.indexOf(typeKey) !== -1 &&
                           Array.isArray(field.choices) && field.choices.length > 0;
        if (!hasFixed && !hasChoices) return '';
        return '<button class="btn btn-link btn-sm p-0 ms-1 btn-show-values align-middle" ' +
               'title="View possible values"><i class="fas fa-list-ul text-secondary"></i></button>';
    }

    function showFieldValues(field) {
        const typeKey = String(field.type || '');
        const choices = FIXED_VALUE_TYPES[typeKey] ||
                        (Array.isArray(field.choices) ? field.choices : []);
        const title   = (field.label || field.name || 'Field') + ' — Possible Values';

        let body;
        if (choices.length === 0) {
            body = '<p class="text-muted p-3 mb-0">No values available for this field.</p>';
        } else {
            body = '<table class="table table-sm mb-0">' +
                   '<thead class="table-light"><tr><th class="ps-4">Value</th><th>Label</th></tr></thead><tbody>';
            choices.forEach(function (c) {
                const lbl = escHtml(c.label ?? c.text ?? c.display_value ?? '');
                const val = escHtml(String(c.value ?? ''));
                body += '<tr><td class="ps-4"><code>' + val + '</code></td><td>' + lbl + '</td></tr>';
            });
            body += '</tbody></table>';
        }

        document.getElementById('field-values-modal-title').textContent = title;
        document.getElementById('field-values-modal-body').innerHTML    = body;

        if (!fieldValuesModal) {
            fieldValuesModal = new bootstrap.Modal(document.getElementById('field-values-modal'));
        }
        fieldValuesModal.show();
    }

    // ── Value Matching (enum → enum) ──────────────────────────────────────
    // Returns the list of selectable choices for a ServiceNow variable, or [].
    function getSnChoices(field) {
        if (!field) return [];
        // Prefer the variable's real choices when the API supplies them (e.g.
        // yes_no exposes Yes/No values, numeric_scale exposes its scale values).
        // Fall back to the fixed value list only for choice-less types such as
        // check_box (type 7), which submit a boolean true/false.
        if (Array.isArray(field.choices) && field.choices.length > 0) {
            return field.choices;
        }
        const typeKey = String(field.type || '');
        if (Object.prototype.hasOwnProperty.call(FIXED_VALUE_TYPES, typeKey)) {
            return FIXED_VALUE_TYPES[typeKey];
        }
        return [];
    }

    function snChoiceLabel(c) {
        return String(c.label ?? c.text ?? c.display_value ?? c.value ?? '');
    }

    // A mapping row supports value matching only when BOTH the ServiceNow
    // variable and the selected REDCap field expose a fixed set of choices.
    function rowSupportsValueMatching($row) {
        const field   = $row.data('sn-field-data');
        const rcField = $row.find('.rc-field-select').val();
        if (!field || !rcField) return false;
        const snChoices = getSnChoices(field);
        const rcChoices = RC_FIELD_CHOICES[rcField] || [];
        return snChoices.length > 0 && rcChoices.length > 0;
    }

    // Refreshes the "Match values" control for a single mapping row.
    function updateValueMapUi($row) {
        const $wrap = $row.find('.value-map-wrap');
        if (!rowSupportsValueMatching($row)) {
            $wrap.hide().empty();
            $row.removeData('value-map');
            return;
        }
        const map    = $row.data('value-map') || {};
        const mapped = Object.keys(map).filter(function (k) { return map[k] !== ''; }).length;
        const badge  = mapped > 0
            ? ' <span class="value-map-badge">' + mapped + ' matched</span>'
            : '';
        $wrap.html(
            '<button type="button" class="btn btn-link btn-sm p-0 btn-match-values">' +
            '<i class="fas fa-random mr-1"></i>Match values</button>' + badge
        ).show();
    }

    function openValueMatchModal($row) {
        const field     = $row.data('sn-field-data');
        const rcField   = $row.find('.rc-field-select').val();
        const snChoices = getSnChoices(field);
        const rcChoices = RC_FIELD_CHOICES[rcField] || [];
        const existing  = $row.data('value-map') || {};
        const rcLabel   = (RC_FIELDS.find(function (f) { return f.name === rcField; }) || {}).label || rcField;
        const snName    = getServiceNowFieldName(field);

        let snOpts = '<option value="">— Do not send —</option>';
        snChoices.forEach(function (c) {
            const val = String(c.value ?? '');
            snOpts += '<option value="' + escHtml(val) + '">' +
                      escHtml(snChoiceLabel(c)) + ' [' + escHtml(val) + ']</option>';
        });

        let body =
            '<p class="text-muted small mb-3">Choose which ServiceNow value to submit for each ' +
            'REDCap value of <strong>' + escHtml(rcLabel) + '</strong> → <code>' + escHtml(snName) + '</code>.</p>' +
            '<table class="table table-sm table-hover align-middle mb-0" id="value-match-table">' +
            '<thead><tr><th>REDCap Value</th><th style="width:55%">ServiceNow Value</th></tr></thead><tbody>';

        rcChoices.forEach(function (c) {
            const rcVal = String(c.value ?? '');
            body +=
                '<tr data-rc-value="' + escHtml(rcVal) + '">' +
                '<td><div>' + escHtml(String(c.label ?? '')) + '</div>' +
                '<small class="text-muted"><code>' + escHtml(rcVal) + '</code></small></td>' +
                '<td><select class="form-select form-select-sm value-match-select">' + snOpts + '</select></td>' +
                '</tr>';
        });
        body += '</tbody></table>';

        document.getElementById('value-match-modal-title').textContent = 'Match Values — ' + snName;
        document.getElementById('value-match-modal-body').innerHTML    = body;

        // Restore existing selections
        $('#value-match-modal-body tr[data-rc-value]').each(function () {
            const rcVal = String($(this).data('rc-value'));
            if (Object.prototype.hasOwnProperty.call(existing, rcVal)) {
                $(this).find('.value-match-select').val(existing[rcVal]);
            }
        });

        pendingValueMatchRow = $row;
        if (!valueMatchModal) {
            valueMatchModal = new bootstrap.Modal(document.getElementById('value-match-modal'),
                                                  { backdrop: 'static', keyboard: false });
        }
        valueMatchModal.show();
    }

    function applyValueMatch() {
        if (!pendingValueMatchRow) return;
        const map = {};
        $('#value-match-modal-body tr[data-rc-value]').each(function () {
            const rcVal = String($(this).data('rc-value'));
            const snVal = $(this).find('.value-match-select').val();
            if (snVal) map[rcVal] = snVal;
        });
        pendingValueMatchRow.data('value-map', map);
        updateValueMapUi(pendingValueMatchRow);
        if (valueMatchModal) valueMatchModal.hide();
        pendingValueMatchRow = null;
    }

    $('#btn-value-match-save').on('click', applyValueMatch);

    $('#modal-mapping-tbody').on('click', '.btn-match-values', function () {
        openValueMatchModal($(this).closest('tr'));
    });

    // When the REDCap field selection changes, the prior value matches no longer
    // apply, so reset them and refresh the value-matching control. Select2 does
    // not reliably bubble a delegated `change` event on manual selection here, so
    // listen for the Select2-specific events (mirrors the criteria builder).
    $('#modal-mapping-tbody').on('select2:select select2:clear', '.rc-field-select', function () {
        const $row = $(this).closest('tr');
        $row.removeData('value-map');
        updateValueMapUi($row);
    });

    // ── Catalog Items Table ───────────────────────────────────────────────
    function renderCatalogItemsTable() {
        const $tbody = $('#catalog-items-tbody').empty();

        if (!CATALOG_ITEMS || CATALOG_ITEMS.length === 0) {
            $('#catalog-items-empty').show();
            $('#catalog-items-table').hide();
            return;
        }

        $('#catalog-items-empty').hide();
        $('#catalog-items-table').show();

        CATALOG_ITEMS.forEach(function (item, index) {
            // const formObj   = RC_FORMS.find(function (f) { return f.name === item.triggerForm; });
            const formLabel = RC_FORMS.get(item.triggerForm) || item.triggerForm || '—';
            const statusBadge = item.active
                ? '<span class="badge badge-success" style="background:#28a745;color:#fff;padding:3px 8px;border-radius:3px">Active</span>'
                : '<span class="badge badge-secondary" style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px">Inactive</span>';

            const $tr = $(
                '<tr>' +
                '<td class="align-middle font-weight-bold ps-3">' + escHtml(item.label || '(no label)') + '</td>' +
                '<td class="align-middle">' + statusBadge + '</td>' +
                '<td class="align-middle"><small class="text-muted">' + escHtml(formLabel) + '</small></td>' +
                '<td class="align-middle text-right pe-3">' +
                  '<button class="btn btn-sm btn-outline-primary mr-1 btn-edit-item" data-index="' + index + '" title="Edit">' +
                  '<i class="fas fa-edit mr-1"></i>Edit</button>' +
                  '<button class="btn btn-sm btn-outline-danger btn-delete-item" data-index="' + index + '" data-label="' + escHtml(item.label || '') + '" title="Delete">' +
                  '<i class="fas fa-trash"></i></button>' +
                '</td>' +
                '</tr>'
            );
            $tbody.append($tr);
        });
    }

    // Initial render
    renderCatalogItemsTable();

    // ── Open Catalog Item Modal ───────────────────────────────────────────
    function openCatalogItemModal(index) {
        resetModal();

        if (index !== null && index !== undefined && CATALOG_ITEMS[index] !== undefined) {
            const item = CATALOG_ITEMS[index];
            pendingModalItem = Object.assign({}, item, { index: index });
            $('#catalog-item-modal-title').text('Edit Catalog Item');
            $('#modal-item-label').val(item.label || '');
            $('#modal-item-active').prop('checked', !!item.active);
            $('#modal-item-sys-id').val(item.sysId || '');
        } else {
            pendingModalItem = { index: null };
            $('#catalog-item-modal-title').text('Add Catalog Item');
            $('#modal-item-active').prop('checked', true);
        }

        catalogItemModal.show();
    }

    function resetModal() {
        // Destroy any existing Select2 instances inside the modal
        $('#catalog-item-modal .select2-hidden-accessible').each(function () {
            try { $(this).select2('destroy'); } catch (e) {}
        });

        $('#modal-item-label').val('');
        $('#modal-item-active').prop('checked', true);
        $('#modal-item-sys-id').val('');
        $('#modal-mapping-table-section').hide();
        $('#modal-mapping-tbody').empty();
        $('#modal-mapping-count').hide().text('');
        $('#modal-criteria-container').empty();
        $('#modal-criteria-empty').show();
        modalCurrentSnapshot = null;
    }

    // After modal fully shown: init Select2, restore saved values, render table/criteria
    $('#catalog-item-modal').on('shown.bs.modal', function () {
        const item = pendingModalItem;

        // Trigger form Select2
        $('#modal-trigger-form-select').select2({
            width:          '100%',
            placeholder:    '— Select a form —',
            allowClear:     true,
            dropdownParent: $('#catalog-item-modal')
        });

        if (item && item.triggerForm) {
            $('#modal-trigger-form-select').val(item.triggerForm).trigger('change');
        }

        // Criteria
        if (item && item.criteria && item.criteria.length > 0) {
            renderModalCriteria(item.criteria);
        }

        // Mapping table from snapshot
        if (item && item.snapshot && Array.isArray(item.snapshot.variables) && item.snapshot.variables.length > 0) {
            modalCurrentSnapshot = item.snapshot;
            renderMappingTable(item.snapshot.variables, item.fieldMappings || {}, item.valueMappings || {});
        }
    });

    $('#catalog-item-modal').on('hidden.bs.modal', function () {
        resetModal();
        pendingModalItem = null;
    });

    // Edit / Add buttons
    $('#btn-add-catalog-item').on('click', function () {
        openCatalogItemModal(null);
    });

    $('#catalog-items-tbody').on('click', '.btn-edit-item', function () {
        openCatalogItemModal(parseInt($(this).data('index'), 10));
    });

    // ── Mapping Table (inside modal) ──────────────────────────────────────
    function getServiceNowFieldName(field) {
        return String((field && (field.name || field.variable_name || field.question_name)) || '');
    }

    function flattenServiceNowFields(fields) {
        const flat = [];

        function walk(list, parentLabel) {
            (Array.isArray(list) ? list : []).forEach(function (field) {
                const children = Array.isArray(field && field.children) ? field.children : [];
                const currentLabel = String((field && (field.label || field.display_label || getServiceNowFieldName(field))) || '');

                if (children.length > 0) {
                    const nextParentLabel = parentLabel || currentLabel;
                    walk(children, nextParentLabel);
                    return;
                }

                const item = Object.assign({}, field || {});
                if (parentLabel) {
                    item.__parentLabel = parentLabel;
                }
                flat.push(item);
            });
        }

        walk(fields, '');
        return flat;
    }

    function renderMappingTable(fields, savedMap, savedValueMap) {
        savedMap = savedMap || {};
        savedValueMap = savedValueMap || {};
        const normalizedFields = flattenServiceNowFields(fields);
        const $tbody = $('#modal-mapping-tbody').empty();
        let rowCount = 0;

        normalizedFields.forEach(function (field) {
            const name     = getServiceNowFieldName(field);
            const label    = String(field.label || field.display_label || name);
            const typeKey  = String(field.type || '');
            const type     = String(field.display_type || typeKey);
            const required = String(field.mandatory || field.required || 'false').toLowerCase() === 'true';
            const active   = String(field.active || 'true').toLowerCase() !== 'false';
            const parentLabel = String(field.__parentLabel || '');

            if (!name) return;

            if (SKIP_TYPES.indexOf(typeKey) !== -1 || !active) return;

            const savedRcField = savedMap[name] || '';
            let badges = '';
            if (required) badges += '<span class="sn-badge sn-badge-required">Required</span>';

            const valuesBtn = buildValuesButton(field, typeKey);
            const attrsCell = (badges + (valuesBtn ? ' ' + valuesBtn : '')).trim()
                           || '<span class="text-muted">—</span>';
            const labelCell = escHtml(label) + (parentLabel
                ? '<div><small class="text-muted">Set: ' + escHtml(parentLabel) + '</small></div>'
                : '');

            const $tr = $(
                '<tr data-sn-field="' + escHtml(name) + '"' +
                    ' data-required="' + required + '">' +
                '<td><code>' + escHtml(name) + '</code></td>' +
                '<td>' + labelCell + '</td>' +
                '<td><small class="text-muted">' + escHtml(type) + '</small></td>' +
                '<td>' + attrsCell + '</td>' +
                '<td>' + buildRcDropdown(savedRcField) +
                    '<div class="value-map-wrap mt-1" style="display:none"></div></td>' +
                '</tr>'
            );
            $tr.data('sn-field-data', field);
            $tbody.append($tr);
            rowCount++;
        });

        if (rowCount === 0) {
            $tbody.append('<tr><td colspan="5" class="text-center text-muted py-3">' +
                          'No mappable variables found for this catalog item.</td></tr>');
        }

        $('#modal-mapping-count').text(rowCount + ' variable' + (rowCount !== 1 ? 's' : '')).show();
        $('#modal-mapping-table-section').slideDown(200);

        // Init Select2 on new rows
        $tbody.find('.rc-field-select').select2({
            width:          '100%',
            placeholder:    '\u2014 Do not map \u2014',
            allowClear:     true,
            dropdownParent: $('#catalog-item-modal')
        });

        // Restore saved mappings
        $tbody.find('tr[data-sn-field]').each(function () {
            const $row       = $(this);
            const snField    = $row.data('sn-field');
            const savedValue = savedMap[snField] || null;
            $row.find('.rc-field-select').val(savedValue).trigger('change');
            // Apply saved value matches after the field selection (the change
            // handler above clears value-map, so restore it here).
            if (savedValueMap[snField] && typeof savedValueMap[snField] === 'object') {
                $row.data('value-map', savedValueMap[snField]);
            }
            updateValueMapUi($row);
        });
    }

    function collectMappings() {
        const map = {};
        $('#modal-mapping-tbody tr[data-sn-field]').each(function () {
            const snField = $(this).data('sn-field');
            const rcField = $(this).find('.rc-field-select').val();
            map[snField] = rcField || '';
        });
        return map;
    }

    function collectValueMappings() {
        const map = {};
        $('#modal-mapping-tbody tr[data-sn-field]').each(function () {
            const $row    = $(this);
            const rcField = $row.find('.rc-field-select').val();
            const vmap    = $row.data('value-map');
            // Only persist value matches for rows that are mapped and still
            // support enum-to-enum matching.
            if (rcField && vmap && rowSupportsValueMatching($row)) {
                const kept = {};
                Object.keys(vmap).forEach(function (k) {
                    if (vmap[k] !== '') kept[k] = vmap[k];
                });
                if (Object.keys(kept).length > 0) {
                    map[$row.data('sn-field')] = kept;
                }
            }
        });
        return map;
    }

    function validateMappings() {
        const errors = [];
        $('#modal-mapping-tbody tr[data-required="true"]').each(function () {
            const rcField = $(this).find('.rc-field-select').val();
            if (!rcField) errors.push($(this).data('sn-field'));
        });
        return errors;
    }

    // Possible values modal (from mapping table rows)
    $('#modal-mapping-tbody').on('click', '.btn-show-values', function () {
        const field = $(this).closest('tr').data('sn-field-data');
        if (field) showFieldValues(field);
    });

    // ── Load Fields ───────────────────────────────────────────────────────
    function fetchModalFields() {
        const creds = getCredentials();
        const sysId = $('#modal-item-sys-id').val().trim();

        if (!creds.instanceUrl || !creds.username || !creds.password) {
            showAlert('warning', 'Please fill in all Connection Settings before loading fields.');
            return;
        }
        if (!sysId) {
            showAlert('warning', 'Please enter the catalog item sys_id.');
            return;
        }

        const $btn = $('#btn-modal-fetch').prop('disabled', true)
                                         .html('<i class="fas fa-spinner fa-spin mr-1"></i> Loading…');
        $('#modal-mapping-table-section').hide();
        $('#modal-mapping-count').hide();

        module.ajax('getServiceNowFields', {
            instanceUrl: creds.instanceUrl,
            username:    creds.username,
            password:    creds.password,
            sysId:       sysId
        }).then(function (resp) {
            const fields = resp && resp.data;
            if (resp.success && Array.isArray(fields)) {
                if (fields.length === 0) {
                    showAlert('warning',
                        'No variables were returned for this catalog item. ' +
                        'Verify the sys_id and confirm the item has variables configured.');
                } else {
                    modalCurrentSnapshot = {
                        sys_id:     sysId,
                        variables:  fields,
                        fetched_at: new Date().toISOString()
                    };
                    // Keep existing mappings if same sys_id, else start fresh
                    const existingSysId = pendingModalItem && pendingModalItem.sysId;
                    const sameItem  = existingSysId === sysId;
                    const savedMap  = (sameItem && pendingModalItem.fieldMappings) ? pendingModalItem.fieldMappings : {};
                    const savedVMap = (sameItem && pendingModalItem.valueMappings) ? pendingModalItem.valueMappings : {};
                    renderMappingTable(fields, savedMap, savedVMap);
                }
            } else {
                showAlert('danger', 'Could not load fields: ' + escHtml((resp && resp.error) || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Request failed. Check your connection settings and try again.');
        }).finally(function () {
            $btn.prop('disabled', false).html('<i class="fas fa-sync-alt mr-1"></i> Load Fields');
        });
    }

    $('#btn-modal-fetch').on('click', fetchModalFields);
    $('#btn-modal-reload').on('click', fetchModalFields);

    // ── Criteria Builder (modal) ──────────────────────────────────────────
    function buildCriterionRow(criterion) {
        criterion = criterion || {};
        const field    = criterion.field    || '';
        const operator = criterion.operator || 'eq';
        const value    = criterion.value    || '';

        let opOpts = '';
        CRITERION_OPERATORS.forEach(function (op) {
            const sel = op.value === operator ? ' selected' : '';
            opOpts += '<option value="' + op.value + '"' + sel + '>' + op.label + '</option>';
        });

        const hideValue = operator === 'not_empty' || operator === 'empty';

        return '<div class="d-flex align-items-center mb-2 criterion-row">' +
            '<div class="criterion-field-wrap mr-2" style="flex:0 1 280px;min-width:160px">' +
            '<select class="form-select form-select-sm criterion-field">' + buildFormGroupedOptions(field) + '</select>' +
            '</div>' +
            '<select class="form-select form-select-sm mr-2 criterion-operator" style="max-width:175px">' + opOpts + '</select>' +
            buildValueInput(field, value, hideValue) +
            '<button class="btn btn-sm btn-outline-danger btn-remove-criterion flex-shrink-0 ml-2" type="button" title="Remove">' +
            '<i class="fas fa-times"></i></button></div>';
    }

    function renderModalCriteria(criteria) {
        const $container = $('#modal-criteria-container').empty();
        if (!criteria || criteria.length === 0) {
            $('#modal-criteria-empty').show();
            return;
        }
        $('#modal-criteria-empty').hide();

        const savedFields = [];
        criteria.forEach(function (c) {
            savedFields.push(c.field || '');
            $container.append(buildCriterionRow(c));
        });

        // Init Select2 on criterion field selects
        $container.find('.criterion-field').select2({
            width:          '100%',
            placeholder:    '— Select field —',
            allowClear:     true,
            dropdownParent: $('#catalog-item-modal')
        });

        // Restore selected values (Select2 needs explicit .val().trigger('change'))
        $container.find('.criterion-row').each(function (i) {
            const val = savedFields[i];
            if (val) {
                $(this).find('.criterion-field').val(val).trigger('change');
            }
        });
    }

    function collectModalCriteria() {
        const criteria = [];
        $('#modal-criteria-container .criterion-row').each(function () {
            const field    = $(this).find('.criterion-field').val();
            const operator = $(this).find('.criterion-operator').val();
            const value    = $(this).find('.criterion-value').val();
            if (field) {
                criteria.push({ field: field, operator: operator || 'eq', value: value || '' });
            }
        });
        return criteria;
    }

    $('#btn-modal-add-criterion').on('click', function () {
        $('#modal-criteria-empty').hide();
        const $row = $(buildCriterionRow({}));
        $('#modal-criteria-container').append($row);
        $row.find('.criterion-field').select2({
            width:          '100%',
            placeholder:    '— Select field —',
            allowClear:     true,
            dropdownParent: $('#catalog-item-modal')
        });
    });

    $('#modal-criteria-container').on('click', '.btn-remove-criterion', function () {
        $(this).closest('.criterion-row').remove();
        if ($('#modal-criteria-container .criterion-row').length === 0) {
            $('#modal-criteria-empty').show();
        }
    });

    $('#modal-criteria-container').on('change', '.criterion-operator', function () {
        const noValue = $(this).val() === 'not_empty' || $(this).val() === 'empty';
        $(this).closest('.criterion-row').find('.criterion-value').toggle(!noValue);
    });

    $('#modal-criteria-container').on('select2:select select2:clear', '.criterion-field', function () {
        const $row      = $(this).closest('.criterion-row');
        const fieldName = $(this).val() || '';
        const operator  = $row.find('.criterion-operator').val();
        const hideValue = operator === 'not_empty' || operator === 'empty';
        $row.find('.criterion-value').replaceWith($(buildValueInput(fieldName, '', hideValue)));
    });

    // ── Save Catalog Item ─────────────────────────────────────────────────
    $('#btn-modal-save').on('click', function () {
        const label       = $('#modal-item-label').val().trim();
        const active      = $('#modal-item-active').is(':checked');
        const sysId       = $('#modal-item-sys-id').val().trim();
        const triggerForm = $('#modal-trigger-form-select').val() || '';

        if (!label) {
            showAlert('warning', 'Please enter a label for this catalog item.');
            return;
        }
        if (!sysId) {
            showAlert('warning', 'Please enter the catalog item sys_id.');
            return;
        }

        // Validate required mappings (only if mapping table is shown)
        if ($('#modal-mapping-table-section').is(':visible')) {
            const errors = validateMappings();
            if (errors.length > 0) {
                showAlert('warning',
                    'The following required ServiceNow variables are not mapped: ' +
                    '<strong>' + errors.map(escHtml).join(', ') + '</strong>.');
                return;
            }
        }

        const fieldMappings = collectMappings();
        const valueMappings = collectValueMappings();
        const criteria      = collectModalCriteria();
        const itemIndex     = pendingModalItem ? pendingModalItem.index : null;

        const $btn = $(this).prop('disabled', true)
                            .html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving…');

        module.ajax('saveCatalogItem', {
            index:         itemIndex,
            label:         label,
            active:        active,
            sysId:         sysId,
            fieldMappings: fieldMappings,
            valueMappings: valueMappings,
            triggerForm:   triggerForm,
            criteria:      criteria,
            snapshot:      modalCurrentSnapshot
        }).then(function (resp) {
            if (resp.success) {
                const newItem = {
                    label:         label,
                    active:        active,
                    sysId:         sysId,
                    fieldMappings: fieldMappings,
                    valueMappings: valueMappings,
                    triggerForm:   triggerForm,
                    criteria:      criteria,
                    snapshot:      modalCurrentSnapshot
                };
                CATALOG_ITEMS[resp.index] = newItem;
                renderCatalogItemsTable();
                catalogItemModal.hide();
                showAlert('success', 'Catalog item saved successfully.', true);
            } else {
                showAlert('danger', 'Save failed: ' + escHtml((resp && resp.error) || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Save request failed. Please try again.');
        }).finally(function () {
            $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Catalog Item');
        });
    });

    // ── Delete Catalog Item ───────────────────────────────────────────────
    $('#catalog-items-tbody').on('click', '.btn-delete-item', function () {
        pendingDeleteIndex = parseInt($(this).data('index'), 10);
        const label = $(this).data('label') || 'this catalog item';
        $('#confirm-delete-label').text(label);
        deleteConfirmModal.show();
    });

    $('#btn-confirm-delete').on('click', function () {
        if (pendingDeleteIndex === null) return;
        const idx  = pendingDeleteIndex;
        const $btn = $(this).prop('disabled', true)
                            .html('<i class="fas fa-spinner fa-spin mr-1"></i> Deleting…');

        module.ajax('deleteCatalogItem', { index: idx }).then(function (resp) {
            if (resp.success) {
                CATALOG_ITEMS.splice(idx, 1);
                renderCatalogItemsTable();
                deleteConfirmModal.hide();
                showAlert('success', 'Catalog item deleted.', true);
            } else {
                showAlert('danger', 'Delete failed: ' + escHtml((resp && resp.error) || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Delete request failed. Please try again.');
        }).finally(function () {
            $btn.prop('disabled', false).html('<i class="fas fa-trash mr-1"></i> Delete');
            pendingDeleteIndex = null;
        });
    });

    $('#delete-confirm-modal').on('hidden.bs.modal', function () {
        pendingDeleteIndex = null;
    });

    // ── Connection Settings ───────────────────────────────────────────────
    $('#btn-test').on('click', function () {
        const creds = getCredentials();
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
                    escHtml((resp && resp.error) || 'Connection failed') + '</span>'
                );
                $('#btn-save-connection').prop('disabled', true);
            }
        }).catch(function () {
            $('#test-status').html(
                '<span class="text-danger"><i class="fas fa-times-circle mr-1"></i>Request error</span>'
            );
            $('#btn-save-connection').prop('disabled', true);
        }).finally(function () {
            $btn.prop('disabled', false).html('<i class="fas fa-satellite-dish mr-1"></i> Test Connection');
        });
    });

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
                showAlert('danger', 'Save failed: ' + escHtml((resp && resp.error) || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Save request failed. Please try again.');
        }).finally(function () {
            $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Connection Settings');
        });
    });

    // ── Password visibility toggle ────────────────────────────────────────
    $('#btn-toggle-pw').on('click', function () {
        const $input = $('#sn-password');
        const show   = $input.attr('type') === 'password';
        $input.attr('type', show ? 'text' : 'password');
        $('#pw-eye-icon').toggleClass('fa-eye fa-eye-slash');
    });

    // ── Submission Log (server-side DataTable) ────────────────────────────
    let submissionLogTable = null;

    function buildRecordLink(record) {
        if (!record) return '';
        const url = RECORD_HOME_URL + '&id=' + encodeURIComponent(record);
        return '<a href="' + escHtml(url) + '" target="_blank" rel="noopener">' + escHtml(record) + '</a>';
    }

    function formLabelFor(name) {
        return RC_FORMS.get(name) || name || '—';
    }

    function initSubmissionLogTable() {
        if (submissionLogTable) {
            return;
        }
        submissionLogTable = $('#submission-log-table').DataTable({
            serverSide:  true,   // pagination/search/sort handled in PHP → scales to huge logs
            processing:  true,
            deferRender: true,
            order:       [[0, 'desc']],
            pageLength:  25,
            lengthMenu:  [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: function (data, callback) {
                module.ajax('getSubmissionLog', data).then(function (json) {
                    callback(json);
                }).catch(function () {
                    callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                    showAlert('danger', 'Failed to load the submission log.');
                });
            },
            columns: [
                { data: 'timestamp',     render: function (d, type) { return type === 'display' ? escHtml(d || '') : (d || ''); } },
                { data: 'record',        render: function (d, type) { return type === 'display' ? buildRecordLink(d) : (d || ''); } },
                { data: 'catalog_label', render: function (d, type) { return type === 'display' ? escHtml(d || '') : (d || ''); } },
                { data: 'instrument',    render: function (d, type) { return type === 'display' ? escHtml(formLabelFor(d)) : (d || ''); } },
                { data: 'request_number',   render: function (d, type, row) {
                    if (type !== 'display') { return d || ''; }
                    if (!d) { return ''; }
                    if (row && row.request_url) {
                        return '<a href="' + escHtml(row.request_url) + '" target="_blank" rel="noopener">' + escHtml(d) + '</a>';
                    }
                    return escHtml(d);
                } }
            ],
            language: {
                emptyTable:  'No RITM submissions have been logged yet.',
                zeroRecords: 'No matching submissions found.'
            }
        });
    }

    // Build the table the first time the Submission Log tab is opened; on later
    // visits just re-adjust column widths (DataTables miscalculates while hidden).
    $('#tab-log-btn').on('shown.bs.tab', function () {
        if (submissionLogTable) {
            submissionLogTable.columns.adjust();
        } else {
            initSubmissionLogTable();
        }
    });

    $('#btn-refresh-log').on('click', function () {
        if (submissionLogTable) {
            submissionLogTable.ajax.reload(null, false);
        }
    });

});
</script>
