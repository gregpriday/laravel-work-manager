# Documentation Search System

This directory contains two JSONL index files that enable efficient documentation search and navigation.

## Index Files

### `index.jsonl` - Quick Lookup
Fast file identification with terse summaries. Each line is a JSON object:

```json
{
  "index": 1,
  "relative_path": "index.md",
  "summary": "Comprehensive documentation navigation hub..."
}
```

### `sections.jsonl` - Detailed Breakdown
Complete file structure with section navigation. Each line is a JSON object:

```json
{
  "index": 1,
  "relative_path": "index.md",
  "detailed_summary": "Laravel Work Manager documentation index serves...",
  "sections": [
    {
      "heading": "Quick Links",
      "offset": 7,
      "limit": 60,
      "summary": "Organized navigation links to all major..."
    }
  ]
}
```

## Search Workflow for Claude

### Step 1: Load Complete Index

Read the entire `index.jsonl` into context (only 58 lines):

```
Read docs/index.jsonl
```

This loads all file summaries for reasoning.

### Step 2: Reason About Candidates

**Use your reasoning** to identify 3-4 most relevant files based on:
- Query keywords and intent
- Summary content
- File paths (e.g., `getting-started/` vs `reference/`)

Example reasoning:
> "User asks about validation. I see indexes 6, 24, and 40 mention validation.
> Index 6 (guides/validation-and-acceptance-policies.md) seems most comprehensive.
> Index 24 (guides/creating-order-types.md) might have validation hooks.
> Let me check both."

### Step 3: Get Section Details for Candidates

Read specific entries from `sections.jsonl` using offset/limit:

```
Read docs/sections.jsonl with offset: 6, limit: 1
Read docs/sections.jsonl with offset: 24, limit: 1
```

Each returns detailed file breakdown with sections.

### Step 4: Analyze Section Breakdowns

Review the `sections` array to identify which specific sections contain relevant information:

```json
{
  "sections": [
    {
      "heading": "Phase 1: Submission Validation",
      "offset": 18,
      "limit": 111,
      "summary": "Covers Laravel validation rules..."
    },
    {
      "heading": "Testing Validation",
      "offset": 484,
      "limit": 58,
      "summary": "Demonstrates PHPUnit test patterns..."
    }
  ]
}
```

### Step 5: Read Targeted Sections

Use section `offset` and `limit` to read only relevant content:

```
Read docs/guides/validation-and-acceptance-policies.md with offset: 18, limit: 111
```

## Complete Example

**User asks:** "How do I validate agent submissions?"

**Step 1 - Load index:**
```
Read docs/index.jsonl
```
Returns all 58 entries.

**Step 2 - Reasoning:**
> "I see index 6 'guides/validation-and-acceptance-policies.md' has 'two-phase validation system'.
> Index 24 'guides/creating-order-types.md' mentions 'validation/lifecycle hooks'.
> Index 40 is a duplicate of 6 (same file).
> I'll examine indexes 6 and 24."

**Step 3 - Get section details:**
```
Read docs/sections.jsonl with offset: 6, limit: 1
Read docs/sections.jsonl with offset: 24, limit: 1
```

**Step 4 - Analyze sections:**
> "Index 6 has 'Phase 1: Submission Validation' section at offset 18, limit 111.
> Index 24 has 'Optional Methods (Validation)' at offset 177, limit 76.
> Both look relevant - I'll read both sections."

**Step 5 - Read targeted sections:**
```
Read docs/guides/validation-and-acceptance-policies.md with offset: 18, limit: 111
Read docs/guides/creating-order-types.md with offset: 177, limit: 76
```

**Step 6 - Answer user based on targeted content.**

## Key Details

### Indexing
- Both files use **1-based indexing** (first line = 1)
- `offset` values are **1-indexed** (first line of file = 1)
- This matches Claude's Read tool exactly

### Paths
- All paths are **relative to `docs/` directory**
- To read a file: `docs/<relative_path>`
- Example: `docs/guides/validation-and-acceptance-policies.md`

