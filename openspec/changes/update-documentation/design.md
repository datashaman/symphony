## Context

Symphony is a fully implemented PHP orchestration daemon with comprehensive OpenSpec specifications and a complete test suite, but its user-facing documentation consists of the default Laravel Zero README template. There is no CLAUDE.md, no .env.example, and no structured documentation. A developer or AI assistant encountering this project for the first time has to read source code to understand what it does.

The project already has detailed internal specs at `openspec/specs/` covering all subsystems. The documentation effort should leverage these specs as source material rather than duplicating effort.

## Goals / Non-Goals

**Goals:**
- Replace the placeholder README with a project-specific one that explains what Symphony is, how to install it, and how to run it
- Create CLAUDE.md so AI coding sessions start with correct project context
- Create .env.example documenting all environment variables
- Establish a docs/ directory following the Diataxis framework (tutorials, how-to guides, reference, explanation)
- Documentation SHALL be accurate against the current codebase (single commit: initial implementation)

**Non-Goals:**
- No code changes, refactoring, or new features
- No auto-generated API documentation tooling (e.g., phpDocumentor)
- No hosted documentation site (GitHub Pages, ReadTheDocs) - plain markdown files only
- No versioned documentation - this covers the current state only
- No changes to OpenSpec internal specs

## Decisions

### 1. Diataxis framework for docs/ structure

**Decision**: Organize `docs/` into four Diataxis quadrants: tutorials, how-to, reference, explanation.

**Rationale**: Diataxis is a well-established documentation framework that separates learning-oriented (tutorials), task-oriented (how-to), information-oriented (reference), and understanding-oriented (explanation) content. This prevents the common failure mode of mixing "getting started" with "API reference" in a single document.

**Alternative considered**: Single flat docs/ with topic-based files. Rejected because it tends to produce documents that try to serve multiple audiences and do none well.

### 2. README as gateway, not comprehensive docs

**Decision**: Keep README.md focused (project description, quick start, link to docs/) rather than putting everything in one file.

**Rationale**: A long README is hard to navigate and maintain. The README should answer "what is this?" and "how do I get started?" in under 2 minutes, then point to docs/ for depth.

### 3. CLAUDE.md scope

**Decision**: CLAUDE.md will contain project architecture summary, coding conventions, test commands, and development workflow - things an AI assistant needs to be productive immediately.

**Rationale**: CLAUDE.md is read at the start of every AI coding session. It should be concise and actionable, not a full architecture document (that lives in docs/explanation/).

### 4. .env.example derived from WorkflowConfig and tracker implementations

**Decision**: Extract all environment variables from the codebase (WorkflowConfig.php, GitHubTracker.php, JiraTracker.php, WORKFLOW.md templates) and document them with comments in .env.example.

**Rationale**: Environment variables are currently only discoverable by reading workflow templates and source code. A single .env.example makes setup straightforward.

### 5. Documentation file structure

**Decision**:
```
docs/
  tutorial/
    getting-started.md          # First run with GitHub tracker
  how-to/
    configure-github-tracker.md
    configure-jira-tracker.md
    write-workflow-templates.md
    tune-retry-and-timeouts.md
    configure-workspace-hooks.md
  reference/
    configuration.md            # Full YAML schema with defaults
    cli.md                      # CLI arguments and usage
    environment-variables.md    # All env vars
    issue-dto.md                # Issue object fields
  explanation/
    architecture.md             # System design, component diagram
    orchestration-loop.md       # Tick cycle, reconciliation
    multi-turn-sessions.md      # Agent session lifecycle
    process-model.md            # Forking, signal handling, cleanup
```

**Rationale**: Each file has a clear single purpose. The structure maps directly to Diataxis quadrants and covers the gaps identified in the codebase analysis.

## Risks / Trade-offs

- **[Documentation drift]** Documentation may become stale as code evolves. -> Mitigation: CLAUDE.md instructs contributors to update docs when changing behavior. Keep docs close to the code they describe.
- **[Over-documentation]** Creating too many files for a young project may feel heavy. -> Mitigation: Start with the essential files only; each document should earn its existence by answering a real question.
- **[OpenSpec duplication]** Some docs/reference content overlaps with openspec/specs/. -> Mitigation: docs/ targets end users; openspec/ targets implementers. Different audiences justify separate documents, but reference docs should cite specs where appropriate.
