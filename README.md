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
    - [Save Results to REDCap](#save-results-to-redcap)
    - [Failure Notifications](#failure-notifications)
  - [Submission Log](#submission-log)
- [Confirmation Receipts](#confirmation-receipts)
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
- Save the resulting ServiceNow request number, request item (RITM) number, and submission status back to the record, e.g. to show respondents a [confirmation receipt](#confirmation-receipts)
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
6. If [result fields](#save-results-to-redcap) are configured, the module saves the request number, RITM number, and submission status back to the record.
7. If any REDCap *File Upload* fields are configured as attachments, the module uploads those files to the newly created RITM.
8. The outcome (success or failure) is logged and appears in the module's [Submission Log](#submission-log). If the submission fails, or a follow-up step (attachments, saving results) fails, the catalog item's [failure notification](#failure-notifications) recipients (if any) are emailed.

All of this happens in the background — the person saving the REDCap record does not need to do anything special, and they do not need a ServiceNow account.

### What you'll need

On the ServiceNow side:

- The URL of your ServiceNow instance (e.g., `https://your-instance.service-now.com`)
- A ServiceNow service account (username and password) that:
  - Can authenticate with **basic authentication**
   - Can read the catalog list and has access to the **Service Catalog API** (`sn_sc`) for the catalog item(s) you want to order
  - Has read access to the `sc_req_item` table via the **Table API** (used to look up the RITM created by each submission, for the Submission Log, attachments, and saving the RITM number to REDCap)
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
7. (Optional) Choose **Save Results to REDCap** fields and enter one or more **Failure Notification** email addresses.
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

> 🚩 **Note:** The module does not itself track which records have already been submitted. If the trigger form is saved again and the criteria still pass, a **new** request will be created in ServiceNow. Design your trigger criteria accordingly. The simplest approach is to [save the request number to a field](#save-results-to-redcap) and add a condition that the field **Is empty**. Because a failed submission clears that field, the record is then submitted once, and is retried on the next save if it fails.

#### Save Results to REDCap

After each submission, the module can write the outcome back to the record so it can be piped into survey completion text, confirmation emails, alerts, or reports. See [Confirmation Receipts](#confirmation-receipts) for a step-by-step example.

| Setting | Required | Value saved after a successful submission | Value saved after a failed submission |
|---|---|---|---|
| Request Number | No | The ServiceNow request number, e.g. `REQ0012345` | *(cleared)* |
| Request Item (RITM) Number | No | The request item number, e.g. `RITM0012345` | *(cleared)* |
| Submission Status | No | `Submitted` | `Failed` |

Details:

- Only **text fields without validation** and **notes fields** can be selected, and never the record ID field. Choose a different field for each setting, and (if several catalog items share a trigger form) for each catalog item.
- Values are written to the same record and event that triggered the submission. Fields on the trigger form are written to the same repeating instance. Fields on a non-repeating instrument are also supported. Fields on a *different* repeating instrument are not, because the instance to update would be ambiguous.
- The values are saved during the record save, **before** REDCap displays the survey completion text or sends the survey confirmation email, so both can pipe them.
- Saving uses REDCap's standard data import, so the change appears in the project's Logging page. Alerts that use the trigger *"When conditional logic is TRUE during a data import, data entry, or survey"* can react to it.
- The RITM number comes from a lookup the module makes after every successful submission (the ordering endpoint returns only the request number). If that lookup fails, the RITM Number field is cleared and the problem is reported as **Save to REDCap Failed**.
- If the values cannot be saved (for example, the record or form is locked, or a selected field was deleted or changed type), the submission itself is unaffected. The problem appears in the [Submission Log](#submission-log) with the status **Save to REDCap Failed** and triggers a [failure notification](#failure-notifications).

#### Failure Notifications

| Setting | Required | Description |
|---|---|---|
| Notification Email Addresses | No | One or more email addresses to notify when a submission for this catalog item fails. Separate multiple addresses with commas, semicolons, or new lines. Leave blank to turn off notifications for this catalog item. |

Each address receives a separate email when:

- **The submission fails** — ServiceNow rejects the request, cannot be reached, or the connection settings are invalid. No request item is created.
- **A follow-up step fails** — the request was created, but one or more configured files could not be attached to it, or the [results could not be saved to REDCap](#save-results-to-redcap). A single email lists every problem.

The email includes the project, catalog item, record ID (linked to the record home page), event (longitudinal projects), trigger form, instance (repeating instances), request and request item numbers (when a request was created), the failure reason(s), and a link to the [Submission Log](#submission-log). Mapped record values are never included.

Emails are sent from the REDCap "do not reply" address if one is configured, otherwise from the system contact email. Each failure sends its own email (there is no throttling), so an outage on the ServiceNow side during a busy period can generate many notifications. If REDCap cannot send a notification, a `ServiceNow failure notification could not be sent` entry is written to the External Module logs.

### Submission Log

The **Submission Log** tab lists every request item (RITM) submission attempt made from the project — successful submissions, failed submissions, and attachment uploads that failed. The table is searchable, sortable, and paginated, and includes:

| Column | Description |
|---|---|
| Timestamp | When the submission occurred |
| Status | **Success**; **Failed** (no request was created); **Attachment Failed** (the request was created but a file could not be attached); or **Save to REDCap Failed** (the request was created but the [result fields](#save-results-to-redcap) could not be updated) |
| Record | The REDCap record ID, linked to the record home page |
| Catalog Item | The label of the catalog item configuration that fired |
| Trigger Form | The instrument whose save triggered the submission |
| Request Number | The ServiceNow request number (REQ), linked directly to the request in your ServiceNow instance |
| Request Item | The request item number (RITM), linked directly to the request item in your ServiceNow instance. Blank for entries logged before this column was added, or if the module could not look up the RITM (check that the service account can read the `sc_req_item` table) |
| Failure Reason | For failures, the error returned by ServiceNow (with the HTTP status code when available), or a description of the connection or attachment problem |

Use the **Show** dropdown to display all entries, successful submissions only, or failures only. Use the search box to filter by record, catalog item label, trigger form, request number, request item number, failure reason, or attachment file name.

## Confirmation Receipts

A common goal is to give the person who submitted the request a receipt that confirms it was submitted, gives the ServiceNow request and RITM numbers, and recaps what was submitted. The module doesn't send receipts itself. Instead, it [saves the results to the record](#save-results-to-redcap), and REDCap's own features — the survey completion text, the survey confirmation email, and Alerts & Notifications — deliver the receipt using piping (see the **How to use Piping here** link next to any piping-enabled setting in REDCap).

### Step 1: Add fields to hold the results

Add these fields to the **trigger form** (the survey). Text Box fields need **no validation**.

| Variable (example) | Field type | Action tags | Purpose |
|---|---|---|---|
| `sn_request_number` | Text Box | `@HIDDEN-SURVEY` | Request number (REQ) |
| `sn_ritm_number` | Text Box | `@HIDDEN-SURVEY` | Request item number (RITM) |
| `sn_submission_status` | Text Box | `@HIDDEN-SURVEY` | `Submitted` or `Failed` |

`@HIDDEN-SURVEY` keeps the fields off the survey page but leaves them visible to staff on the data entry form. The variable names are only examples; any names work.

> ⚠️ If the project is in production, add the fields through Draft Mode and get the changes approved before selecting them in the module.

### Step 2: Select the fields in the module

Edit the catalog item, select the three fields under **Save Results to REDCap**, and save.

**Recommended:** also add a push criterion `sn_request_number` **Is empty**, so each record is only submitted once. A failed submission clears the field, so re-saving the form retries it.

### Step 3: Write the completion text

In **Survey Settings → Survey Completion Text**, pipe the result fields and whichever submitted fields you want to recap:

```html
<p><strong>Thank you. Your request has been received.</strong></p>
<p>Request number: [sn_request_number]<br>
   Request item: [sn_ritm_number]</p>
<p>Here is a summary of what you submitted:</p>
<ul>
  <li>Requested for: [requested_for_name] ([requested_for_email])</li>
  <li>Job type: [job_type]</li>
</ul>
```

Multiple choice fields pipe their label by default (add `:value` for the raw code). Checkbox fields pipe a comma-separated list of checked labels.

### Step 4: Send a confirmation email (optional)

You have two options:

- **Survey confirmation email** (*Survey Settings → Send confirmation email?*). Pipe the same fields into the subject and message. The email goes to the respondent's email address field. You can also attach a PDF of the completed survey, but REDCap warns against this if the survey collects identifiers.
- **Alerts & Notifications.** Create an alert with the trigger *"When conditional logic is TRUE during a data import, data entry, or survey"* and the logic `[sn_submission_status] = "Submitted"`, send it to the requester's email field, and set it to send only once. A second alert with `[sn_submission_status] = "Failed"` can send a "we're looking into it" message instead.

### Handling failed submissions

The survey completion text and the survey confirmation email **cannot be made conditional**. If the submission fails, they are still shown/sent, with blank request numbers. Choose one of these approaches:

1. **Use neutral wording** and pipe the status, e.g. *"Submission status: [sn_submission_status]. Request number: [sn_request_number:hideunderscore]"*. (`:hideunderscore` pipes a blank value instead of `______`.)
2. **Use a calculated message field.** Add a hidden `@CALCTEXT` field and pipe it into the completion text and email:

   ```
   @HIDDEN-SURVEY @CALCTEXT(if([sn_submission_status] = "Submitted", concat("Your request number is ", [sn_request_number], " (", [sn_ritm_number], ")."), "We could not submit your request automatically. Our team has been notified and will follow up."))
   ```

   REDCap recalculates this field on the server after the module saves the results, unless auto-calculations are disabled for the project. Confirm the wording with a test submission. Only promise that "our team has been notified" if [failure notifications](#failure-notifications) are configured.
3. **Use two Alerts** (success and failure) instead of the survey confirmation email, as described in Step 4.

### Why this works

When a survey is submitted, REDCap runs the module's save hook — which submits the request to ServiceNow and saves the results to the record — **before** it sends the survey confirmation email and before it displays the completion text. Both read the record's data at that moment, so the request numbers are already filled in. The respondent waits on the final page while this happens (normally a second or two, plus the time to upload any attachments).

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
- Any failure to save [results back to the record](#save-results-to-redcap) (also shown in the Submission Log)
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

   If the trigger form is saved again and the criteria still pass, a second request is created in ServiceNow. To prevent this, [save the request number to a field](#save-results-to-redcap) and add a push criterion that the field **Is empty**.

5. **Can the person who submitted the request get a receipt with the request number?**

   Yes. See [Confirmation Receipts](#confirmation-receipts).

6. **Where do I find a catalog item's sys_id?**

   In ServiceNow, open the catalog item's record (e.g., in the `sc_cat_item` table or via the catalog item's URL) and copy the 32-character `sys_id` value.

## Changelog

See the [releases page](../../releases) for a full changelog.