---
tracker:
  kind: jira
  endpoint: https://your-domain.atlassian.net
  project_slug: PROJ
  email: $JIRA_EMAIL
  api_key: $JIRA_API_TOKEN
  active_states:
    - To Do
    - In Progress
  terminal_states:
    - Done
    - Closed
    - Cancelled

polling:
  interval_ms: 30000

workspace:
  root: /tmp/symphony_workspaces
  hooks:
    after_create:
      - "git clone https://github.com/your-org/your-repo.git ."
    before_run:
      - "git pull origin main"

agent:
  max_concurrent_agents: 5
  max_turns: 20

codex:
  command: "claude -p --output-format stream-json"
  turn_timeout_ms: 3600000
  stall_timeout_ms: 300000
---

You are working on issue {{ issue.identifier }}: {{ issue.title }}

{{ issue.description }}

{% if attempt %}
This is retry attempt {{ attempt }}. Review what was done previously and continue from where you left off.
{% endif %}
