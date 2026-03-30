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

agent:
  max_concurrent_agents: 5
  max_turns: 20
---

You are working on issue {{ issue.identifier }}: {{ issue.title }}

{{ issue.description }}

{% if attempt %}
This is retry attempt {{ attempt }}. Review what was done previously and continue from where you left off.
{% endif %}
