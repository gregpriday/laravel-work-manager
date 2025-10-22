# Partial Submissions Guide

**By the end of this guide, you'll be able to:** Use partial submissions for incremental work, implement partialRules() and assemble(), and decide when partial submissions are appropriate.

---

## When to Use Partial Submissions

Use partial submissions when:
- Work is complex and takes significant time
- Results can be validated incrementally
- You want early feedback on work quality
- Work might be interrupted and need to resume
- Different parts can be submitted in any order

**Examples**:
- Research tasks (company research with multiple data facets)
- Large data collection (contacts, locations, products)
- Multi-step processes (analyze → verify → enrich)

**Don't use** for simple, atomic work that completes quickly.

---

## Configuration

Enable in `config/work-manager.php`:

```php
'partials' => [
    'enabled' => true,
    'max_parts_per_item' => 100,
    'max_payload_bytes' => 1048576,  // 1MB
],
```

---

## Implementing Partial Support

### Step 1: Define Required Parts

```php
public function requiredParts(WorkItem $item): array
{
    return ['identity', 'firmographics', 'contacts'];
}
```

### Step 2: Validation Rules Per Part

```php
public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
{
    return match ($partKey) {
        'identity' => [
            'name' => 'required|string',
            'domain' => 'required|url',
            'confidence' => 'required|numeric|min:0|max:1',
        ],
        'firmographics' => [
            'employees' => 'nullable|integer|min:1',
            'revenue' => 'nullable|numeric',
        ],
        'contacts' => [
            'contacts' => 'required|array|min:1',
            'contacts.*.email' => 'required|email',
        ],
        default => [],
    };
}
```

### Step 3: Custom Part Validation

```php
protected function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void
{
    if ($partKey === 'identity' && $payload['confidence'] < 0.7) {
        throw ValidationException::withMessages([
            'confidence' => ['Identity requires confidence >= 0.7'],
        ]);
    }
}
```

### Step 4: Assemble Parts

```php
public function assemble(WorkItem $item, Collection $latestParts): array
{
    $result = [];
    
    foreach ($latestParts as $part) {
        $result[$part->part_key] = $part->payload;
    }
    
    return $result;
}
```

### Step 5: Validate Assembled Result

```php
protected function validateAssembled(WorkItem $item, array $assembled): void
{
    if (empty($assembled['identity']) || empty($assembled['contacts'])) {
        throw ValidationException::withMessages([
            'assembled' => ['Must include identity and contacts'],
        ]);
    }
}
```

---

## Agent Workflow

### Submit Parts Incrementally

```bash
# Part 1: Identity
curl -X POST /api/ai/work/items/{item-id}/submit-part \
  -H "X-Idempotency-Key: part-identity-1" \
  -d '{
    "part_key": "identity",
    "seq": 1,
    "payload": {
      "name": "Acme Corp",
      "domain": "acme.com",
      "confidence": 0.95
    }
  }'

# Part 2: Firmographics
curl -X POST /api/ai/work/items/{item-id}/submit-part \
  -H "X-Idempotency-Key: part-firmographics-1" \
  -d '{
    "part_key": "firmographics",
    "seq": 1,
    "payload": {
      "employees": 500,
      "revenue": 10000000
    }
  }'

# Part 3: Contacts
curl -X POST /api/ai/work/items/{item-id}/submit-part \
  -H "X-Idempotency-Key: part-contacts-1" \
  -d '{
    "part_key": "contacts",
    "seq": 1,
    "payload": {
      "contacts": [
        {"name": "John Doe", "email": "john@acme.com"}
      ]
    }
  }'
```

### Finalize

```bash
curl -X POST /api/ai/work/items/{item-id}/finalize \
  -H "X-Idempotency-Key: finalize-{item-id}-1" \
  -d '{"mode": "strict"}'
```

---

## Complete Example

See [examples/CustomerResearchPartialType.php](../../examples/CustomerResearchPartialType.php) for a full implementation.

---

## See Also

- [Creating Order Types Guide](creating-order-types.md)
- [Validation Guide](validation-and-acceptance-policies.md)
- [examples/CustomerResearchPartialType.php](../../examples/CustomerResearchPartialType.php)
