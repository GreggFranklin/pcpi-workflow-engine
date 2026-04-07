# PCPI Workflow Engine – Workflow Creation Checklist

Use this checklist when adding a new workflow.

If any item is incorrect, failures may occur in Review activation, PDF
generation, or cascade delete.

------------------------------------------------------------------------

## 1. Registry Entry

Add workflow to `trait-registry.php` with:

-   label
-   source_form_id
-   review_form_id
-   questionnaire_page_path
-   review_page_path
-   pdf_id

If standard workflow, also include:

-   applicant_form_id
-   applicant_workflow_field_id

Define relationship field IDs.

------------------------------------------------------------------------

## 2. Applicant Form (Standard Mode Only)

-   Form exists.
-   Workflow selector field stores workflow key.
-   Field ID matches registry.

------------------------------------------------------------------------

## 3. Questionnaire Form

Hidden field required:

-   Input Name: `parent_applicant_entry_id`
-   Registry field ID must match.

------------------------------------------------------------------------

## 4. Review Form

Hidden fields required:

-   `parent_questionnaire_entry_id`
-   `parent_applicant_entry_id`

Registry field IDs must match.

------------------------------------------------------------------------

## 5. Page Routing

Ensure WordPress pages exist and contain:

-   `[pcpi_questionnaire]`
-   `[pcpi_review]`

Paths must match registry.

------------------------------------------------------------------------

## 6. Gravity PDF

-   PDF exists under correct form.
-   `pdf_id` belongs to same form as rendered entry.

------------------------------------------------------------------------

## 7. Verification Test

-   Applicant submission works
-   Questionnaire link works
-   Review activates
-   Summary generates PDF
-   Delete cascades properly

------------------------------------------------------------------------

## Golden Rule

Every workflow is a contract. Registry, forms, and PDFs must agree.
