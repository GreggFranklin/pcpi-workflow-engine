# PCPI Workflow Engine – Workflow Contract

## Overview

Each workflow defined in `trait-registry.php` is a contract.

Changing a workflow affects:

-   Entry relationships
-   Routing
-   PDF generation
-   Dashboard behavior
-   Access control

------------------------------------------------------------------------

## Required Keys

### label

Human-readable workflow name.

### source_form_id

Gravity Forms ID for Questionnaire form.

### review_form_id

Gravity Forms ID for Review form.

### questionnaire_page_path

Frontend route for questionnaire page.

### review_page_path

Frontend route for review page.

### pdf_id

Gravity PDF ID associated with this workflow.

------------------------------------------------------------------------

## Applicant Workflow Keys (Standard Mode)

### applicant_form_id

Initial Applicant form ID. Use `0` or omit for kiosk mode.

### applicant_workflow_field_id

Field ID that stores workflow key on Applicant form.

------------------------------------------------------------------------

## Relationship Anchors

### questionnaire_parent_applicant_field_id

Links Questionnaire → Applicant.

### review_parent_questionnaire_field_id

Links Review → Questionnaire.

### review_parent_applicant_field_id

Links Review → Applicant.

Hidden fields must use stable `inputName` values:

-   `parent_applicant_entry_id`
-   `parent_questionnaire_entry_id`

------------------------------------------------------------------------

## Entry Modes

### Standard

Includes Applicant step.

### Kiosk

Defined via:

``` php
'entry_mode' => 'kiosk'
```

No applicant step.

------------------------------------------------------------------------

## Features Block

Optional behavior flags:

``` php
'features' => [
  'auto_scroll_radios' => true,
  'mark_all_as_no'     => true,
  'overlay_loader'     => true,
  'disable_gf_spinner' => true,
]
```

Feature flags: - Are declarative - Must be accessed defensively - Do
nothing until consumed by code

------------------------------------------------------------------------

## Backward Compatibility Rules

-   Always use safe array access.
-   Never assume keys exist.
-   Additive changes only.
