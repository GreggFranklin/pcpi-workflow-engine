# PCPI Workflow Engine

This plugin provides a workflow registry and two universal single-page renderers:

- **Questionnaire page**: renders the workflow’s *source* (questionnaire) form.
- **Review page**: renders the workflow’s *source* form (read-only, prefilled from an entry) and injects the workflow’s *review/comments* fields beneath matching sections.

---

## Pages

Create two WordPress pages (default slugs):

### 1) Questionnaire page
Slug: `questionnaire`

Content:
`[pcpi_questionnaire]`

URL:
`/questionnaire/?workflow=<key>`

Optional Applicant → Questionnaire prefill:
`/questionnaire/?workflow=<key>&parent_applicant_entry_id=<APPLICANT_ENTRY_ID>`

### 2) Review page
Slug: `review`

Content:
`[pcpi_review]`

URL:
`/review/?workflow=<key>&entry_id=<SOURCE_ENTRY_ID>`

> Legacy shortcode alias is still supported: `[pcpi_review_mode]`

---

## Section → Comment Injection

Add matching CSS classes:

- On the **source** form: section fields get `pcpi-section-<key>`
- On the **review** form: comment fields get `pcpi-comment-<key>`

Example key: `medical`

- Source section CSS class: `pcpi-section-medical`
- Comment field CSS class: `pcpi-comment-medical`

This is what keeps the review UX identical to the questionnaire while allowing extra examiner comment fields.

---

## Debug Logging

Enable logs:

```php
add_filter('pcpi_workflow_engine_debug', '__return_true');
```

Logs go to PHP `error_log()`.

---

## Workflow Registry

Override workflows:

```php
add_filter('pcpi_workflow_engine_workflows', function($workflows){
  // edit $workflows here
  return $workflows;
});
```

---

## Notes on Your Current Setup

You said your Applicant form is **Form ID 4**. The default `polygraph` workflow is configured with:

- `applicant_form_id` = 4
- `source_form_id` = 2
- `review_form_id` = 23

Update these in the registry (or via filter) to match your environment.


## Applicant Form Workflow Dropdown

On Applicant Form (ID 4), add a Dropdown field "Workflow".

- Enable **Show Values**.
- Set each choice VALUE to the workflow key (`polygraph`, `employment`, `psych`).

With that, you may use:

`/questionnaire/?parent_applicant_entry_id=<APPLICANT_ENTRY_ID>`

and the plugin will infer `workflow` automatically.