### Section Extraction
- Only **H2 headers** (`##`) are included in sections
- H1 (`#`) and H3 (`###`) headers are ignored
- `limit` = number of lines from `offset` to next H2 or end of file

### Cross-Reference
- The `index` field is the same in both files
- Use it to correlate entries: `index.jsonl` line N → `sections.jsonl` line N

## Reasoning-Based Search Strategy

The key is **loading the full index and using reasoning**, not grep/keyword matching:

### Why Reasoning Over Keywords

- **Context understanding**: "How do I set up MCP?" → Reason that `getting-started/installation.md` and `guides/mcp-server-integration.md` are relevant, not just keyword "MCP"
- **Intent detection**: "What happens when work fails?" → Reason about state management, error handling, and dead-lettering
- **Multi-file synthesis**: "Production deployment" → Reason across deployment guide, configuration, environment variables, and requirements

### Recommended Approach

1. **Always load `index.jsonl` first** (58 lines, ~15KB - negligible in 200K token context)
2. **Scan with human-like reasoning** instead of regex
3. **Select 3-4 most promising candidates** based on query intent
4. **Read section details** for those candidates only
5. **Make final section selection** based on detailed summaries
6. **Read targeted sections** from actual files

### Example Reasoning Patterns

**Query**: "How do I deploy to production?"

Reasoning:
- `guides/deployment-and-production.md` (index 26) - Primary guide
- `guides/environment-variables.md` (index 4) - Production config
- `getting-started/requirements.md` (index 2) - Server requirements
- `guides/configuration.md` (index 22) - Config best practices

**Query**: "What events are fired?"

Reasoning:
- `guides/events-and-listeners.md` (index 8) - Event catalog
- `reference/events-reference.md` (index 52) - Complete reference
- Skip `concepts/lifecycle-and-flow.md` unless user asks about when events fire

**Query**: "Getting started tutorial"

Reasoning:
- `getting-started/quickstart.md` (index 20) - 5-minute tutorial
- `getting-started/introduction.md` (index 19) - Overview first
- `examples/basic-usage.md` (index 10) - Simple example

## Performance Tips

1. **Load `index.jsonl` once** - Keep it in context for the entire conversation
2. **Be selective with `sections.jsonl`** - Only read 3-4 entries per query
3. **Use offset/limit precisely** - Read only the sections you need from actual files
4. **Don't read entire files** - Section-level reading saves tokens and time

## File Formats

Both files are **JSONL** (JSON Lines):
- One JSON object per line
- No array wrapper
- Each line is independently parseable
- Easy to grep, stream, and process line-by-line

## Maintenance

These files are generated from the documentation source files. To regenerate:

1. The first 10 entries were created by AI agents reading each file
2. Remaining entries were generated by a PHP script
3. Files are sorted alphabetically by `relative_path`
4. Indexes are assigned sequentially after sorting

## Use Cases

### For Claude
- **Quick lookup**: "Find files about X"
- **Section navigation**: "Read the validation section"
- **Targeted reading**: Only read relevant sections, not entire files
- **Context management**: Stay within token limits by reading precisely

### For Developers
- **Documentation overview**: See all available docs at a glance
- **Section discovery**: Find specific topics without manual browsing
- **Build tooling**: Generate navigation, search indexes, etc.
- **Quality checks**: Verify documentation coverage

## Integration Example

```javascript
// Load indexes
const index = readLines('docs/index.jsonl').map(JSON.parse);
const sections = readLines('docs/sections.jsonl').map(JSON.parse);

// Search for files
const matches = index.filter(f =>
  f.summary.toLowerCase().includes('validation')
);

// Get detailed info
const details = matches.map(m =>
  sections.find(s => s.index === m.index)
);

// Read specific section
const section = details[0].sections[0];
readFile('docs/' + details[0].relative_path, {
  offset: section.offset,
  limit: section.limit
});
```

## Notes

- **58 documentation files** are indexed
- **Covers all .md files** in the docs directory
- **Alphabetically sorted** for stability
- **Section summaries** are information-dense for semantic search
- **Offset/limit** values are precise for efficient reading
