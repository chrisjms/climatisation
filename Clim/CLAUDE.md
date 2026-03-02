# Hôtel Corintel - Project Context

## Project Overview

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP (no framework) |
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Database | MySQL with PDO |

**Do NOT introduce:**
- PHP frameworks (Laravel, Symfony, etc.)
- JavaScript frameworks (React, Vue, Angular, etc.)
- CSS frameworks (Bootstrap, Tailwind, etc.)
- Node.js or npm-based build tools
- Composer dependencies (unless absolutely necessary)

## Development Rules

### Code Style
- Use existing CSS variables (defined in `:root` in style.css)
- Follow existing naming conventions (kebab-case for CSS, camelCase for JS)
- Keep PHP files self-contained (each page includes its own dependencies)
- Use PDO prepared statements for all database queries

### Forms
- POST to same page or dedicated handler
- Server-side validation always required
- Return JSON for AJAX requests
- Use `htmlspecialchars()` for all output

## Security

- Sanitize all user inputs
- Use prepared statements (PDO)
- Session-based admin authentication
- No sensitive data in client-side code
- Validate file uploads (images only, size limits)

## Workflow Orchestration

### 1. Plan Mode Default
Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately - don't keep pushing
- Use plan mode for verification steps, not just building
- Write detailed specs upfront to reduce ambiguity

### 2. Subagent Strategy
- Use subagents liberally to keep main context window clean
- Offload research, exploration, and parallel analysis to subagents
- For complex problems, throw more compute at it via subagents
- One tack per subagent for focused execution

### 3. Self-Improvement Loop
- After ANY correction from the user: update 'tasks/lessons.md' with the pattern
- Write rules for yourself that prevent the same mistake
- Ruthlessly iterate on these lessons until mistake rate drops
- Review lessons at session start for relevant project

### 4. Verification Before Done
- Never mark a task complete without proving it works
- Diff behavior between main and your changes, when relevant
- Ask yourself: "Would a staff engineer approve this?"
- Run tests, check logs, demonstrate correctness

### 5. Demand Elegance (Balanced)
- For non-trivial changes: pause and ask "Is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution"
- Skip this for simple, obvious fixes - don't over-engineer
- Challenge your own work before presenting it

### 6. Autonomous Bug Fixing
- When given a bug report: just fix it. Don't ask for hand-holding
- Point at logs, errors, failing tests - then resolve them
- Zero context switching required from the user
- Go fix failing CI tests without being told how

## Task Management
1. *Plan First*: Write plan to 'tasks/todo.md' with checkable items
2. *Verify Plan*: Check in before starting implementation
3. *Track Progress*: Mark items complete as you go
4. *Explain Changes*: High-level summary at each step
5. *Document Results*: Add review section to 'tasks/todo.md'
6. *Capture Lessons*: Update 'tasks/lessons.md' after corrections

## Core Principles
- *Simplicity First*: Make every change as simple as possible. Impact minimal code.
- *No Laziness*: Find root causes. No temporary fixes. Senior developer standards.
- *Minimal Impact*: Changes should only touch what's necessary. Avoid introducing bugs.