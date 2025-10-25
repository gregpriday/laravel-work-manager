# Documentation Indexing System

This guide explains how to regenerate the documentation index files (`index.jsonl` and `sections.jsonl`) when documentation is added, removed, or significantly changed.

## Overview

The indexing process creates two JSONL files that enable efficient documentation search:

1. **`index.jsonl`** - Quick lookup with terse summaries (one line per file)
2. **`sections.jsonl`** - Detailed breakdown with section navigation (one line per file)

### ⚠️ CRITICAL REQUIREMENT: Perfect Index Synchronization

**THE TWO FILES MUST BE 100% SYNCHRONIZED AT ALL TIMES.**

- **EVERY file in `index.jsonl` MUST have a corresponding entry in `sections.jsonl`**
- **The `index` field MUST match between files** (line N in index.jsonl = line N in sections.jsonl)
- **The `relative_path` field MUST be identical between files**
- **If a file has no H2 sections**, still create an entry in `sections.jsonl` with an empty `sections: []` array
- **NO EXCEPTIONS** - Missing entries break the search system described in `_searching.md`

The search system relies on this perfect 1:1 correspondence to correlate entries between files.

## When to Reindex

Reindex when you:
- Add new documentation files
- Remove documentation files
- Significantly restructure existing documentation (new sections, reorganized content)
- Update major sections that would benefit from new summaries

Minor edits (typo fixes, small clarifications) don't require reindexing.

## Indexing Process

### Step 1: Prepare File List

First, get a list of all markdown files in the docs directory (excluding index files and files starting with `_`):

```bash
find docs -name "*.md" -not -name "_*.md" | sort > /tmp/doc_files.txt
```

Count the files to determine batch sizes:

```bash
wc -l /tmp/doc_files.txt
```

**IMPORTANT**: This file (`/tmp/doc_files.txt`) will be your tracking mechanism. As you process batches, you'll remove completed files from this list. This:
- Prevents duplicate indexing
- Makes it easy to resume if interrupted
- Provides clear progress tracking
- Ensures every file is processed exactly once

### Step 2: Clear Existing Indexes

**IMPORTANT**: Back up existing indexes before clearing:

```bash
cp docs/index.jsonl docs/index.jsonl.backup
cp docs/sections.jsonl docs/sections.jsonl.backup
```

Then clear the index files:

```bash
> docs/index.jsonl
> docs/sections.jsonl
```

### Step 3: Spawn Indexing Agents (Batches of 5)

For each batch of 5 files, spawn parallel agents with the Task tool. Each agent receives:

1. **File path** (relative to `docs/`)
2. **Assigned index number** (sequential, 1-based)
3. **Indexing instructions** (see template below)

#### Batch Processing Workflow

**For each batch:**

1. **Read the next 5 files** from `/tmp/doc_files.txt`:
   ```bash
   head -5 /tmp/doc_files.txt
   ```

2. **Launch 5 agents in parallel** with these files

3. **Wait for all agents to complete**

4. **Verify the batch** was successful:
   ```bash
   # Check that 5 new entries were added to each file
   tail -5 docs/index.jsonl | jq -r '.relative_path'
   tail -5 docs/sections.jsonl | jq -r '.relative_path'
   ```

5. **Remove completed files** from the tracking list:
   ```bash
   # Remove the first 5 lines (the files you just processed)
   tail -n +6 /tmp/doc_files.txt > /tmp/doc_files.tmp
   mv /tmp/doc_files.tmp /tmp/doc_files.txt
   ```

6. **Check remaining files**:
   ```bash
   echo "Remaining files: $(wc -l < /tmp/doc_files.txt)"
   ```

7. **Continue to next batch** until `/tmp/doc_files.txt` is empty

**Example batch invocation** (indices 1-5):

