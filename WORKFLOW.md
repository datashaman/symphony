---
tracker:
  kind: github
  repository: datashaman/my-project
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
  root: /tmp/symphony_workspaces
  hooks:
    after_create:
      - "git clone https://github.com/datashaman/my-project.git ."
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
