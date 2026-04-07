# PCPI Workflow Engine – System Architecture

## Architectural Principle

The Workflow Registry is the single source of truth.

All behavior must derive from:

``` php
$workflow = self::get_workflow( $key );
```

No workflow-specific logic should live outside the registry.

------------------------------------------------------------------------

## Core Layers

### Registry (trait-registry.php)

Defines: - Form IDs - Parent relationship field IDs - Routing paths -
PDF ID - Entry mode - Feature flags

Configuration only. No logic.

------------------------------------------------------------------------

### Context Layer

Determines: - Active workflow - Page type (questionnaire / review) -
Entry mode - Entry IDs

------------------------------------------------------------------------

### Assets Layer

Responsible for: - CSS/JS enqueue - Reading feature flags - UI behavior

Must access features defensively:

``` php
$features = $workflow['features'] ?? [];
```

------------------------------------------------------------------------

### Shortcodes

Render: - Questionnaire - Review

Driven entirely by context + registry.

------------------------------------------------------------------------

### Staff Dashboard

Reads registry to: - Display entries - Enable review - Enable PDF -
Determine workflow state

------------------------------------------------------------------------

### Access Control

Handles: - Signed URLs - TTL validation - Permission enforcement

------------------------------------------------------------------------

### Gravity PDF

Renders documents based on configured `pdf_id`.

------------------------------------------------------------------------

## Data Flow

### Standard Mode

Applicant → Questionnaire → Review → PDF

### Kiosk Mode

Questionnaire → Review → PDF

No applicant entry.

------------------------------------------------------------------------

## Design Rules

1.  No hardcoding workflow behavior outside registry.
2.  Registry keys must remain backward compatible.
3.  New behavior must be additive.
4.  Context must be validated before rendering.
