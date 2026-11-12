<?php
/**
 * ServiceNow Catalog Integration – Configuration Page
 *
 * @var \YaleREDCap\REDCapServiceNowCatalogIntegration\REDCapServiceNowCatalogIntegration $module
 */

defined('APP_PATH_WEBROOT') || die('Direct access not permitted.');

if (!$module->framework->isSuperUser()) {
    exit($module->framework->tt('This page is only accessible to REDCap administrators.'));
}

$pid = (int) $module->getProjectId();

$recordHomeUrl = APP_PATH_WEBROOT . 'DataEntry/record_home.php?pid=' . $pid;


// ─── Page Data ─────────────────────────────────────────────────────────────────
// Saved connection settings. The password and client secret are never sent to
// the page, only whether one is saved.
$connection            = $module->getConnectionSummary();
$savedUrl              = $connection['instanceUrl'];
$savedUsername         = $connection['username'];
$hasPassword           = $connection['hasPassword'];
$savedAuthType         = $connection['authType'];
$savedGrantType        = $connection['grantType'];
$savedClientId         = $connection['clientId'];
$hasClientSecret       = $connection['hasClientSecret'];
$usesUserCredentials   = $savedAuthType === 'basic' || $savedGrantType === 'password';
$hasConnectionSettings = $connection['hasAnySettings'];
$connectionSaved       = $connection['isComplete'];
$savedBaseUrl          = $connection['instanceBaseUrl'];
// Same shape as currentConnection() in the page's JavaScript.
$savedConnection = [
    'instanceUrl' => $savedUrl,
    'authType'    => $savedAuthType,
    'grantType'   => $savedAuthType === 'oauth' ? $savedGrantType : '',
    'clientId'    => $savedAuthType === 'oauth' ? $savedClientId : '',
    'username'    => $usesUserCredentials ? $savedUsername : '',
];

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
        'name'       => $fieldInfo['field_name'],
        'label'      => strip_tags($fieldInfo['field_label']),
        'type'       => $fieldInfo['field_type'],
        'validation' => (string) ($fieldInfo['text_validation_type_or_show_slider_number'] ?? ''),
        'form'       => $fieldInfo['form_name'],
        'choices'    => $fieldChoices,
    ];
}