```
# Read next 5 files
$ head -5 /tmp/doc_files.txt
docs/concepts/state-management.md
docs/examples/city-tier-generation.md
docs/examples/content-fact-check.md
docs/examples/customer-research-partial.md
docs/examples/database-record-insert.md

# Launch 5 agents in parallel:
- Agent 1: Index "concepts/state-management.md" as index 1
- Agent 2: Index "examples/city-tier-generation.md" as index 2
- Agent 3: Index "examples/content-fact-check.md" as index 3
- Agent 4: Index "examples/customer-research-partial.md" as index 4
- Agent 5: Index "examples/database-record-insert.md" as index 5

# After completion, remove them from tracking
$ tail -n +6 /tmp/doc_files.txt > /tmp/doc_files.tmp && mv /tmp/doc_files.tmp /tmp/doc_files.txt
$ echo "Remaining: $(wc -l < /tmp/doc_files.txt)"
Remaining: 52
```

Continue with batches until `/tmp/doc_files.txt` is empty:
- Batch 2: indices 6-10 (52 files remaining)
- Batch 3: indices 11-15 (47 files remaining)
- ...
- Final batch: last 1-5 files (0 files remaining)

### Step 4: Agent Instructions Template

Each agent receives these instructions:

```markdown
# Indexing Task for Documentation File

You are indexing a documentation file for efficient search. You will create TWO entries:

1. A quick lookup entry for `index.jsonl`
2. A detailed breakdown entry for `sections.jsonl`

## Your Assignment

- **File**: docs/{relative_path}
- **Index Number**: {N}

## Instructions

### Part 1: Read the File

Read the entire file: `docs/{relative_path}`

### Part 2: Create Quick Lookup Entry

Analyze the file and create a **terse, information-dense summary** (1-2 sentences, ~150-250 chars).

The summary should:
- Capture the PRIMARY purpose and key topics
- Use keywords that users would search for
- Be concise but specific
- Avoid generic phrases like "this document explains"

**Format** (append to `docs/index.jsonl`):

```json
{"index": {N}, "relative_path": "{relative_path}", "summary": "Your terse summary here"}
```

### Part 3: Create Detailed Breakdown Entry

Create a detailed breakdown with:

1. **detailed_summary**: 2-4 sentences explaining what the file covers, its use case, and target audience
2. **sections**: Array of H2 (`##`) headers with:
   - `heading`: The H2 header text (without the `##`)
   - `offset`: Line number where this section starts (1-indexed)
   - `limit`: Number of lines from offset to the next H2 header (or end of file)
   - `summary`: Information-dense summary of this section (1-2 sentences)

**Important**:
- Only include **H2 headers** (`##`), NOT H1 (`#`) or H3 (`###`)
- `offset` is **1-indexed** (first line of file = 1)
- `limit` = number of lines from offset to next H2 (or EOF)
- Section summaries should be specific: what does THIS section teach/explain/demonstrate?
- **If the file has NO H2 sections**, use an empty array: `"sections": []`
- **CRITICAL**: You MUST create an entry in `sections.jsonl` even if there are no H2 sections

**Format** (append to `docs/sections.jsonl`):

```json
{
  "index": {N},
  "relative_path": "{relative_path}",
  "detailed_summary": "Your detailed summary here",
  "sections": [
    {
      "heading": "Section Title",
      "offset": 42,
      "limit": 58,
      "summary": "Specific summary of this section"
    },
    ...
  ]
}
```

### Part 4: Append to Files

Use the Bash tool with `>>` to append each entry:

```bash
echo '{"index": {N}, "relative_path": "{relative_path}", "summary": "..."}' >> docs/index.jsonl
```

```bash
echo '{complex json with sections}' >> docs/sections.jsonl
```

**CRITICAL**: Ensure JSON is valid and compact (no newlines within the JSON object). Each entry must be a single line.

## Quality Checklist

Before submitting:
- [ ] Summary is terse and keyword-rich
- [ ] Detailed summary explains purpose and use case
- [ ] All H2 sections are included (and ONLY H2, not H1/H3)
- [ ] `offset` values are accurate (1-indexed line numbers)
- [ ] `limit` values correctly span to next H2 or EOF
- [ ] Section summaries are specific and informative
- [ ] JSON is valid and compact (single line per entry)
- [ ] **CRITICAL**: Entries are appended to BOTH files (index.jsonl AND sections.jsonl)
- [ ] **CRITICAL**: Both entries use the SAME index number and relative_path

## Example Output

For a file with 100 lines and two H2 sections:

