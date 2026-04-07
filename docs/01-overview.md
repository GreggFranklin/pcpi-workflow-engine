# PCPI Workflow Engine – Overview

## Purpose

The PCPI Workflow Engine is the orchestration layer that defines and
enforces multi-form workflows across the PCPI system.

It coordinates:

-   Gravity Forms (data collection)
-   Gravity PDF (document rendering)
-   Staff Dashboard (entry management)
-   Site Access Control (signed links / routing)

If a workflow is correctly defined in the registry, the rest of the
system behaves deterministically.

------------------------------------------------------------------------

## What This Plugin Owns

The Workflow Engine is responsible for:

-   Defining workflows (registry)
-   Linking entries across forms
-   Generating questionnaire & review URLs
-   Prefilling questionnaire fields from Applicant entries
-   Acting as the authoritative workflow contract

It intentionally does NOT:

-   Render staff UI (Staff Dashboard)
-   Enforce access rules (Site Access Control)
-   Store PDFs (Gravity PDF)

------------------------------------------------------------------------

## Core Mental Model

Standard workflow chain:

Applicant → Questionnaire → Review → PDF

Relationships are explicit and registry-driven. Nothing is inferred.

If any required relationship field is missing or misconfigured, failures
will occur.

------------------------------------------------------------------------

## Related Documentation

-   02-architecture.md
-   03-workflow-contract.md
-   04-workflow-creation-checklist.md
