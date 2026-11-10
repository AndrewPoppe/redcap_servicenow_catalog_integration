# REDCap ServiceNow Catalog Integration

## Table of Contents

- [Overview](#overview)
  - [What is it?](#what-is-it)
  - [How does it work?](#how-does-it-work)
  - [What you'll need](#what-youll-need)
- [Installation](#installation)
- [Getting Started](#getting-started)
- [The Configuration Page](#the-configuration-page)
  - [Connection Settings](#connection-settings)
  - [Catalog Items](#catalog-items)
    - [General Settings](#general-settings)
    - [Catalog Item \& Field Mapping](#catalog-item--field-mapping)
    - [Matching Values](#matching-values)
    - [Attachments](#attachments)
    - [Trigger Settings](#trigger-settings)
    - [Failure Notifications](#failure-notifications)
  - [Submission Log](#submission-log)
- [What Gets Sent to ServiceNow](#what-gets-sent-to-servicenow)
- [Logging and Troubleshooting](#logging-and-troubleshooting)
- [Security Considerations](#security-considerations)
- [FAQ](#faq)
- [Changelog](#changelog)

## Overview

### What is it?

This is a REDCap External Module that connects a REDCap project to a [ServiceNow](https://www.servicenow.com/) service catalog. When a record is saved in REDCap, the module can automatically order a ServiceNow catalog item on your behalf — creating a Request (REQ) and Request Item (RITM) in ServiceNow populated with values from the REDCap record.

From an end-user perspective, this means data entered into a REDCap form can automatically create a matching request in ServiceNow, so downstream teams can track and act on it in their existing ServiceNow workflow. No one has to re-key data from REDCap into a ServiceNow form.

The module provides a point-and-click configuration page within the REDCap project where an administrator can:

- Connect to a ServiceNow instance and verify the connection
- Configure one or more ServiceNow catalog items to integrate with
- Map REDCap fields to the variables (questions) defined on each catalog item
- Match REDCap choice values (radio, dropdown, checkbox, etc.) to ServiceNow choice values
- Attach files uploaded to REDCap *File Upload* fields to the resulting ServiceNow request item
- Control exactly when a submission fires, using a trigger form and optional record-based criteria
- Review a searchable log of every submission attempt — successful or not — with direct links to the resulting requests in ServiceNow and the reason for any failure
- Email a list of recipients, per catalog item, whenever a submission fails

### How does it work?

1. A REDCap administrator configures the ServiceNow connection and one or more catalog items on the module's configuration page (see [Getting Started](#getting-started)).
2. A user saves a record on the configured **trigger form** — via data entry or survey.
3. The module checks each active catalog item configuration:
   - Does the saved instrument match the item's trigger form?
   - Does at least one of the item's push criteria condition groups (if any) pass for this record?
4. If so, the module builds a submission from the record's data using the configured field and value mappings and orders the catalog item in ServiceNow using the [Service Catalog API](https://www.servicenow.com/docs/r/zurich/api-reference/rest-apis/c_ServiceCatalogAPI.html) (`order_now`).
5. ServiceNow creates a Request (REQ) and Request Item (RITM), which then flow through whatever workflow is attached to the catalog item in ServiceNow.
6. If any REDCap *File Upload* fields are configured as attachments, the module uploads those files to the newly created RITM.
7. The outcome (success or failure) is logged and appears in the module's [Submission Log](#submission-log). If the submission fails, or an attachment cannot be uploaded, the catalog item's [failure notification](#failure-notifications) recipients (if any) are emailed.

All of this happens in the background — the person saving the REDCap record does not need to do anything special, and they do not need a ServiceNow account.

### What you'll need

On the ServiceNow side:

- The URL of your ServiceNow instance (e.g., `https://your-instance.service-now.com`)
- A ServiceNow service account (username and password) that:
  - Can authenticate with **basic authentication**
   - Can read the catalog list and has access to the **Service Catalog API** (`sn_sc`) for the catalog item(s) you want to order
  - Has read access to the `sc_req_item` table via the **Table API** (used to locate the RITM when uploading attachments)
  - Has access to the **Attachment API** (only needed if you use the attachments feature)
- The **sys_id** of each catalog item you want to integrate with (a 32-character hexadecimal identifier, visible in the catalog item's URL or record in ServiceNow)

## Installation

- This External Module should be installed via the REDCap Repo.

Once installed, enable the module in the REDCap project(s) that should submit catalog requests.

## Getting Started

1. **Enable the module** in your REDCap project.
2. Open the **ServiceNow Catalog Integration** link in the project's left-hand menu (visible to REDCap administrators).
3. On the **Configuration** tab, enter your ServiceNow instance URL, username, and password in [Connection Settings](#connection-settings), click **Test Connection**, and then **Save Connection Settings**.
4. Click **Add Catalog Item**, enter the catalog item's **sys_id**, and click **Load Fields** to pull in the catalog item's variables from ServiceNow.
5. Map each ServiceNow variable to the REDCap field that should supply its value, and match choice values where needed.
6. Choose a **Trigger Form** and (optionally) add **Push Criteria**.
7. (Optional) Enter one or more **Failure Notification** email addresses.
8. Save the catalog item and make sure it is marked **Active**.
9. Save a test record on the trigger form and confirm the request appears in ServiceNow and in the module's [Submission Log](#submission-log).

## The Configuration Page

The configuration page is added to the project's menu as **ServiceNow Catalog Integration**. It is only accessible to REDCap administrators. It has two tabs: **Configuration** and **Submission Log**.

### Connection Settings

This card holds the credentials used for every call to ServiceNow.

| Setting | Required | Description |
|---|---|---|
| ServiceNow Instance URL | Yes | The base URL of your ServiceNow instance, e.g. `https://your-instance.service-now.com`. Must begin with `https://`. |
| Username | Yes | The ServiceNow service account username used to authenticate (basic auth). |
| Password | Yes | The service account password. The saved password is not displayed after the page reloads. Leave it blank when updating the URL or username to keep the currently saved password; enter a new value only when you want to change it. |

Use the **Test Connection** button to verify the URL and credentials before saving. The **Save Connection Settings** button is only enabled after a successful test, which helps prevent saving a broken configuration. Once connection settings have been saved, a **Delete Connection Settings** button is also available, which clears the saved instance URL, username, and password after a confirmation prompt.

### Catalog Items

The **Catalog Items** card lists every catalog item configuration in the project, along with its status (Active/Inactive) and trigger form. A single project can integrate with any number of catalog items — each has its own mappings and trigger settings. Click **Add Catalog Item** to create a new configuration, or use the row actions to **Edit** or **Delete** an existing one.

Adding or editing a catalog item opens an editor with the following sections:

#### General Settings

| Setting | Required | Description |
|---|---|---|
| Label | Yes | A descriptive name to identify this configuration (e.g., "IT Access Request"). Shown in the catalog items list and in the submission log. |
| Active | No | When unchecked, this catalog item will never be triggered, even if its trigger conditions are met. Useful for temporarily pausing an integration without deleting its configuration. |

#### Catalog Item & Field Mapping

Enter the catalog item's 32-character **sys_id** and click **Load Fields**. The module fetches the item's variables directly from ServiceNow and displays them in a mapping table showing, for each variable:

- **Variable Name** — the internal ServiceNow variable name
- **Display Label** — the question label shown to users in ServiceNow
- **Type** — the ServiceNow variable type
- **Attributes** — indicates required variables and provides a possible-values action for supported choice variables
- **REDCap Field** — a dropdown to select the REDCap field whose value should populate this variable

Inactive and non-input ServiceNow variables are omitted from the table. After fields have been loaded, every displayed variable marked **Required** must be mapped before the catalog item can be saved. Variables you leave unmapped are not included in the submission.

For choice-type variables, a button is available to view the variable's possible values as defined in ServiceNow — helpful when deciding which REDCap field to map.

Form completion status fields (`<form>_complete`) are available for mapping alongside regular fields.

The loaded variable definitions are stored with the catalog item configuration and are not checked automatically for later ServiceNow changes. Use **Reload Fields** to retrieve the current definitions, then review the mappings before saving.

#### Matching Values

REDCap choice fields (radio, dropdown, checkbox, yes/no, true/false, and form completion status) usually store coded values (e.g., `1`, `2`, `3`) that will not match the values a ServiceNow choice variable expects. The **Match Values** feature solves this: for any mapped choice field, you can specify exactly which ServiceNow value should be sent for each REDCap choice.

A few important details:

- When at least one value match is saved for a variable, the mapping is **authoritative**: only REDCap values you have explicitly matched are sent.
- With a saved value mapping, any REDCap choice set to **Do not send** (or left unmatched) is omitted. For a non-checkbox field, this means the variable is left out of the submission entirely when the record has that value.
- If every choice is set to **Do not send**, no value mapping is saved and the raw REDCap value is sent. Leave the ServiceNow variable itself unmapped if it should never be sent.
- If no value matching is configured for a variable, the raw REDCap value is sent as-is.

#### Attachments

Select one or more REDCap *File Upload* fields. When a submission succeeds, any files uploaded to those fields on the record are attached to the newly created request item (RITM) in ServiceNow.

Notes:

- Only *File Upload* fields are listed.
- Attachments are uploaded as a follow-up step after the RITM is created (the ServiceNow ordering endpoint cannot carry files). If the RITM creation fails, no attachments are sent.
- Each attachment upload is logged individually. Failed uploads appear in the [Submission Log](#submission-log) with an **Attachment Failed** status and trigger a [failure notification](#failure-notifications).

#### Trigger Settings

These settings control exactly when this catalog item is submitted to ServiceNow.

| Setting | Required | Description |
|---|---|---|
| Trigger Form | No | The REDCap instrument that triggers the submission. Whenever this form is saved (data entry or survey), the module evaluates the push criteria and submits the request if they pass. A configuration without a trigger form is saved but never submits. |
| Push Criteria | No | An optional set of condition groups based on the record's data. If no criteria are defined, the submission fires on every save of the trigger form. |

Criteria are organized into **condition groups**. Every condition within a group must pass (AND logic); if there is more than one group, the submission fires as soon as **any** group fully passes (OR logic). This lets you express rules like "(Field 1 is A AND Field 2 is B) OR (Field 1 is C)", including combinations of checkbox options — e.g. add a group for "Symptoms: Fever is Checked" and another group for "Symptoms: Cough is Checked" to fire when *either* option is checked. Use **Add Condition Group** to add an OR'd group, and **Add Condition** within a group to add an AND'd condition.

Each condition consists of a REDCap field, an operator, and (where applicable) a comparison value. The supported operators are:

| Operator | Passes when... |
|---|---|
| Equals | The field's value exactly equals the comparison value |
| Not equals | The field's value does not equal the comparison value |
| Contains | The field's value contains the comparison value |
| Is not empty | The field has any value |
| Is empty | The field has no value |

Criteria compare a single value against the field. For checkbox fields, select the specific checkbox option (shown as "Field Label: Choice Label") and use **Equals**/**Not equals** with a value of **Checked** or **Unchecked**.

> 💡 **Tip:** A common pattern is to add a condition like `my_form_complete` **Equals** `2` so requests are only submitted when the form is marked Complete, or to use a dedicated "Submit to ServiceNow?" yes/no field as a gate.

> 🚩 **Note:** The module currently does not track which records have already been submitted. If the trigger form is saved again and the criteria still pass, a **new** request will be created in ServiceNow. Design your trigger criteria accordingly (e.g., gate on a status field that changes after submission).

#### Failure Notifications

| Setting | Required | Description |
|---|---|---|
| Notification Email Addresses | No | One or more email addresses to notify when a submission for this catalog item fails. Separate multiple addresses with commas, semicolons, or new lines. Leave blank to turn off notifications for this catalog item. |

Each address receives a separate email when:

- **The submission fails** — ServiceNow rejects the request, cannot be reached, or the connection settings are invalid. No request item is created.
- **An attachment cannot be uploaded** — the request was created, but one or more configured files could not be attached to it. A single email lists every file that failed.

The email includes the project, catalog item, record ID (linked to the record home page), event (longitudinal projects), trigger form, instance (repeating instances), request number (attachment failures only), the failure reason(s), and a link to the [Submission Log](#submission-log). Mapped record values are never included.

Emails are sent from the REDCap "do not reply" address if one is configured, otherwise from the system contact email. Each failure sends its own email (there is no throttling), so an outage on the ServiceNow side during a busy period can generate many notifications. If REDCap cannot send a notification, a `ServiceNow failure notification could not be sent` entry is written to the External Module logs.

### Submission Log

The **Submission Log** tab lists every request item (RITM) submission attempt made from the project — successful submissions, failed submissions, and attachment uploads that failed. The table is searchable, sortable, and paginated, and includes:

| Column | Description |
|---|---|
| Timestamp | When the submission occurred |
| Status | **Success**, **Failed** (no request was created), or **Attachment Failed** (the request was created but a file could not be attached) |
| Record | The REDCap record ID, linked to the record home page |
| Catalog Item | The label of the catalog item configuration that fired |
| Trigger Form | The instrument whose save triggered the submission |
| Request Number | The ServiceNow request number (REQ), linked directly to the request in your ServiceNow instance |
| Failure Reason | For failures, the error returned by ServiceNow (with the HTTP status code when available), or a description of the connection or attachment problem |

Use the **Show** dropdown to display all entries, successful submissions only, or failures only. Use the search box to filter by record, catalog item label, trigger form, request number, failure reason, or attachment file name.

## What Gets Sent to ServiceNow

For each triggered submission, the module makes a `POST` to the ServiceNow Service Catalog API's `order_now` endpoint for the configured catalog item, with:

- **Quantity:** 1
- **Variables:** a map of ServiceNow variable name → value built from the record's data using your field and value mappings

Details of how values are prepared:

- Values are taken from the saved record in the event/instance that was saved (repeating instrument data is supported).
- **Checkbox fields** send the checked choices as a comma-separated list (after applying any value matching). When a saved value mapping exists, the variable is omitted if none of the checked choices has an explicit match.
- **All other fields** send their stored value, translated through value matching if configured.
- Mapped fields whose REDCap value is not present are skipped.

Nothing else from the record is transmitted — only the variables you have explicitly mapped (plus any configured file attachments).

## Logging and Troubleshooting

The module logs extensively to the External Module logs:

- Every RITM submission, successful or failed, including the HTTP status and error message returned by ServiceNow for failures (these entries power the [Submission Log](#submission-log))
- Every attachment upload, successful or failed (failed uploads also appear in the Submission Log)
- Any [failure notification](#failure-notifications) email that REDCap could not send

Verbose debug entries (trigger evaluation, record data, and submitted variables) can be enabled by a developer by setting the `DEBUG` constant in the module's main class to `true`. This is disabled by default. Because these debug entries may contain REDCap record values, only enable them temporarily for troubleshooting and ensure access to the External Module logs is limited appropriately.

If submissions are not appearing in ServiceNow, check the following:

1. Is the module enabled in the project, and is the catalog item marked **Active**?
2. Does the saved instrument match the configured **Trigger Form**?
3. Does at least one **Push Criteria** condition group pass for the record in question?
4. Does the **Test Connection** button succeed with the saved credentials?
5. Check the [Submission Log](#submission-log) (set **Show** to *Failures only*) for failed submissions and the reason ServiceNow gave, or the External Module logs for full details.

## Security Considerations

- The ServiceNow instance URL must use `https://`. Request URLs are rebuilt from only the validated host (and optional port) of the saved instance URL plus fixed API paths — any path, query string, or embedded credentials in the setting are discarded, and record identifiers (sys_ids) are sanitized to hexadecimal before being placed in a URL. Submitted data can never change where a request is sent.
- Outgoing submission data is sanitized before transmission: only variables whose names match ServiceNow's variable-name format are sent, values must be simple (scalar) values, and control characters are stripped.
- The configuration page and all of its actions are restricted to REDCap administrators.
- ServiceNow credentials are stored as module project settings. The saved password is not displayed back in the browser.
- Use a dedicated ServiceNow service account with the minimum access required (Service Catalog API for the relevant items, `sc_req_item` read, and Attachment API if using attachments) rather than a personal account.
- Only the specific field values you map are sent to ServiceNow. Review your mappings carefully before activating a catalog item if your project contains identifiers or other sensitive data.
- Failure notification emails contain the record ID and the failure reason, but no mapped record values. Note that a ServiceNow error message may occasionally quote a submitted value. Only add recipients who are permitted to see the project's record IDs, and keep in mind that the record ID itself may be an identifier in some projects.

## FAQ

1. **Do REDCap users need ServiceNow accounts?**

   No. All API calls are authenticated using the single service account configured in Connection Settings. If the catalog workflow needs the identity of the REDCap user or requester, map an appropriate REDCap field (e.g., email or name) to a catalog item variable.

2. **Can one project submit to multiple catalog items?**

   Yes. Add as many catalog item configurations as you need — each with its own trigger form, criteria, and mappings. Multiple catalog items can even share the same trigger form; each is evaluated independently.

3. **Do surveys trigger submissions?**

   Yes. The submission is triggered whenever the trigger form is saved, whether by a data entry user or a survey respondent.

4. **What happens if the same record is saved twice?**

   If the trigger form is saved again and the criteria still pass, a second request is created in ServiceNow. Use push criteria (e.g., a status field) to control this.

5. **Where do I find a catalog item's sys_id?**

   In ServiceNow, open the catalog item's record (e.g., in the `sc_cat_item` table or via the catalog item's URL) and copy the 32-character `sys_id` value.

## Changelog

See the [releases page](../../releases) for a full changelog.