$recordIdField = (string) \REDCap::getRecordIdField();

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
    .rc-field-select { min-width: 230px; max-width: 320px; }
    .select2-container { min-width: 230px; }
    /* Cap width only on the field-mapping dropdowns so the clear (×) stays reachable */
    #modal-mapping-tbody .select2-container { max-width: 320px; }
    .select2-container .select2-selection--single { height: 31px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 29px;
        padding-right: 24px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 29px; }
    /* Keep the clear (×) button clear of the truncated text and the dropdown arrow */
    .select2-container--default .select2-selection--single .select2-selection__clear { margin-right: 4px; }
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
    #submission-log-table td.sn-log-reason { min-width: 240px; white-space: normal; word-break: break-word; }
    #submission-log-table .sn-log-status { white-space: nowrap; }
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
                <?php // autocomplete="off" (here and on the inputs below): the browser must not restore unsaved values when the page is revisited. ?>
                <input type="url" class="form-control sn-connection-input" id="instance-url"
                       style="max-width:540px"
                       placeholder="https://your-instance.service-now.com"
                       autocomplete="off" spellcheck="false"
                       aria-describedby="instance-url-help"
                       value="<?= htmlspecialchars($savedUrl, ENT_QUOTES) ?>">
                <small class="form-text text-muted" id="instance-url-help">Must begin with <code>https://</code>.</small>
            </div>

            <div class="form-group">
                <span class="font-weight-bold d-block mb-1" id="sn-auth-type-label">Authentication Method</span>
                <div role="radiogroup" aria-labelledby="sn-auth-type-label" aria-describedby="sn-auth-type-help">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input sn-connection-input" type="radio" name="sn-auth-type"
                               id="sn-auth-type-basic" value="basic" autocomplete="off"<?= $savedAuthType === 'basic' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="sn-auth-type-basic">Basic authentication</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input sn-connection-input" type="radio" name="sn-auth-type"
                               id="sn-auth-type-oauth" value="oauth" autocomplete="off"<?= $savedAuthType === 'oauth' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="sn-auth-type-oauth">OAuth 2.0</label>
                    </div>
                </div>
                <small class="form-text text-muted" id="sn-auth-type-help">
                    Basic authentication sends the service account's username and password with every request.
                    OAuth 2.0 exchanges credentials for short-lived access tokens, which the module reuses and renews automatically.
                    Only the credentials used by the selected method are kept when you save.
                </small>
            </div>

            <div id="sn-oauth-settings"<?= $savedAuthType === 'oauth' ? '' : ' style="display:none"' ?>>
                <div class="form-group">
                    <label for="sn-oauth-grant-type" class="font-weight-bold">Grant Type</label>
                    <select class="form-select sn-connection-input" id="sn-oauth-grant-type" style="max-width:540px"
                            autocomplete="off" aria-describedby="sn-grant-help-<?= $savedGrantType ?>">
                        <option value="client_credentials"<?= $savedGrantType === 'client_credentials' ? ' selected' : '' ?>>Client credentials grant (recommended)</option>
                        <option value="password"<?= $savedGrantType === 'password' ? ' selected' : '' ?>>Password grant</option>
                    </select>
                    <small class="form-text text-muted sn-grant-help" data-grant="client_credentials" id="sn-grant-help-client_credentials"
                           <?= $savedGrantType === 'client_credentials' ? '' : 'style="display:none"' ?>>
                        Uses only the OAuth client ID and secret, so no username or password is stored. Tokens act as the
                        <em>OAuth Application User</em> set on the OAuth application in ServiceNow. The client credentials
                        grant must be enabled on the instance. See the module documentation for the ServiceNow setup steps.
                    </small>
                    <small class="form-text text-muted sn-grant-help" data-grant="password" id="sn-grant-help-password"
                           <?= $savedGrantType === 'password' ? '' : 'style="display:none"' ?>>
                        Uses the OAuth client ID and secret plus the service account's username and password; tokens are
                        issued to that service account. ServiceNow discourages this grant for new integrations, and some
                        instances turn it off. See the module documentation for the ServiceNow setup steps.
                    </small>
                </div>
                <div class="row">
                    <div class="col-md-4 form-group">
                        <label for="sn-client-id" class="font-weight-bold">Client ID</label>
                        <input type="text" class="form-control sn-connection-input" id="sn-client-id"
                               autocomplete="off" spellcheck="false"
                               value="<?= htmlspecialchars($savedClientId, ENT_QUOTES) ?>">
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="sn-client-secret" class="font-weight-bold">Client Secret</label>
                        <div class="input-group">
                            <input type="password" class="form-control sn-connection-input" id="sn-client-secret"
                                   autocomplete="new-password" spellcheck="false"
                                   <?= $hasClientSecret ? 'aria-describedby="sn-client-secret-saved-note"' : '' ?>
                                   placeholder="<?= $hasClientSecret ? 'Saved — enter to change' : 'Enter client secret' ?>">
                            <button class="btn btn-outline-secondary" type="button" id="btn-toggle-client-secret"
                                    title="Show / hide client secret" aria-pressed="false">
                                <i class="fas fa-eye" id="client-secret-eye-icon"></i>
                            </button>
                        </div>
                        <small class="form-text text-muted" id="sn-client-secret-saved-note"
                               <?= $hasClientSecret ? '' : 'style="display:none"' ?>>A client secret is saved. Leave blank to keep it.</small>
                    </div>
                </div>
            </div>

            <div class="row" id="sn-user-credentials"<?= $usesUserCredentials ? '' : ' style="display:none"' ?>>
                <div class="col-md-4 form-group">
                    <label for="sn-username" class="font-weight-bold">Username</label>
                    <?php // Not "username"/"current-password": the browser must not fill in the administrator's own REDCap login. ?>
                    <input type="text" class="form-control sn-connection-input" id="sn-username"
                           autocomplete="off" spellcheck="false"
                           placeholder="service_account_name"
                           value="<?= htmlspecialchars($savedUsername, ENT_QUOTES) ?>">
                </div>
                <div class="col-md-4 form-group">
                    <label for="sn-password" class="font-weight-bold">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control sn-connection-input" id="sn-password"
                               autocomplete="new-password"
                               <?= $hasPassword ? 'aria-describedby="sn-password-saved-note"' : '' ?>
                               placeholder="<?= $hasPassword ? 'Saved — enter to change' : 'Enter password' ?>">
                        <button class="btn btn-outline-secondary" type="button" id="btn-toggle-pw"
                                title="Show / hide password" aria-pressed="false">
                            <i class="fas fa-eye" id="pw-eye-icon"></i>
                        </button>
                    </div>
                    <small class="form-text text-muted" id="sn-password-saved-note"
                           <?= $hasPassword ? '' : 'style="display:none"' ?>>A password is saved. Leave blank to keep it.</small>
                </div>
            </div>

            <div class="d-flex align-items-center flex-wrap" style="gap:0.5rem">
                <button class="btn btn-outline-primary" id="btn-test" type="button">
                    <i class="fas fa-satellite-dish mr-1"></i> Test Connection
                </button>
                <button class="btn btn-outline-success" id="btn-save-connection" type="button" disabled>
                    <i class="fas fa-save mr-1"></i> Save Connection Settings
                </button>
                <button class="btn btn-outline-danger" id="btn-delete-connection" type="button"
                        style="<?= $hasConnectionSettings ? '' : 'display:none' ?>">
                    <i class="fas fa-trash mr-1"></i> Delete Connection Settings
                </button>
                <span id="test-status" role="status"></span>
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
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2" style="gap:0.5rem">
                    <p class="text-muted small mb-0">
                        Every ServiceNow request item (RITM) submission attempt is recorded below. That includes
                        failed submissions, and follow-up steps that failed after a request was created (attachment
                        uploads, or saving results back to the record), each with the reason for the failure.
                    </p>
                    <div class="d-flex align-items-center" style="gap:0.5rem">
                        <label for="log-status-filter" class="small text-muted mb-0 text-nowrap">Show:</label>
                        <select id="log-status-filter" class="form-select form-select-sm" style="width:auto">
                            <option value="all">All submissions</option>
                            <option value="success">Successful only</option>
                            <option value="failed">Failures only</option>
                        </select>
                    </div>
                </div>
                <table id="submission-log-table" class="hover w-100">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Status</th>
                            <th>Record</th>
                            <th>Catalog Item</th>
                            <th>Trigger Form</th>
                            <th>Request Number</th>
                            <th>Request Item</th>
                            <th>Failure Reason</th>
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

                <!-- Attachments -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <i class="fas fa-paperclip mr-1"></i> Attachments
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-0">
                            <label for="modal-attachment-fields" class="font-weight-bold">
                                File Upload Fields
                            </label>
                            <select class="form-control" id="modal-attachment-fields" multiple></select>
                            <small class="form-text text-muted">
                                Files uploaded to the selected REDCap fields will be attached to the
                                request item (RITM) after it is created. Only <em>File Upload</em> fields
                                are listed.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Trigger Settings -->
                <div class="card shadow-sm mb-4">
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
                                pushes the record to ServiceNow if any condition group is satisfied.
                            </small>
                        </div>

                        <hr class="my-3">

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="font-weight-bold mb-0">
                                Push Criteria
                                <small class="font-weight-normal text-muted ml-1">(optional)</small>
                            </label>
                            <button class="btn btn-sm btn-outline-secondary" id="btn-modal-add-group" type="button">
                                <i class="fas fa-plus mr-1"></i> Add Condition Group
                            </button>
                        </div>
                        <small class="form-text text-muted mb-2">
                            Conditions within a group must <strong>all</strong> match (AND). If there is more than
                            one group, the push fires when <strong>any</strong> group matches (OR).
                        </small>

                        <div id="modal-criteria-container"></div>
                        <p id="modal-criteria-empty" class="text-muted small mb-0">
                            No criteria — the push will fire on every save of the trigger form.
                        </p>

                    </div>
                </div>

                <!-- Save Results to REDCap -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <i class="fas fa-reply mr-1"></i> Save Results to REDCap
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            After each submission, the module can save the ServiceNow request details back to the
                            record. You can then pipe them into the survey completion text, the confirmation email, or
                            an alert, e.g. <code>[sn_request_number]</code>. All three are optional. Only text fields
                            without validation and notes fields are listed (the record ID field is excluded). To keep the
                            fields off the survey itself, add <code>@HIDDEN-SURVEY</code> to each one.
                        </p>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="modal-result-request-number" class="font-weight-bold">Request Number</label>
                                <select class="form-control result-field-select" id="modal-result-request-number"
                                        data-result-key="requestNumber"></select>
                                <small class="form-text text-muted">e.g. REQ0012345</small>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="modal-result-ritm-number" class="font-weight-bold">Request Item (RITM) Number</label>
                                <select class="form-control result-field-select" id="modal-result-ritm-number"
                                        data-result-key="ritmNumber"></select>
                                <small class="form-text text-muted">e.g. RITM0012345</small>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="modal-result-status" class="font-weight-bold">Submission Status</label>
                                <select class="form-control result-field-select" id="modal-result-status"
                                        data-result-key="status"></select>
                                <small class="form-text text-muted">
                                    Set to <code>Submitted</code> or <code>Failed</code>.
                                </small>
                            </div>
                        </div>
                        <small class="form-text text-muted mt-0">
                            When a submission fails, the status is set to <code>Failed</code> and both number fields are
                            cleared. Fields on the trigger form are written to the instance that was saved.
                        </small>
                    </div>
                </div>

                <!-- Failure Notifications -->
                <div class="card shadow-sm mb-2">
                    <div class="card-header">
                        <i class="fas fa-bell mr-1"></i> Failure Notifications
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-0">
                            <label for="modal-failure-emails" class="font-weight-bold">
                                Notification Email Addresses
                                <small class="font-weight-normal text-muted ml-1">(optional)</small>
                            </label>
                            <textarea class="form-control" id="modal-failure-emails" rows="2"
                                      style="max-width:540px"
                                      placeholder="e.g. redcap-admin@example.edu, servicedesk@example.edu"></textarea>
                            <small class="form-text text-muted">
                                Each address receives an email when a submission for this catalog item fails, or when
                                the request is created but a file cannot be attached. The email includes the record ID
                                and the failure reason, but no other record data. Separate multiple addresses with
                                commas, semicolons, or new lines. Leave blank to turn off notifications.
                            </small>
                        </div>
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