**index.jsonl entry**:
```json
{"index": 7, "relative_path": "guides/example.md", "summary": "Comprehensive guide to building custom order types with validation hooks, lifecycle patterns, and testing strategies."}
```

**sections.jsonl entry**:
```json
{"index": 7, "relative_path": "guides/example.md", "detailed_summary": "This guide teaches developers how to create custom order types by extending AbstractOrderType. It covers required methods, optional validation hooks, lifecycle patterns, and testing approaches. Target audience: Laravel developers building AI-agent workflows.", "sections": [{"heading": "Getting Started", "offset": 10, "limit": 35, "summary": "Installation, basic setup, and minimal viable order type implementation."}, {"heading": "Advanced Patterns", "offset": 45, "limit": 55, "summary": "Complex validation rules, custom acceptance policies, and multi-step workflows."}]}
```
```

### Step 5: Monitor Progress

The tracking file makes progress monitoring easy:

```bash
# Check how many files remain
wc -l /tmp/doc_files.txt

# See what files are left
cat /tmp/doc_files.txt

# Calculate progress
TOTAL=57  # Original file count
REMAINING=$(wc -l < /tmp/doc_files.txt)
COMPLETED=$((TOTAL - REMAINING))
echo "Progress: $COMPLETED/$TOTAL files indexed ($((COMPLETED * 100 / TOTAL))%)"
```

After each batch completes:
1. Wait for all 5 agents to complete
2. Verify entries were appended to both files
3. Check for JSON validity: `jq empty docs/index.jsonl && jq empty docs/sections.jsonl`
4. Remove completed files from tracking list
5. Continue to next batch

**If interrupted**: Simply resume by reading from `/tmp/doc_files.txt` - it contains only the files that haven't been indexed yet.

### Step 6: Sort and Deduplicate

After all batches complete, sort both files by relative_path and renumber:

```python
#!/usr/bin/env python3
import json

# Sort and deduplicate index.jsonl
seen = set()
entries = []

with open('docs/index.jsonl', 'r') as f:
    for line in f:
        entry = json.loads(line.strip())
        if entry['relative_path'] not in seen:
            seen.add(entry['relative_path'])
            entries.append(entry)

entries.sort(key=lambda x: x['relative_path'])

for i, entry in enumerate(entries, 1):
    entry['index'] = i

with open('docs/index.jsonl', 'w') as f:
    for entry in entries:
        f.write(json.dumps(entry) + '\n')

print(f"index.jsonl: {len(entries)} entries")

# Sort and deduplicate sections.jsonl
seen = set()
entries = []

with open('docs/sections.jsonl', 'r') as f:
    for line in f:
        entry = json.loads(line.strip())
        if entry['relative_path'] not in seen:
            seen.add(entry['relative_path'])
            entries.append(entry)

entries.sort(key=lambda x: x['relative_path'])

for i, entry in enumerate(entries, 1):
    entry['index'] = i

with open('docs/sections.jsonl', 'w') as f:
    for entry in entries:
        f.write(json.dumps(entry) + '\n')

print(f"sections.jsonl: {len(entries)} entries")
```

Save as `sort_indexes.py` and run:

```bash
python3 sort_indexes.py
```

### Step 7: Verify Final Output

```bash
# Check counts (MUST BE EQUAL!)
echo "Index entries: $(wc -l < docs/index.jsonl)"
echo "Section entries: $(wc -l < docs/sections.jsonl)"

# CRITICAL: Verify counts are identical
INDEX_COUNT=$(wc -l < docs/index.jsonl)
SECTION_COUNT=$(wc -l < docs/sections.jsonl)
if [ "$INDEX_COUNT" != "$SECTION_COUNT" ]; then
  echo "❌ ERROR: File counts don't match! Index: $INDEX_COUNT, Sections: $SECTION_COUNT"
  echo "The files MUST have the same number of entries."
  exit 1
fi

# Check for duplicates
echo "Duplicates in index: $(jq -r '.relative_path' docs/index.jsonl | sort | uniq -d | wc -l)"
echo "Duplicates in sections: $(jq -r '.relative_path' docs/sections.jsonl | sort | uniq -d | wc -l)"

# Validate JSON
jq empty docs/index.jsonl && echo "✓ index.jsonl: valid JSON"
jq empty docs/sections.jsonl && echo "✓ sections.jsonl: valid JSON"

