## Why

The project README.md is the default Laravel Zero template and contains zero information about Symphony. There is no CLAUDE.md, no .env.example, and no user-facing documentation beyond raw OpenSpec specs. A developer discovering this project has no way to understand what it does, how to configure it, or how to run it without reading the source code.

## What Changes

- **Replace README.md** with a comprehensive project README covering purpose, quick start, configuration, architecture overview, and supported trackers (GitHub, Jira)
- **Create CLAUDE.md** with project conventions, architecture notes, and development guidelines for AI-assisted coding sessions
- **Create .env.example** documenting all environment variables the application expects (API tokens, workspace paths, etc.)
- **Create docs/ directory** following the Diataxis framework:
  - **Tutorial**: Step-by-step guide to get Symphony running against a GitHub project
  - **How-to guides**: Workflow configuration, Jira setup, custom hooks, retry tuning
  - **Reference**: Configuration schema, Issue DTO fields, CLI arguments, environment variables
  - **Explanation**: Architecture overview, orchestration loop, multi-turn agent sessions, process model

## Capabilities

### New Capabilities

- `documentation`: User-facing documentation suite following the Diataxis framework, covering README, CLAUDE.md, .env.example, and docs/ directory structure

### Modified Capabilities

_(none - this change adds documentation only, no behavioral changes to existing specs)_

## Impact

- **Files created**: README.md (rewrite), CLAUDE.md, .env.example, docs/tutorial.md, docs/how-to/*.md, docs/reference/*.md, docs/explanation/*.md
- **No code changes**: Documentation only - no application behavior is modified
- **Dependencies**: None added or removed
- **Breaking**: None
