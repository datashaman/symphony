---
tracker:
  kind: jira
  endpoint: $JIRA_BASE_URL
  project_slug: SOL
  email: $JIRA_EMAIL
  api_key: $JIRA_API_TOKEN
  active_states:
    - To Do
    - In Progress
  terminal_states:
    - Done
    - Closed
    - Cancelled
  # assignee: currentUser()    # default — only your tickets
  # sprint: openSprints()      # default — current sprint only
  # assignee: none             # all assignees
  # sprint: none               # all sprints
  # jql: "project = SOL AND ..." # full override

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