# CRITICAL: Verify index synchronization
python3 << 'VERIFY_EOF'
import json

print("\n=== Verifying Index Synchronization ===\n")

index_data = []
section_data = []

with open('docs/index.jsonl', 'r') as f:
    for line in f:
        if line.strip():
            index_data.append(json.loads(line.strip()))

with open('docs/sections.jsonl', 'r') as f:
    for line in f:
        if line.strip():
            section_data.append(json.loads(line.strip()))

mismatches = 0
for i, (idx_entry, sec_entry) in enumerate(zip(index_data, section_data), 1):
    if idx_entry['index'] != sec_entry['index']:
        print(f"❌ MISMATCH at line {i}: index {idx_entry['index']} != {sec_entry['index']}")
        mismatches += 1
    if idx_entry['relative_path'] != sec_entry['relative_path']:
        print(f"❌ MISMATCH at line {i}: '{idx_entry['relative_path']}' != '{sec_entry['relative_path']}'")
        mismatches += 1

if mismatches == 0:
    print(f"✅ All {len(index_data)} entries perfectly synchronized!")
else:
    print(f"\n❌ Found {mismatches} errors - FILES ARE NOT SYNCHRONIZED!")
    exit(1)
VERIFY_EOF

# Show sample
echo -e "\n=== First 3 entries from index.jsonl ==="
head -3 docs/index.jsonl | jq '.'

echo -e "\n=== First entry from sections.jsonl ==="
head -1 docs/sections.jsonl | jq '.'
```

**Expected output**:
- ✅ **SAME number of entries in both files** (CRITICAL!)
- ✅ Zero duplicates
- ✅ Valid JSON in both files
- ✅ Alphabetically sorted by relative_path
- ✅ Sequential index numbers (1, 2, 3, ...)
- ✅ **No synchronization mismatches** (CRITICAL!)

**If verification fails**, DO NOT proceed. Fix the indexing errors before using the files.

## Batch Processing Example

For 57 documentation files:

**Initial setup**:
```bash
# Create tracking file
find docs -name "*.md" -not -name "_*.md" | sort > /tmp/doc_files.txt

# Verify count
$ wc -l /tmp/doc_files.txt
57 /tmp/doc_files.txt

# Clear indexes
> docs/index.jsonl
> docs/sections.jsonl
```

**Batch 1** (indices 1-5):
```bash
# Check next files
$ head -5 /tmp/doc_files.txt
docs/README.md
docs/concepts/architecture-overview.md
docs/concepts/configuration-model.md
docs/concepts/lifecycle-and-flow.md
docs/concepts/security-and-permissions.md

# Launch agents for these 5 files (indices 1-5)
# ... agents complete ...

# Verify
$ tail -5 docs/index.jsonl | jq -r '.relative_path'
README.md
concepts/architecture-overview.md
concepts/configuration-model.md
concepts/lifecycle-and-flow.md
concepts/security-and-permissions.md

# Remove from tracking
$ tail -n +6 /tmp/doc_files.txt > /tmp/doc_files.tmp && mv /tmp/doc_files.tmp /tmp/doc_files.txt
$ wc -l /tmp/doc_files.txt
52 /tmp/doc_files.txt  # ✓ 5 files removed
```

**Batch 2** (indices 6-10):
```bash
$ head -5 /tmp/doc_files.txt
docs/concepts/state-management.md
docs/concepts/what-it-does.md
docs/dev/setup.md
docs/examples/basic-usage.md
docs/examples/city-tier-generation.md