<!-- ── Delete Connection Settings Confirmation Modal ──────────────────────── -->
<div class="modal fade" id="delete-connection-confirm-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the saved ServiceNow connection settings
                   (instance URL, authentication method, and all saved credentials)?
                   Any cached OAuth token is also removed from REDCap, but it is not revoked in
                   ServiceNow; it stays valid there until it expires.</p>
                <p class="text-danger mb-0"><small><i class="fas fa-exclamation-triangle mr-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete-connection">
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
    let HAS_PASSWORD      = <?= $hasPassword ? 'true' : 'false' ?>;
    let HAS_CLIENT_SECRET = <?= $hasClientSecret ? 'true' : 'false' ?>;
    // The instance the saved password and client secret belong to, as the
    // server normalizes it (null when none is saved).
    let SAVED_INSTANCE_BASE = <?= json_encode($savedBaseUrl) ?>;
    // The saved connection settings other than secrets, in the shape returned
    // by currentConnection(), to tell whether the page has unsaved changes.
    let SAVED_CONNECTION = <?= json_encode($savedConnection) ?>;
    // Whether complete connection settings are saved (fields are always
    // loaded with the saved settings, not the ones on the page).
    let CONNECTION_SAVED  = <?= $connectionSaved ? 'true' : 'false' ?>;
    // Sent in place of a secret left blank, meaning "keep the saved value".
    const SAVED_SECRET    = '__SAVED__';
    const RECORD_HOME_URL = <?= json_encode($recordHomeUrl) ?>;
    const RECORD_ID_FIELD = <?= json_encode($recordIdField) ?>;

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

    // Trigger criteria need to test individual checkbox options (a checkbox
    // field itself has no single value), so each option is expanded into its
    // own selectable pseudo-field named "<field>___<code>" — matching REDCap's
    // own export naming convention for checkboxes.
    const RC_CRITERIA_FIELDS = [];
    RC_FIELDS.forEach(function (f) {
        if (f.type === 'checkbox' && Array.isArray(f.choices) && f.choices.length > 0) {
            f.choices.forEach(function (c) {
                RC_CRITERIA_FIELDS.push({
                    name:    f.name + '___' + c.value,
                    label:   f.label + ': ' + c.label,
                    type:    'checkbox_option',
                    form:    f.form,
                    choices: [ { value: '1', label: 'Checked' }, { value: '0', label: 'Unchecked' } ]
                });
            });
            return;
        }
        RC_CRITERIA_FIELDS.push(f);
    });

    const RC_CRITERIA_FIELD_CHOICES = {};
    RC_CRITERIA_FIELDS.forEach(function (f) {
        if (Array.isArray(f.choices) && f.choices.length > 0) {
            RC_CRITERIA_FIELD_CHOICES[f.name] = f.choices;
        }
    });

    // ── Modal state ───────────────────────────────────────────────────────
    let pendingModalItem    = null;   // { index, label, active, sysId, ... } or { index: null }
    let modalCurrentSnapshot = null; // { sys_id, variables, fetched_at }
    let pendingDeleteIndex  = null;

    // Bootstrap modal instances
    const catalogItemModal  = new bootstrap.Modal(document.getElementById('catalog-item-modal'), { backdrop: 'static', keyboard: false });
    const deleteConfirmModal = new bootstrap.Modal(document.getElementById('delete-confirm-modal'));
    const deleteConnectionConfirmModal = new bootstrap.Modal(document.getElementById('delete-connection-confirm-modal'));
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

    // ── Connection settings helpers ───────────────────────────────────────
    function getAuthType() {
        return $('input[name="sn-auth-type"]:checked').val() === 'oauth' ? 'oauth' : 'basic';
    }

    function getGrantType() {
        return $('#sn-oauth-grant-type').val() === 'password' ? 'password' : 'client_credentials';
    }

    // Basic authentication and the OAuth password grant use the service
    // account username/password; the client credentials grant does not.
    function usesUserCredentials(authType, grantType) {
        return authType !== 'oauth' || grantType === 'password';
    }

    // Shows only the inputs used by the selected authentication method.
    function updateAuthFields() {
        const authType  = getAuthType();
        const grantType = getGrantType();
        $('#sn-oauth-settings').toggle(authType === 'oauth');
        $('#sn-user-credentials').toggle(usesUserCredentials(authType, grantType));
        $('.sn-grant-help').each(function () {
            $(this).toggle($(this).data('grant') === grantType);
        });
        $('#sn-oauth-grant-type').attr('aria-describedby', 'sn-grant-help-' + grantType);
    }

    // Host names accepted by the server (ServiceNowApiClient::normalizeInstanceUrl()).
    const INSTANCE_HOST_PATTERN = /^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?)+$/;

    // Returns the base URL the server sends requests for an instance URL to
    // (https://host, plus the port when it is not 443), or null when the
    // server would reject the URL. Uses exactly the rules of the server's
    // ServiceNowApiClient::normalizeInstanceUrl() (for the trimmed value the
    // page sends), rather than the browser's URL parser, which accepts and
    // rewrites more.
    function normalizeInstanceUrl(value) {
        const url   = String(value || '').trim().replace(/\/+$/, '');
        const match = /^https:\/\/([^\/?#]*)/i.exec(url);
        // Whitespace as PHP's \s defines it, so both sides agree.
        const authority = (match && !/[\t\n\v\f\r ]/.test(match[1])) ? /^([^:@]*)(?::([^:@]*))?$/.exec(match[1]) : null;
        if (!authority || !INSTANCE_HOST_PATTERN.test(authority[1])) {
            return null;
        }
        let port = 443;
        if (authority[2] !== undefined && authority[2] !== '') {
            port = /^[0-9]{1,5}$/.test(authority[2]) ? parseInt(authority[2], 10) : 0;
            if (port < 1 || port > 65535) {
                return null;
            }
        }
        return 'https://' + authority[1].toLowerCase() + (port !== 443 ? ':' + port : '');
    }

    // A saved password or client secret is only ever sent to the saved
    // instance, so it can only be reused while the Instance URL points there.
    function isSavedInstance() {
        return SAVED_INSTANCE_BASE !== null && SAVED_INSTANCE_BASE === normalizeInstanceUrl($('#instance-url').val());
    }

    // Reflects in a secret input's placeholder and note whether the saved
    // secret will be used when the input is left blank.
    function updateSecretField($input, $note, hasSaved, label) {
        const sameInstance = isSavedInstance();
        $input.attr('placeholder', hasSaved && sameInstance ? 'Saved — enter to change' : 'Enter ' + label);
        if (hasSaved) {
            $note.text(sameInstance
                ? 'A ' + label + ' is saved. Leave blank to keep it.'
                : 'The saved ' + label + ' is only used with the saved instance URL. Enter it again to connect to a different instance.');
            $input.attr('aria-describedby', $note.attr('id'));
        } else {
            $input.removeAttr('aria-describedby');
        }
        $note.toggle(hasSaved);
    }

    function updateSavedSecretFields() {
        updateSecretField($('#sn-password'), $('#sn-password-saved-note'), HAS_PASSWORD, 'password');
        updateSecretField($('#sn-client-secret'), $('#sn-client-secret-saved-note'), HAS_CLIENT_SECRET, 'client secret');
    }

    function getCredentials() {
        const authType  = getAuthType();
        const grantType = authType === 'oauth' ? getGrantType() : '';
        const userCreds = usesUserCredentials(authType, grantType);
        const pw        = $('#sn-password').val();
        const secret    = $('#sn-client-secret').val();
        const reuse     = isSavedInstance();
        return {
            instanceUrl:  $('#instance-url').val().trim(),
            authType:     authType,
            grantType:    grantType,
            clientId:     authType === 'oauth' ? $('#sn-client-id').val().trim() : '',
            clientSecret: authType === 'oauth' ? (secret !== '' ? secret : (HAS_CLIENT_SECRET && reuse ? SAVED_SECRET : '')) : '',
            username:     userCreds ? $('#sn-username').val().trim() : '',
            password:     userCreds ? (pw !== '' ? pw : (HAS_PASSWORD && reuse ? SAVED_SECRET : '')) : ''
        };
    }

    // The connection settings on the page other than secrets, in the shape of
    // SAVED_CONNECTION.
    function currentConnection() {
        const creds = getCredentials();
        return {
            instanceUrl: creds.instanceUrl,
            authType:    creds.authType,
            grantType:   creds.grantType,
            clientId:    creds.clientId,
            username:    creds.username
        };
    }

    // Whether the page shows connection settings other than the saved ones,
    // including a typed password or client secret that the selected method uses.
    function hasUnsavedConnectionChanges() {
        const now         = currentConnection();
        const typedSecret = (usesUserCredentials(now.authType, now.grantType) && $('#sn-password').val() !== '')
            || (now.authType === 'oauth' && $('#sn-client-secret').val() !== '');
        return typedSecret
            || Object.keys(SAVED_CONNECTION).some(function (key) { return now[key] !== SAVED_CONNECTION[key]; });
    }

    // Labels of the required connection settings that are still empty.
    function getMissingConnectionFields(creds) {
        const missing = [];
        if (!creds.instanceUrl) { missing.push('Instance URL'); }
        if (creds.authType === 'oauth') {
            if (!creds.clientId)     { missing.push('Client ID'); }
            if (!creds.clientSecret) { missing.push('Client Secret'); }
        }
        if (usesUserCredentials(creds.authType, creds.grantType)) {
            if (!creds.username) { missing.push('Username'); }
            if (!creds.password) { missing.push('Password'); }
        }
        return missing;
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

    // Same grouping as buildFormGroupedOptions, but sourced from RC_CRITERIA_FIELDS
    // so individual checkbox options are selectable as trigger criteria fields.
    function buildCriteriaFieldGroupedOptions(selectedValue) {
        const formOrder = [];
        const byForm    = {};
        RC_CRITERIA_FIELDS.forEach(function (f) {
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

    // Builds <optgroup>ed <option>s for REDCap file-upload fields, used by the
    // attachments multi-select. `selected` is an array of field names.
    function buildFileFieldOptions(selected) {
        selected = Array.isArray(selected) ? selected : [];
        const formOrder = [];
        const byForm    = {};
        RC_FIELDS.forEach(function (f) {
            if (f.type !== 'file') return;
            if (!byForm[f.form]) {
                formOrder.push(f.form);
                byForm[f.form] = [];
            }
            byForm[f.form].push(f);
        });
        let opts = '';
        formOrder.forEach(function (form) {
            const formLabel = form.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            opts += '<optgroup label="' + escHtml(formLabel) + '">';
            byForm[form].forEach(function (f) {
                const sel  = (selected.indexOf(f.name) !== -1) ? ' selected' : '';
                const text = escHtml(f.label) + ' [' + escHtml(f.name) + ']';
                opts += '<option value="' + escHtml(f.name) + '"' + sel + '>' + text + '</option>';
            });
            opts += '</optgroup>';
        });
        return opts;
    }

    // Builds <optgroup>ed <option>s for the "Save Results to REDCap" selects:
    // text fields without validation and notes fields, excluding the record ID.
    function isResultFieldCandidate(f) {
        if (f.name === RECORD_ID_FIELD) return false;
        return (f.type === 'text' && !f.validation) || f.type === 'notes';
    }

    function buildResultFieldOptions(selected) {
        const formOrder = [];
        const byForm    = {};
        RC_FIELDS.forEach(function (f) {
            if (!isResultFieldCandidate(f)) return;
            if (!byForm[f.form]) {
                formOrder.push(f.form);
                byForm[f.form] = [];
            }
            byForm[f.form].push(f);
        });
        let opts = '<option value=""></option>';
        formOrder.forEach(function (form) {
            const formLabel = RC_FORMS.get(form) || form;
            opts += '<optgroup label="' + escHtml(formLabel) + '">';
            byForm[form].forEach(function (f) {
                const sel  = (f.name === selected) ? ' selected' : '';
                const text = escHtml(f.label) + ' [' + escHtml(f.name) + ']';
                opts += '<option value="' + escHtml(f.name) + '"' + sel + '>' + text + '</option>';
            });
            opts += '</optgroup>';
        });
        return opts;
    }

    function collectResultFields() {
        const fields = {};
        $('#catalog-item-modal .result-field-select').each(function () {
            fields[$(this).data('result-key')] = $(this).val() || '';
        });
        return fields;
    }

    function buildValueInput(fieldName, currentValue, hide) {
        const style   = 'max-width:200px' + (hide ? ';display:none' : '');
        const choices = fieldName ? (RC_CRITERIA_FIELD_CHOICES[fieldName] || null) : null;
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

    // Select2 quirk: clicking the clear (×) button both clears the value AND
    // reopens the dropdown. Suppress the open that immediately follows a clear.
    $(document).on('select2:clear', 'select', function () {
        $(this).one('select2:opening', function (e) {
            e.preventDefault();
        });
    });

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
            $('#modal-failure-emails').val(Array.isArray(item.failureEmails) ? item.failureEmails.join(', ') : '');
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
        $('#modal-attachment-fields').empty().val(null);
        $('#modal-failure-emails').val('');
        $('#catalog-item-modal .result-field-select').empty().val(null);
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

        // Attachment fields multi-select
        const attachSelected = (item && Array.isArray(item.attachmentFields)) ? item.attachmentFields : [];
        $('#modal-attachment-fields')
            .html(buildFileFieldOptions(attachSelected))
            .select2({
                width:          '100%',
                placeholder:    '— Select file upload fields —',
                allowClear:     true,
                dropdownParent: $('#catalog-item-modal')
            });

        // Save Results to REDCap selects
        const resultFields = (item && item.resultFields) ? item.resultFields : {};
        $('#catalog-item-modal .result-field-select').each(function () {
            $(this)
                .html(buildResultFieldOptions(resultFields[$(this).data('result-key')] || ''))
                .select2({
                    width:          '100%',
                    placeholder:    '— Do not save —',
                    allowClear:     true,
                    dropdownParent: $('#catalog-item-modal')
                });
        });
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
        const sysId = $('#modal-item-sys-id').val().trim();

        // Fields are always loaded with the saved connection settings.
        if (!CONNECTION_SAVED) {
            showAlert('warning', 'Save the connection settings before loading fields.');
            return;
        }
        if (!sysId) {
            showAlert('warning', 'Please enter the catalog item sys_id.');
            return;
        }
        if (hasUnsavedConnectionChanges()) {
            showAlert('info', 'Fields are loaded with the saved connection settings. Unsaved changes to the connection settings are not used.', true);
        }

        const $btn = $('#btn-modal-fetch').prop('disabled', true)
                                         .html('<i class="fas fa-spinner fa-spin mr-1"></i> Loading…');
        $('#modal-mapping-table-section').hide();
        $('#modal-mapping-count').hide();

        // No credentials are sent from the page.
        module.ajax('getServiceNowFields', {
            sysId: sysId
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
            '<select class="form-select form-select-sm criterion-field">' + buildCriteriaFieldGroupedOptions(field) + '</select>' +
            '</div>' +
            '<select class="form-select form-select-sm mr-2 criterion-operator" style="max-width:175px">' + opOpts + '</select>' +
            buildValueInput(field, value, hideValue) +
            '<button class="btn btn-sm btn-outline-danger btn-remove-criterion flex-shrink-0 ml-2" type="button" title="Remove">' +
            '<i class="fas fa-times"></i></button></div>';
    }

    function buildCriteriaGroup(rows) {
        rows = (rows && rows.length > 0) ? rows : [{}];
        let rowsHtml = '';
        rows.forEach(function (c) { rowsHtml += buildCriterionRow(c); });
        return '<div class="criteria-group border rounded p-2 mb-2">' +
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
            '<small class="text-muted font-weight-bold text-uppercase">Match all of</small>' +
            '<button class="btn btn-sm btn-link text-danger btn-remove-group p-0" type="button" title="Remove group">' +
            '<i class="fas fa-trash-alt"></i></button></div>' +
            '<div class="criteria-rows">' + rowsHtml + '</div>' +
            '<button class="btn btn-sm btn-outline-secondary btn-add-condition mt-1" type="button">' +
            '<i class="fas fa-plus mr-1"></i> Add Condition</button></div>';
    }

    // Accepts either the current OR-of-AND-groups format (array of arrays) or
    // legacy flat criteria (array of criterion objects), always returning groups.
    function normalizeCriteriaGroups(criteria) {
        if (!criteria || criteria.length === 0) {
            return [];
        }
        if (criteria[0] && typeof criteria[0] === 'object' && !Array.isArray(criteria[0]) && 'field' in criteria[0]) {
            return [criteria];
        }
        return criteria;
    }

    function renderModalCriteria(criteria) {
        const $container = $('#modal-criteria-container').empty();
        const groups = normalizeCriteriaGroups(criteria);
        if (groups.length === 0) {
            $('#modal-criteria-empty').show();
            return;
        }
        $('#modal-criteria-empty').hide();

        groups.forEach(function (group, gi) {
            if (gi > 0) {
                $container.append('<div class="text-center text-muted small my-1 criteria-or-divider">— OR —</div>');
            }
            const savedFields = (group || []).map(function (c) { return c.field || ''; });
            const $group = $(buildCriteriaGroup(group));
            $container.append($group);

            $group.find('.criterion-field').select2({
                width:          '100%',
                placeholder:    '— Select field —',
                allowClear:     true,
                dropdownParent: $('#catalog-item-modal')
            });

            $group.find('.criterion-row').each(function (i) {
                const val = savedFields[i];
                if (val) {
                    $(this).find('.criterion-field').val(val).trigger('change');
                }
            });
        });
    }

    function collectModalCriteria() {
        const groups = [];
        $('#modal-criteria-container .criteria-group').each(function () {
            const rows = [];
            $(this).find('.criterion-row').each(function () {
                const field    = $(this).find('.criterion-field').val();
                const operator = $(this).find('.criterion-operator').val();
                const value    = $(this).find('.criterion-value').val();
                if (field) {
                    rows.push({ field: field, operator: operator || 'eq', value: value || '' });
                }
            });
            if (rows.length > 0) {
                groups.push(rows);
            }
        });
        return groups;
    }

    $('#btn-modal-add-group').on('click', function () {
        $('#modal-criteria-empty').hide();
        if ($('#modal-criteria-container .criteria-group').length > 0) {
            $('#modal-criteria-container').append('<div class="text-center text-muted small my-1 criteria-or-divider">— OR —</div>');
        }
        const $group = $(buildCriteriaGroup([{}]));
        $('#modal-criteria-container').append($group);
        $group.find('.criterion-field').select2({
            width:          '100%',
            placeholder:    '— Select field —',
            allowClear:     true,
            dropdownParent: $('#catalog-item-modal')
        });
    });

    $('#modal-criteria-container').on('click', '.btn-add-condition', function () {
        const $group = $(this).closest('.criteria-group');
        const $row   = $(buildCriterionRow({}));
        $group.find('.criteria-rows').append($row);
        $row.find('.criterion-field').select2({
            width:          '100%',
            placeholder:    '— Select field —',
            allowClear:     true,
            dropdownParent: $('#catalog-item-modal')
        });
    });

    $('#modal-criteria-container').on('click', '.btn-remove-group', function () {
        const $group = $(this).closest('.criteria-group');
        const $divider = $group.prev('.criteria-or-divider').length
            ? $group.prev('.criteria-or-divider')
            : $group.next('.criteria-or-divider');
        $divider.remove();
        $group.remove();
        if ($('#modal-criteria-container .criteria-group').length === 0) {
            $('#modal-criteria-empty').show();
        }
    });

    $('#modal-criteria-container').on('click', '.btn-remove-criterion', function () {
        $(this).closest('.criterion-row').remove();
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

    // ── Failure notification emails ───────────────────────────────────────
    // Splits the textarea on commas, semicolons, and whitespace and removes
    // case-insensitive duplicates. The server re-validates every address.
    function parseEmailList(text) {
        const seen   = {};
        const emails = [];
        String(text || '').split(/[\s,;]+/).forEach(function (part) {
            const email = part.trim();
            if (!email || seen[email.toLowerCase()]) return;
            seen[email.toLowerCase()] = true;
            emails.push(email);
        });
        return emails;
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

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

        const failureEmails = parseEmailList($('#modal-failure-emails').val());
        const invalidEmails = failureEmails.filter(function (e) { return !isValidEmail(e); });
        if (invalidEmails.length > 0) {
            showAlert('warning',
                'Please correct the following failure notification email address(es): ' +
                '<strong>' + invalidEmails.map(escHtml).join(', ') + '</strong>.');
            return;
        }

        const resultFields = collectResultFields();
        const chosenResultFields = Object.values(resultFields).filter(Boolean);
        if (new Set(chosenResultFields).size !== chosenResultFields.length) {
            showAlert('warning', 'Choose a different REDCap field for each result in <strong>Save Results to REDCap</strong>.');
            return;
        }

        const fieldMappings = collectMappings();
        const valueMappings = collectValueMappings();
        const criteria      = collectModalCriteria();
        const attachmentFields = $('#modal-attachment-fields').val() || [];
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
            attachmentFields: attachmentFields,
            triggerForm:   triggerForm,
            criteria:      criteria,
            snapshot:      modalCurrentSnapshot,
            resultFields:  resultFields,
            failureEmails: failureEmails
        }).then(function (resp) {
            if (resp.success) {
                const newItem = {
                    label:         label,
                    active:        active,
                    sysId:         sysId,
                    fieldMappings: fieldMappings,
                    valueMappings: valueMappings,
                    attachmentFields: attachmentFields,
                    triggerForm:   triggerForm,
                    criteria:      criteria,
                    snapshot:      modalCurrentSnapshot,
                    resultFields:  resp.resultFields || resultFields,
                    failureEmails: Array.isArray(resp.failureEmails) ? resp.failureEmails : failureEmails
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
    // Incremented whenever a connection input changes, so a test result
    // only enables Save for the exact settings that were tested.
    let connectionVersion = 0;
    // Whether the settings on the page have passed a test (and are unchanged since).
    let saveAllowed = false;
    // Whether a Test, Save, or Delete request is in flight. Only one runs at a
    // time, so their responses cannot overtake each other.
    let connectionBusy = false;

    function refreshConnectionButtons() {
        $('#btn-test, #btn-delete-connection, #btn-confirm-delete-connection').prop('disabled', connectionBusy);
        $('#btn-save-connection').prop('disabled', connectionBusy || !saveAllowed);
    }

    function setConnectionBusy(busy) {
        connectionBusy = busy;
        refreshConnectionButtons();
    }

    $('.sn-connection-input').on('input change', function () {
        connectionVersion++;
        saveAllowed = false;
        refreshConnectionButtons();
        $('#test-status').html('');
    });

    $('input[name="sn-auth-type"], #sn-oauth-grant-type').on('change', updateAuthFields);
    $('#instance-url').on('input change', updateSavedSecretFields);

    // When the page is shown again from the browser history, the browser may
    // have restored inputs to unsaved values without firing change events, so
    // bring the visible fields in line with the inputs. Restoration happens
    // before this ready handler runs on a normal history navigation (so sync
    // now) and before 'pageshow' otherwise.
    updateAuthFields();
    updateSavedSecretFields();
    $(window).on('pageshow', function () {
        updateAuthFields();
        updateSavedSecretFields();
    });

    $('#btn-test').on('click', function () {
        if (connectionBusy) {
            return;
        }
        const creds   = getCredentials();
        const missing = getMissingConnectionFields(creds);
        if (missing.length > 0) {
            showAlert('warning', 'Please fill in the following before testing: ' + escHtml(missing.join(', ')) + '.');
            return;
        }
        const testedVersion = connectionVersion;
        const $btn = $(this).html('<i class="fas fa-spinner fa-spin mr-1"></i> Testing…');
        setConnectionBusy(true);
        $('#test-status').html('');

        module.ajax('testConnection', creds).then(function (resp) {
            if (testedVersion !== connectionVersion) {
                return; // The settings were edited while the test was running.
            }
            if (resp.success) {
                $('#test-status').html(resp.warning
                    ? '<span class="text-warning-emphasis"><i class="fas fa-exclamation-triangle mr-1"></i>' + escHtml(resp.warning) + '</span>'
                    : '<span class="text-success"><i class="fas fa-check-circle mr-1"></i>Connected successfully</span>'
                );
                saveAllowed = true;
            } else {
                $('#test-status').html(
                    '<span class="text-danger"><i class="fas fa-times-circle mr-1"></i>' +
                    escHtml((resp && resp.error) || 'Connection failed') + '</span>'
                );
                saveAllowed = false;
            }
        }).catch(function () {
            if (testedVersion !== connectionVersion) {
                return;
            }
            $('#test-status').html(
                '<span class="text-danger"><i class="fas fa-times-circle mr-1"></i>Request error</span>'
            );
            saveAllowed = false;
        }).finally(function () {
            $btn.html('<i class="fas fa-satellite-dish mr-1"></i> Test Connection');
            setConnectionBusy(false);
        });
    });

    $('#btn-save-connection').on('click', function () {
        if (connectionBusy || !saveAllowed) {
            return;
        }
        const creds        = getCredentials();
        const savedVersion = connectionVersion;
        const $btn         = $(this).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving…');
        setConnectionBusy(true);

        module.ajax('saveConnectionSettings', creds).then(function (resp) {
            if (resp.success) {
                showAlert('success', 'Connection settings saved.', true);
                // The server keeps only the credentials the selected method
                // uses, and saves the instance URL in its normalized form.
                const oauth       = creds.authType === 'oauth';
                const userCreds   = usesUserCredentials(creds.authType, creds.grantType);
                const instanceUrl = (resp && resp.instanceUrl) || creds.instanceUrl;
                HAS_PASSWORD        = userCreds;
                HAS_CLIENT_SECRET   = oauth;
                SAVED_INSTANCE_BASE = normalizeInstanceUrl(instanceUrl);
                SAVED_CONNECTION    = {
                    instanceUrl: instanceUrl,
                    authType:    creds.authType,
                    grantType:   creds.grantType,
                    clientId:    creds.clientId,
                    username:    creds.username
                };
                CONNECTION_SAVED = true;
                // Show what was saved, unless the inputs were edited while
                // the settings were being saved.
                if (savedVersion === connectionVersion) {
                    $('#instance-url').val(instanceUrl);
                    $('#sn-password, #sn-client-secret').val('');
                    if (!userCreds) {
                        $('#sn-username').val('');
                    }
                    if (!oauth) {
                        $('#sn-client-id').val('');
                        $('#sn-oauth-grant-type').val('client_credentials');
                        updateAuthFields();
                    }
                }
                updateSavedSecretFields();
                $('#btn-delete-connection').show();
            } else {
                showAlert('danger', 'Save failed: ' + escHtml((resp && resp.error) || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Save request failed. Please try again.');
        }).finally(function () {
            // Settings edited during the save must be tested before they can be saved.
            saveAllowed = savedVersion === connectionVersion;
            $btn.html('<i class="fas fa-save mr-1"></i> Save Connection Settings');
            setConnectionBusy(false);
        });
    });

    // ── Delete Connection Settings ────────────────────────────────────────
    $('#btn-delete-connection').on('click', function () {
        if (!connectionBusy) {
            deleteConnectionConfirmModal.show();
        }
    });

    $('#btn-confirm-delete-connection').on('click', function () {
        if (connectionBusy) {
            return;
        }
        const $btn = $(this).html('<i class="fas fa-spinner fa-spin mr-1"></i> Deleting…');
        setConnectionBusy(true);

        module.ajax('deleteConnectionSettings', {}).then(function (resp) {
            if (resp.success) {
                HAS_PASSWORD        = false;
                HAS_CLIENT_SECRET   = false;
                SAVED_INSTANCE_BASE = null;
                SAVED_CONNECTION    = { instanceUrl: '', authType: 'basic', grantType: '', clientId: '', username: '' };
                CONNECTION_SAVED    = false;
                connectionVersion++;
                saveAllowed = false;
                $('#instance-url').val('');
                $('#sn-auth-type-basic').prop('checked', true);
                $('#sn-oauth-grant-type').val('client_credentials');
                $('#sn-client-id').val('');
                $('#sn-username').val('');
                $('#sn-password, #sn-client-secret').val('');
                updateSavedSecretFields();
                updateAuthFields();
                $('#test-status').html('');
                $('#btn-delete-connection').hide();
                deleteConnectionConfirmModal.hide();
                showAlert('success', 'Connection settings deleted.', true);
            } else {
                showAlert('danger', 'Delete failed: ' + escHtml((resp && resp.error) || 'Unknown error.'));
            }
        }).catch(function () {
            showAlert('danger', 'Delete request failed. Please try again.');
        }).finally(function () {
            $btn.html('<i class="fas fa-trash mr-1"></i> Delete');
            setConnectionBusy(false);
        });
    });

    // ── Password / client secret visibility toggles ───────────────────────
    function bindSecretToggle(buttonSelector, inputSelector, iconSelector) {
        $(buttonSelector).on('click', function () {
            const $input = $(inputSelector);
            const show   = $input.attr('type') === 'password';
            $input.attr('type', show ? 'text' : 'password');
            $(iconSelector).toggleClass('fa-eye fa-eye-slash');
            $(this).attr('aria-pressed', show ? 'true' : 'false');
        });
    }
    bindSecretToggle('#btn-toggle-pw', '#sn-password', '#pw-eye-icon');
    bindSecretToggle('#btn-toggle-client-secret', '#sn-client-secret', '#client-secret-eye-icon');

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

    const LOG_STATUS_BADGES = {
        success:           { cls: 'bg-success',          icon: 'check-circle',         text: 'Success' },
        failed:            { cls: 'bg-danger',           icon: 'times-circle',         text: 'Failed' },
        attachment_failed: { cls: 'bg-warning text-dark', icon: 'exclamation-triangle', text: 'Attachment Failed' },
        save_failed:       { cls: 'bg-warning text-dark', icon: 'exclamation-triangle', text: 'Save to REDCap Failed' }
    };

    // Renders a ServiceNow number (REQ/RITM) as a link when its URL is known.
    function renderServiceNowLink(urlKey) {
        return function (d, type, row) {
            if (type !== 'display') { return d || ''; }
            if (!d) { return ''; }
            if (row && row[urlKey]) {
                return '<a href="' + escHtml(row[urlKey]) + '" target="_blank" rel="noopener">' + escHtml(d) + '</a>';
            }
            return escHtml(d);
        };
    }

    function buildStatusBadge(status) {
        const b = LOG_STATUS_BADGES[status] || LOG_STATUS_BADGES.failed;
        return '<span class="badge sn-log-status ' + b.cls + '"><i class="fas fa-' + b.icon + ' me-1"></i>' +
               escHtml(b.text) + '</span>';
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
                data.status = $('#log-status-filter').val() || 'all';
                module.ajax('getSubmissionLog', data).then(function (json) {
                    callback(json);
                }).catch(function () {
                    callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                    showAlert('danger', 'Failed to load the submission log.');
                });
            },
            columns: [
                { data: 'timestamp',     render: function (d, type) { return type === 'display' ? escHtml(d || '') : (d || ''); } },
                { data: 'status',        render: function (d, type) { return type === 'display' ? buildStatusBadge(d) : (d || ''); } },
                { data: 'record',        render: function (d, type) { return type === 'display' ? buildRecordLink(d) : (d || ''); } },
                { data: 'catalog_label', render: function (d, type) { return type === 'display' ? escHtml(d || '') : (d || ''); } },
                { data: 'instrument',    render: function (d, type) { return type === 'display' ? escHtml(formLabelFor(d)) : (d || ''); } },
                { data: 'request_number', render: renderServiceNowLink('request_url') },
                { data: 'ritm_number',    render: renderServiceNowLink('ritm_url') },
                { data: 'reason', orderable: false, className: 'sn-log-reason', render: function (d, type) {
                    if (type !== 'display') { return d || ''; }
                    return d ? '<small>' + escHtml(d) + '</small>' : '';
                } }
            ],
            language: {
                emptyTable:  'No RITM submissions have been logged yet.',
                zeroRecords: 'No matching submissions found.'
            }
        });
    }

    $('#log-status-filter').on('change', function () {
        if (submissionLogTable) {
            submissionLogTable.ajax.reload();
        }
    });

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

    // Failure notification emails link to "#submission-log"; open that tab.
    if (window.location.hash === '#submission-log') {
        bootstrap.Tab.getOrCreateInstance(document.getElementById('tab-log-btn')).show();
    }

});
</script>
