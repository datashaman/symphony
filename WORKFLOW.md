---
tracker:
  kind: github
  api_key: $GITHUB_TOKEN
  active_states:
    - todo
    - in-progress
  terminal_states:
    - done
    - closed
    - cancelled

polling:
  interval_ms: 30000

workspace:
  setup:
    - cp %BASE%/.env .
    - composer install --no-interaction --no-progress

agent:
  max_concurrent_agents: 5
  max_turns: 20
---

You are working on issue {{ issue.identifier }}: {{ issue.title }}

{{ issue.description }}

{% if attempt %}
This is retry attempt {{ attempt }}. Review what was done previously and continue from where you left off.
{% endif %}

## Prime Directive

After completing all work, you MUST commit, push, and open a pull request. This is the entire point of your task — unshipped work has zero value.

1. **Commit** all changes with a clear, descriptive commit message referencing {{ issue.identifier }}.
2. **Push** the current branch to the remote.
3. **Create a pull request** with:
   - A concise title summarizing the change
   - A body that describes what was done and includes "Closes {{ issue.url | default(issue.identifier) }}"
   - Target the repository's default branch (main/master)

Do NOT stop after making code changes. The pull request is your deliverable.