# Launch agents, verify, remove
$ tail -n +6 /tmp/doc_files.txt > /tmp/doc_files.tmp && mv /tmp/doc_files.tmp /tmp/doc_files.txt
$ wc -l /tmp/doc_files.txt
47 /tmp/doc_files.txt
```

**Continue until tracking file is empty**:
```bash
$ wc -l /tmp/doc_files.txt
0 /tmp/doc_files.txt  # ✓ All files processed
```

## Summary Quality Guidelines

### Quick Summary (`index.jsonl`)

**Good**:
- "System requirements specification covering PHP 8.2+, Laravel 11+, database (MySQL/PostgreSQL/SQLite), optional Redis, development/production environment setup, agent requirements, networking, and resource estimates for different deployment scales."

**Bad**:
- "This document explains the requirements." ❌ Too generic
- "You need PHP and Laravel to run this package." ❌ Too vague

### Detailed Summary (`sections.jsonl`)

**Good**:
- "This requirements document provides comprehensive system specifications for Laravel Work Manager deployment. It covers server requirements (PHP 8.2+ with extensions, Laravel 11+, MySQL/PostgreSQL/SQLite databases with specific features), optional Redis for lease backend and caching, development environment setup recommendations, production deployment requirements including application servers and web servers, queue workers, agent requirements for both local and remote MCP integrations, networking configuration, and resource estimates for small/medium/large deployments with specific CPU, RAM, and storage recommendations."

**Bad**:
- "This page talks about requirements for the system." ❌ Too vague
- "Requirements document." ❌ No detail

### Section Summary

**Good**:
- "PHP 8.2+ requirements with required/optional extensions, Laravel 11+ version support, database compatibility (MySQL 8.0+, PostgreSQL 13+, SQLite for testing) with JSON columns, UUIDs, and row-level locking, plus database configuration recommendations."

**Bad**:
- "This section covers server requirements." ❌ No specifics
- "PHP and Laravel versions." ❌ Too terse

## Troubleshooting

### JSON Parse Errors

If you get JSON parse errors after indexing:

```bash
# Find invalid lines
jq empty docs/index.jsonl 2>&1 | grep "parse error"
```

Fix by re-running the problematic agent with correct JSON escaping.

### Index Synchronization Errors

If the two files have different counts or mismatched indexes:

```bash
# Find files in index.jsonl but not in sections.jsonl
comm -23 <(jq -r '.relative_path' docs/index.jsonl | sort) \
         <(jq -r '.relative_path' docs/sections.jsonl | sort)

# Find files in sections.jsonl but not in index.jsonl
comm -13 <(jq -r '.relative_path' docs/index.jsonl | sort) \
         <(jq -r '.relative_path' docs/sections.jsonl | sort)
```

**Fix**: Re-index the missing files, ensuring BOTH entries are created.

### Files with No H2 Sections

Files without H2 headers should still have an entry in `sections.jsonl`:

```bash
# Check which files have no sections
jq 'select(.sections | length == 0) | .relative_path' docs/sections.jsonl
```

This is OK - files without H2 headers should have `"sections": []`. The important thing is that the entry EXISTS in both files.

### Offset/Limit Errors

If offset/limit values seem wrong:

1. Verify line numbers match Read tool output (1-indexed)
2. Check that limit spans exactly to next H2 (or EOF)
3. Re-read the file and recalculate

Use this formula:
- `offset` = line number of H2 header
- `limit` = (next H2 line number) - offset, OR (file end line) - offset

## Files to Exclude

Do NOT index these files:
- `_searching.md` (internal documentation)
- `_indexing.md` (this file)
- Any other files starting with `_`
- Backup files (`.backup`, `.tmp`, etc.)

The `find` command in Step 1 handles this automatically with `-not -name "_*.md"`.

## Notes

- **Tracking file**: `/tmp/doc_files.txt` is your single source of truth for what remains to be indexed
  - Prevents duplicate processing
  - Makes resumption after interruption trivial
  - Provides clear progress tracking
  - Remove files from it only AFTER successful batch completion
- **Parallel execution**: Batches of 5 allow good parallelism without overwhelming the system
- **Sequential indexing**: Initial index numbers don't matter; final sort/renumber handles it
- **1-based indexing**: All offsets and index numbers are 1-based (first line = 1)
- **Single-line JSON**: Each JSONL entry must be a single line (no pretty-printing)
- **Alphabetical order**: Final sorted output is alphabetical by relative_path for stability

## Estimated Time

- Small docs (10-20 files): ~10-15 minutes
- Medium docs (20-50 files): ~20-30 minutes
- Large docs (50-100 files): ~40-60 minutes
- Very large docs (100-200 files): ~1.5-2 hours

Most time is spent in agent analysis and summary writing. The sorting step is nearly instantaneous.
