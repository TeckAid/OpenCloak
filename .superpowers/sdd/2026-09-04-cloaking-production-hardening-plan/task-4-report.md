# Task 4 report — campaign/link persistence, URL actions, and tenant ownership

Date: 2026-09-04
Baseline: `9c481fa`

## Scope completed

Implemented the Task 4 hardening changes in:

- `admin/campaigns.php`
- `admin/links.php`
- `admin/domains.php`
- `api/index.php`
- `includes/rules.php`
- `includes/security.php`
- `index.php`
- `tests/Unit/RulesTest.php`
- `tests/Integration/HttpTest.php`

## What changed

### Shared parsing and persistence

- Added shared named-field parsers in `includes/rules.php`:
  - `parse_campaign_input()`
  - `parse_link_input()`
  - supporting typed scalar/flag/id helpers
- Removed duplicated positional campaign/link persistence logic from admin/API create and update flows.
- Added shared mutable-column lists so create/update/clone paths bind the same named values consistently.

### URL validation and delivery safety

- Enforced strict validation for:
  - primary offer URLs
  - offer pool URLs
  - route-target URLs
- Validation now happens before persistence and again before iframe/meta/header delivery.
- Invalid delivery targets fall back to the white page instead of emitting unsafe redirect/meta output.

### Checkbox and enum correctness

- Added explicit zero-default handling for checkbox-backed fields on create/update.
- Fixed edit rendering to reflect persisted `is_active` state.
- Standardized redirect enum handling, including `303` and `meta`, across admin, API, parsing, and public delivery.

### Ownership and delete safety

- Added user-ownership checks for `campaign_id` and `domain_id` relationships on create/update.
- Cross-tenant references are rejected instead of being silently accepted.
- Domain deletion now blocks while referenced by links.
- Campaign deletion now blocks while referenced by links.
- Deletes no longer silently republish or detach links in a way that changes behavior unexpectedly.

### Rule/runtime hardening

- Switched OS minimum-version comparison to `version_compare()` semantics.
- Preserved dotted OS versions during parsing.
- Reworked delay-start uniqueness logic to use:
  - one transaction
  - `< limit` semantics
  - unique scope/IP behavior for non-permanent rules

### API route handling

- Tightened malformed resource handling in `api/index.php`.
- Invalid resource ids / extra segments now return `404`.
- Wrong methods on supported resource shapes return `405`.

## Test coverage added

Added/expanded tests for:

- campaign create/update persistence across fields
- link create/update persistence across fields
- unchecked checkbox defaults
- invalid primary/pool/route URLs
- `303` redirect handling
- `meta` delivery fallback behavior
- version `14.10` vs `14.9`
- delay-start limit/uniqueness behavior
- cross-tenant campaign/domain references
- safe domain deletion
- malformed API routes and methods

## Verification evidence

Focused Task 4 tests:

- `php -r 'require_once "tests/bootstrap.php"; require_once "tests/Unit/RulesTest.php"; require_once "tests/Integration/HttpTest.php"; $classes=["RulesTest","HttpTest"]; $failed=0; foreach($classes as $class){ $case=new $class(); foreach($case->run() as $result){ $status=$result["passed"]?"PASS":"FAIL"; $line=sprintf("%s::%s %s (%.2fms)",$class,$result["name"],$status,$result["duration_ms"]); if(!$result["passed"]){ $line.=" - ".$result["message"]; $failed++; } echo $line.PHP_EOL; }} exit($failed>0?1:0);'`
  - Result: all `RulesTest` and `HttpTest` cases passed.

Full suite:

- `php /Users/nasir/Documents/GitHub/Cloaking/tests/run.php`
  - Result: full suite passed, including `CredentialsTest`, `HttpTest`, `RulesTest`, and `SecurityTest`.

Syntax/lint:

- `php -l /Users/nasir/Documents/GitHub/Cloaking/admin/campaigns.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/admin/domains.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/admin/links.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/api/index.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/includes/rules.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/includes/security.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/index.php`
  - Result: no syntax errors in any modified PHP file.

## Remaining concern

- Delay-start uniqueness is now enforced transactionally within the current file scope, but this task did not add a database-level unique constraint because `includes/database.php` / schema changes were outside the scoped file list. The runtime behavior required by Task 4 is covered by tests and the transactional implementation.
